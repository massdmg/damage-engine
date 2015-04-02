<?php

  // Damage Engine Copyright 2012-2015 Massive Damage, Inc.
  //
  // Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except 
  // in compliance with the License. You may obtain a copy of the License at
  //
  //     http://www.apache.org/licenses/LICENSE-2.0
  //
  // Unless required by applicable law or agreed to in writing, software distributed under the License 
  // is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express 
  // or implied. See the License for the specific language governing permissions and limitations under 
  // the License.

  defined('MEMCACHE_COMPRESSED') or define('MEMCACHE_COMPRESSED',       2);
  defined('MEMCACHE_CURRENT'   ) or define("MEMCACHE_CURRENT"   , 1 << 31);

  Conf::define_term("CACHE_CONNECTION_DEFAULT_CLAIM_TIMEOUT", "the default maximum number of seconds to wait to obtain a claim",  8);
  Conf::define_term("CACHE_CONNECTION_DEFAULT_CLAIM_EXPIRY" , "the default maximum number of seconds to hold a claim"          , 30);


  class CacheConnection extends StatisticsGenerator
  {
    public  $default_max_age      = null;
    public  $default_backing_rate = 100;
    private $cache                = null;


    //
    // Creates a new connection to the cache, with an optional connection to a backing database.

    function __construct( $cache, $statistics_collector = null, $enable_logging = null )
    {
      parent::__construct($statistics_collector);
      
      $this->cache                = $cache;
      $this->default_max_age      = ONE_YEAR;
      $this->default_backing_rate = 100;
      $this->claims               = array();
      $this->fake_claims          = null;
      $this->age_limit            = null;
      $this->prefix               = defined("APPLICATION_NAME") ? APPLICATION_NAME . ":" : "";

      $this->logging_enabled      = $enable_logging;
      $this->track_releases       = false;

      $this->accumulate("cache_misses");
      $this->accumulate("cache_hits"  );

      if( $this->logging_enabled or is_null($this->logging_enabled) && Features::enabled("cache_connection_logging") )
      {
        $this->logging_enabled = true;
        if( Features::enabled("cache_connection_track_claim_release") )
        {
          $this->track_releases = true;
        }
      }
    }
    
    function close()
    {
      if( $this->cache )
      {
        memcache_close($this->cache);
        $this->cache = null;
      }
    }

    function __sleep()
    {
      return array();
    }

    static function canonicalize_time( $value )
    {
      return $value > ONE_YEAR ? $value : (time() - $value - mt_rand(0, 3));
    }

    static function determine_age_limit()
    {
      $age_limit = 0;
      foreach( func_get_args() as $limit )
      {
        if( $limit )
        {
          $limit = static::canonicalize_time($limit);
          $age_limit >= $limit or $age_limit = $limit;
        }
      }

      return $age_limit;
    }


    //
    // Retrieves the named value from the cache, provided it is no older than $max_age (in
    // seconds). The routine may add a few seconds to your $max_age, to help minimize rebuild
    // contention. $max_age 0 very specifically means don't use any cached object (it makes
    // sense for the database-backed routines). If you pass a $max_age > ONE_YEAR, it will be
    // used as an exact time the entry must have been created on or after. For historical reasons,
    // the positive value of any negative $max_age will be used the same way.
    //
    // CacheConnection can only evaluate age on things it put in the cache. For other objects,
    // setting a $max_age will get you a null.
    //
    // Example:
    //   if( $value = $cache->get("somekey") )
    //   {
    //     echo $value;
    //   }
    //

    function get( $key, $max_age = null )
    {
      $value = null;

      if( is_null($max_age) )
      {
        $max_age = $this->default_max_age;
      }
      
      if( $max_age )
      {
        $this->increment("cache_attempts");

        $age_limit = static::determine_age_limit($this->age_limit, abs($max_age));
        debug("CACHE GET: $key with age limit $age_limit");

        $start = microtime(true);
        $entry = false;

        if( Features::enabled("cache_monitor_memory_use") )
        {
          $memory_before = memory_get_usage();
        }

        try
        {
          $entry = $this->cache->get($this->prefix . $key);
        }
        catch( GameException $e )
        {
          // likely class loading failed; discard the data and ignore it
        }

        $this->accumulate("cache_wait_time", microtime(true) - $start);
        
        if( $entry !== false )
        {
          if( is_a($entry, "CacheEntry") )
          {
            $valid = ($entry->created >= $age_limit);
            if( $valid )
            {
              debug("CACHE GOT: $key");
              $this->increment("cache_hits");
              $value = $entry->value;
            }
          }
          elseif( ($max_age == ONE_YEAR && is_null($this->age_limit)) )
          {
            debug("CACHE GOT: $key");
            $this->increment("cache_hits"       );
            $this->increment("cache_legacy_hits");
            $value = $entry;
          }
        }
      }

      if( is_null($value) )
      {
        $this->increment("cache_misses");
      }
      elseif( Features::enabled("cache_monitor_memory_use") )
      {
        $memory_after = memory_get_usage();
        $memory_delta = $memory_after - $memory_before;
        $memory_sign  = $memory_delta < 0 ? "" : "+";
        debug("MEMORY USE: $key: $memory_after ({$memory_sign}$memory_delta)");
      }
      
      return $value;
    }


    //
    // Stores a value into the underlying cache. The object will stay in the cache for some
    // reasonably long period, unless the cache needs the space back.
    //
    // For sanity reasons, set() will refuse to overwrite an existing cache object unless
    // you hold a claim() on it. You can disable the sanity check in the $settings array by
    // setting "claim_required" to false, or by using overwrite() instead.
    //
    // If you are setting an object that needs to be read by the old Cache class, be sure
    // to set "legacy" in $settings. For historical reasons, if you pass a boolean for
    // $settings, it is the legacy flag.

    function set( $key, $value, $settings = null )
    {
      strlen($key) < 250 or Script::fail("memcache_key_length_too_long", array("key" => $key, "limit" => 250, "actual" => strlen($key)));

      $settings = $this->parse_settings($settings);
      $expiry   = array_fetch_value($settings, "expiry", 0    );
      $method   = array_fetch_value($settings, "method", "set");
      $entry    = new CacheEntry($key, $value);

      debug("CACHE SET: $key");
      $this->increment("cache_writes");
      
      $start = microtime(true);
      if( !$this->cache->$method($this->prefix . $key, $entry, MEMCACHE_COMPRESSED, (int)$expiry) )
      {
        $this->accumulate("cache_wait_time", microtime(true) - $start);
        trigger_error("Cache $method of $key failed!", E_USER_ERROR);
        return false;
      }
      
      $this->accumulate("cache_wait_time", microtime(true) - $start);

      return true;
    }


    //
    // Equivalent to set() with claim_required = false. Use this only if you feel really safe
    // about not having a claim.

    function overwrite( $key, $value, $settings = null )
    {
      return $this->set($key, $value, $settings);
    }


    //
    // Adds an object to the cache. Returns true unless the object already exists in the cache.
    // Generally, you should pass an expiry time with this routine.

    function add( $key, $value, $expiry = 0, $settings = null )
    {
      $settings = $this->parse_settings($settings);
      $settings["expiry"] = $expiry;
      $settings["method"] = "add";
      
      return $this->set($key, $value, $settings);
    }
    
    
    //
    // Invalidates a stored object, if present in the cache. At the moment, this is the same as
    // delete(), but you should use it for times when clearing out stale data, in order to support
    // future enhancements.

    function invalidate( $key )
    {
      $this->cache->delete($this->prefix . $key, $retrieve = false);
    }


    //
    // Deletes an entry from the cache, if present.

    function delete( $key, $retrieve = false )
    {
      $this->increment("cache_deletes");

      $result = $retrieve ? $this->get($key) : null;
      $this->cache->delete($this->prefix . $key);
      return $result;
    }


    function consume( $key )
    {
      return $this->delete($key, $retrieve = true);
    }


    //
    // Gets the object from the cache or calls your Callback to create it. Stores the
    // created object in the cache for next time. If you set the $max_age to 0, the
    // cache will be bypassed entirely (both get and set).

    function get_or_create( $key, $callback, $max_age = null, $parameters = array() )
    {
      $object = $this->get($key, $max_age);
      if( !$object )
      {
        if( $object = Callback::do_call_with_array($callback, $parameters) )
        {
          if( !($max_age === 0) )
          {
            @$this->set($key, $object, array("claim_required" => false));
          }
        }
      }

      return $object;
    }


    //
    // Returns a list of all keys in the cache. Derived from code found on the PHP website.

    function get_keys( $pattern = null )
    {
      $keys = array();

      foreach( $this->cache->getExtendedStats('slabs') as $server => $slabs )
      {
        foreach( $slabs as $id => $meta )
        {
          foreach( $this->cache->getExtendedStats('cachedump', (int)$id) as $host => $map )
          {
            if( is_array($map) )
            {
              foreach( $map as $key => $info )
              {
                if( !$pattern || preg_match($pattern, $key) )
                {
                  array_push($keys, $key);
                }
              }
            }
          }
        }
      }

      return $keys;
    }
    
    
    function get_stats( $type = "settings" )
    {      
      return empty($type) ? $this->cache->getExtendedStats() : $this->cache->getExtendedStats($type);
    }


    //
    // Deletes all entries from the cache.

    function clear()
    {
      $this->cache->flush();
      sleep(3); // Give it a moment to finish
    }


    function clear_in_process_cache()
    {
      if( is_a($this->cache, "InProcessCache") )
      {
        $this->cache->flush_local();
      }
    }




  // ================================================================================================
  // Coordination

    function claim_and_get( $key, $max_age = null, $block = true, $timeout = 0 )
    {
      if( $this->claim($key, $block, $timeout) )
      {
        return $this->get($key, $max_age);
      }

      return null;
    }

    //
    // Attempts to claim the specified key. Any other cache user that attempts to claim the same
    // key will fail or block until you release() the key. Claimed keys are automatically released
    // for you (eventually), but you should probably release() them explicitly, to minimize
    // disruption to and failures for other users. You can safely nest claim()s for the same key,
    // as long as you release() the same number of times.
    //
    // NOTE: $timeout is in seconds.

    function claim( $key, $block = true, $timeout = 0, $expiry = null )
    {
      $key = $this->key_for($key);

      //
      // Bypass the hard work if we have already claimed the key.

      if( array_fetch_value($this->claims, $key, 0) > 0 )
      {
        $this->claims[$key] += 1;
        return $this->claims[$key];                        //<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<
      }


      //
      // If we are still here, we're doing it the hard way.

      $timeout or $timeout = Conf::get("CACHE_CONNECTION_DEFAULT_CLAIM_TIMEOUT", 8);
      is_null($expiry) and $expiry = max($timeout * 2, Conf::get("CACHE_CONNECTION_DEFAULT_CLAIM_EXPIRY", 30));     // This used to be calculated off max_execution_time, but random values are dangerous. This is a reasonable limit.

      $cache_key = "$key::claim";
      $start     = microtime(true);
      $claimed   = false;
      $pid       = Script::get_id();
      $min_wait  = ceil( 30000 / max($timeout, 1));   // microseconds -- we divide by timeout on the assumption that a longer timeout means more important that it not fail
      $max_wait  = ceil(300000 / max($timeout, 1));   // microseconds

      //
      // This bit of processing can get very complex to debug, as it is hard to duplicate the
      // problems in a controlled environment. So, we'll take extra steps to track who is
      // blocking us, so that if we fail, we can provide useful logging.

      $blocker_log     = array();
      $blocker_summary = array();

      do
      {
        $attempt = microtime(true);
        $claimed = $this->cache->add($this->prefix . $cache_key, new CacheEntry($cache_key, $pid), MEMCACHE_COMPRESSED, $expiry);

        if( !$claimed && $this->logging_enabled )
        {
          $flags   = MEMCACHE_CURRENT;
          $blocker = $this->cache->get($this->prefix . $cache_key, $flags);
          $record  = new BlockedClaimRecord($attempt, $blocker ? $blocker->value : "removed");

          $blocker_log[] = $record;
          @$blocker_summary[$record->by] += 1;
        }
      }
      while( !$claimed && $block && microtime(true) - $start < $timeout && is_null(usleep(mt_rand($min_wait, $max_wait))) );

      $completed = microtime(true);
      $wait_time = $completed - $start;

      $this->accumulate("cache_claim_time", $wait_time);
      $this->log_claim($key, $completed, $claimed, $wait_time, $blocker_log, $blocker_summary);

      if( $claimed )
      {
        debug("CACHE CLAIMED: $cache_key");
        $this->claims[$key] = 1;
        return 1;
      }

      debug("CACHE CLAIM FAILED: $cache_key");
      return 0;
    }


    //
    // Releases the claimed key. You really, really shouldn't call this unless you called claim()
    // and it returned true first.

    function release( $key )
    {
      $key       = $this->key_for($key);
      $released  = false;
      $pid       = Script::get_id();

      $this->log_release_track($key, "release() pid $pid");
      if( isset($this->claims[$key]) )
      {
        $this->log_release_track($key, "release() in claims");
        if( $this->claims[$key] > 1 )
        {
          $this->claims[$key] -= 1;
          $released = true;
          $this->log_release_track($key, "release() count decremented");
        }
        elseif( $this->claims[$key] == 1 )
        {
          $this->log_release_track($key, "release() count at 1");

          $flags     = MEMCACHE_CURRENT;
          $cache_key = "$key::claim";
          if( $existing = $this->cache->get($this->prefix . $cache_key, $flags) )
          {
            $this->log_release_track($key, "release() found existing claim from " . @$existing->value);
            if( !is_a($existing, "CacheEntry") || $existing->value == $pid )
            {
              $this->log_release_track($key, "release() deleting claim");
              if( $this->cache->delete($this->prefix . $cache_key) )
              {
                $this->log_release_track($key, "release() deleted claim");
                $this->increment("cache_claims_released");
                $this->log_release($key);
                unset($this->claims[$key]);
                $released = true;
                debug("CACHE CLAIM RELEASED: $key");
              }
            }
          }
        }
      }

      return $released;
    }


    //
    // Releases all claims.

    function release_all()
    {
      $keys = array_keys($this->claims);
      $this->accumulate("cache_claims_outstanding", count($keys));

      foreach( $keys as $key )
      {
        if( $this->claims[$key] > 0 )
        {
          $this->claims[$key] = 1;
        }

        $this->log_release_track($key, "release_all()");
        $this->release($key);
      }
    }


    //
    // For debug purposes only, prevents claimed from being released.
    
    function release_none()
    {
      $this->claims = array();  
    }


    //
    // Returns true if you hold a claim on the specified key.

    function has_claimed( $key )
    {
      return array_fetch_value($this->claims, $key, 0) > 0 || ($this->fake_claims && array_fetch_value($this->fake_claims, $key, 0) > 0);
    }


    //
    // Attempts to claim several keys, all within a single overall time limit. Returns a map
    // of claim key to claim count if all claims were required. Any acquired keys will be
    // released if the routine fails.

    function claim_several( $keys, $block = true, $overall_timeout = 0 )
    {
      $count     = ($overall_timeout > 0);    // We don't use $overall_timeout if it less than one second
      $remaining = $overall_timeout;          // Never used unless $overall_timeout is positive
      $start     = microtime(true);

      //
      // Attempt the claims in a consistent order, so there's less chance of a deadlock

      sort($keys);
      $claimed = array();
      foreach( $keys as $key )
      {
        $claims = $this->claim($key, $block, $remaining);
        if( $claims < 1 )
        {
          break;
        }

        $claimed[$key] = $claims;

        if( $count )
        {
          $elapsed   = microtime(true) - $start;
          $remaining = $overall_timeout - $elapsed;
          
          if( $remaining <= 0 )
          {
            break;
          }
        }
      }

      //
      // This is an all or nothing operation. Clean up if we failed.

      if( count($claimed) < count($keys) )
      {
        foreach( array_reverse(array_keys($claimed)) as $key )
        {
          $this->release($key);
        }

        return false;
      }

      return $claimed;
    }


    //
    // For testing purposes, ensures you can set() keys without having actually claimed them.

    function fake_claim( $key )
    {
      is_array($this->fake_claims) or $this->fake_claims = array();
      $this->fake_claims[$key] = true;
      return true;
    }


    //
    // For testing purposes, undoes an earlier fake_claim() on $key.

    function undo_fake_claim( $key )
    {
      is_array($this->fake_claims) or $this->fake_claims = array();
      unset($this->fake_claims[$key]);
      return true;
    }




  // ================================================================================================
  // Private stuff

    //
    // Logs claim data to the database and updates the claim_keys collection.

    protected function log_claim( $key, $completed, $acquired, $wait_time, $blocker_log, $blocker_summary )
    {
      if( $this->logging_enabled )
      {
        Script::signal("claim_acquired", $key, $completed, $acquired, $wait_time, $blocker_log, $blocker_summary);
      }
    }


    protected function log_release( $key )
    {
      if( $this->logging_enabled )
      {
        Script::signal("claim_released", $key);
      }
    }


    protected function log_release_track( $key, $stage )
    {
      if( $this->track_releases )
      {
        Script::signal("claim_release_in_progress", $key, $stage);
      }
    }


    //
    // Attempts to returns a valid key for $thing.

    private function key_for( $thing, $id = null )
    {
      if( !empty($id) )
      {
        return sprintf("object:%s:%d", $thing, $id);
      }
      elseif( is_object($thing) )
      {
        if( method_exists($thing, "get_cache_key") )
        {
          return $thing->get_cache_key();
        }
        else
        {
          return sprintf("object:%s:%d", get_class($thing), $object->get_id());
        }
      }
      else
      {
        return $thing;
      }
    }


    //
    // Parses a settings array/string into an array of key/value pairs.

    function parse_settings( $settings )
    {
      if( is_string($settings) )
      {
        $unparsed = $settings;
        $settings = array();
        parse_str($unparsed, $settings);
      }
      elseif( !is_array($settings) )
      {
        $legacy   = ($settings === true);  // Vestigial as of the retirement of 1.2.3
        $settings = array();
      }

      return $settings;
    }


  }



  class BlockedClaimRecord
  {
    function __construct( $at, $by )
    {
      $this->at = $at;
      $this->by = $by;
    }
  }
