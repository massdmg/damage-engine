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

  defined("MEMCACHE_CURRENT") or define("MEMCACHE_CURRENT", 1 << 31);


  //
  // Provides an in-process cache, useful primarily for local-only testing (or if you tend to
  // retrieve the same set of data multiple times in a page). It is build to be interface-
  // compatible with Memcache, for use with CacheConnection.

  class InProcessCache
  {
    function __construct( $backing_cache = null, $entry_limit = 10 )
    {
      $this->backing_cache = $backing_cache;
      $this->store         = array();
      $this->entry_limit   = $entry_limit;
      $this->discard_count = 0;
    }

    function add( $key, $value, $flag = 0, $expires = 0 )
    {
      if( $this->backing_cache )
      {
        if( $this->backing_cache->add($key, $value, $flag, $expires) )
        {
          if( !$expires )
          {
            $this->store($key, $value);
          }
          return true;
        }
      }
      elseif( !isset($this->store[$key]) )
      {
        $this->store($key, $value);
        return true;
      }

      return false;
    }

    function set( $key, $value, $flag = 0, $expires = 0 )
    {
      if( empty($this->backing_cache) || $this->backing_cache->set($key, $value, $flag, $expires) )
      {
        if( !$expires )
        {
          $this->store($key, $value);
        }
        else
        {
          unset($this->store[$key]);
        }
        return true;
      }

      return false;
    }

    function get( $key, &$flags = 0 )
    {
      if( !($flags & MEMCACHE_CURRENT) && isset($this->store[$key]) )
      {
        //
        // To maintain the LRU item at the front of the queue, move this one to the back.

        $this->store($key, $this->store[$key]);
        return $this->store[$key];
      }
      elseif( !empty($this->backing_cache) )
      {
        if( $value = $this->backing_cache->get($key, $flags) )
        {
          $this->store($key, $value);
          return $value;
        }
      }

      return false;
    }

    function delete( $key )
    {
      unset($this->store[$key]);
      if( empty($this->backing_cache) || $this->backing_cache->delete($key) )
      {
        return true;
      }

      return false;
    }


    function getExtendedStats()
    {
      return $this->backing_cache ? call_user_func_array(array($this->backing_cache, "getExtendedStats"), func_get_args()) : array();
    }


    function flush()
    {
      $this->store = array();
      return $this->backing_cache ? $this->backing_cache->flush() : true;
    }


    function flush_local()
    {
      $this->store = array();
    }



    protected function store( $key, &$value )
    {
      //
      // First, remove any existing entry for the $key, so it will be added at the back of the queue.

      unset($this->store[$key]);

      //
      // Then, clear enough room from the front of the queue for the new entry to be added.

      while( count($this->store) >= $this->entry_limit )
      {
        reset($this->store);
        $oldest_key = key($this->store);
        unset($this->store[$oldest_key]);

        $this->discard_count += 1;
      }

      //
      // Occassionally clean up any mess.

      if( $this->discard_count % 14 == 13 )
      {
        gc_collect_cycles();
      }

      //
      // Finally, store the new entry.

      $this->store[$key] =& $value;
    }
  }
