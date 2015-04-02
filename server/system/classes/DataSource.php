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

  //
  // Combines database and cache operations into a single, abortable transaction. Due to the
  // involvement of a cache, some aspects of transaction isolation can't apply. However,
  // everything will be saved or discarded as a unit.

  class DataSource
  {
    public    $db;       // Use with extreme caution
    public    $cache;    // Use with extreme caution
    public    $schema;
    protected $sets;
    protected $claims;

    function __construct( $cache, $db_connector, $for_writing = true, $statistics_collector = null, $on_abort = null )
    {
      $this->cache         = $cache;
      $this->routers       = array();
      $this->routing       = array();
      $this->discarders    = array();
      $this->sets          = array();
      $this->set_settings  = array();
      $this->claims        = array();
      $this->collector     = $statistics_collector;
      $this->reconnector   = $db_connector;
      $this->connector     = Callback::for_method($db_connector, "connect", $statistics_collector, $for_writing);
      $this->db            = new DeferredObject(Callback::for_method($this, "connect_db"));
      $this->schema        = new DeferredObject(Callback::for_method($this, "connect_schema"));
      $this->on_abort      = $on_abort;
      $this->delay_budget  = 13000;        // 13ms; See get_from_cache_of_db for details.
      $this->id            = null;
      $this->on_next_query = array();
      $this->on_last_query = array();
      $this->on_next_query_structure = array();
      $this->on_last_query_structure = array();
    }
    
    
    function __destruct()
    {
      $this->close();
    }
    
    function get_id()
    {
      $this->id or $this->id = spl_object_hash($this);
      return $this->id;
    }
    

    function filter_by( $by )
    {
      $class = sprintf("DataSource%sFilter", convert_snake_to_pascal_case($by));
      return $class::build($this, array_slice(func_get_args(), 1));
    }
    
    
    function unfilter()
    {
      return $this;
    }
    
    
    function reconnect_for_writing( $on_abort = null, $clone = false )
    {
      $clone and !$on_abort and $on_abort = $this->on_abort;
      $ds = new static($this->cache, $this->reconnector, $for_writing = true, $this->collector, $on_abort);
      $clone and $this->copy_state($ds);
      return $ds;
    }
    
    
    function reconnect_for_reading( $clone = false )
    {
      $ds = new static($this->cache, $this->reconnector, $for_writing = false, $this->collector);
      $clone and $this->copy_state($ds);
      return $ds;
    }
    


    function commit()
    {
      debug("COMMITTING DataSource");
      
      Script::signal("data_source_committing", $this);
      $this->connector or $this->db->commit_transaction() or $this->abort();
      $this->commit_to_cache();
      $this->release_claims();
      $this->discarders = array();
      Script::signal("data_source_committed", $this);
      
      debug("COMMITTED DataSource");
      
      return true;
    }


    function discard()
    {
      debug("DISCARDING DataSource");
      
      $this->connector or $this->db->rollback_transaction();
      $this->release_claims();
      $this->sets = array();
      $this->set_settings = array();

      while( $callback = array_pop($this->discarders) )
      {
        $callback->call();
      }

      Script::signal("data_source_discarded", $this);
      
      return true;
    }
    
    function on_discard( $callback )
    {
      $this->discarders[] = $callback;
    }


    function abort( $throw = true )
    {
      $this->discard();
      if( $throw )
      {
        $throw = $this->on_abort;
        if( is_object($throw) )
        {
          if( is_a($throw, "Callback") )
          {
            $throw->call();
          }
          else
          {
            throw $throw;
          }
        }
        elseif( is_string($throw) )
        {
          throw new $throw;
        }
        else
        {
          throw new Exception("aborted");
        }
      }
    }
    
    
    //
    // Allows you to register a callback to be used when selecting a cache to use. This is useful 
    // if you want to have multiple caches for different purposes. Your callback will be passed
    // the current cache selection and the cache key. You must return a cache to use. Note that 
    // later filters get to override earlier ones.
    
    function on_route( $callback )
    {
      $this->routers[] = $callback;
    }


    function close()
    {
      $this->discard();
      $this->connector or $this->db->close();
      $this->cache = null;                      // Don't close, as we didn't open it
    }

    



  //===============================================================================================
  // SECTION: Direct cache acccess.


    function get( $cache_key, $max_age = null, $static = false )
    {
      if( $cache_key )
      {
        if( array_key_exists($cache_key, $this->sets) )
        {
          $value = $this->sets[$cache_key];
          if( !is_null($value) && $value !== 0 )
          {
            return unserialize($value);
          }
        }
        else
        {
          return $this->get_cache($cache_key, $static)->get($cache_key, $max_age);
        }
      }

      return null;
    }

    function set( $cache_key, $value, $settings = null, $static = false )
    {
      if( !empty($cache_key) && !is_null($value) )
      {
        if( $static )
        {
          $this->get_cache($cache_key, $static)->set($cache_key, $value, $settings);
        }
        else
        {
          debug("BUFFERED CACHE SET: $cache_key");
          $this->sets[$cache_key] = serialize($value);
          $this->set_settings[$cache_key] = $settings;
        }
        
        return true;
      }

      return false;
    }
    
    function overwrite( $cache_key, $value, $settings = null, $static = false )
    {
      abort("gone; switch to set");
    }
    
    
    function delete( $cache_key, $static = false )
    {
      if( $static ) 
      {
        unset($this->sets[$cache_key]);
        unset($this->set_settings[$cache_key]);
        
        $this->get_cache($cache_key, $static)->delete($cache_key);
      }
      else
      {
        $this->sets[$cache_key] = null;
        unset($this->set_settings[$cache_key]);
      }
    }
    
    
    function invalidate( $cache_key, $static = false )
    {
      return $this->delete($cache_key, $static);
    }


    function consume( $cache_key, $max_age = null )
    {
      return $this->get_cache($cache_key)->consume($cache_key, $max_age);  // BUG: should this be buffered???
    }
    
    
    function claim( $cache_key, $block = true, $timeout = 0, $expiry = null )
    {
      if( $this->get_cache($cache_key)->claim($cache_key, $block, $timeout, $expiry) )
      {
        @$this->claims[$cache_key] += 1;
      }

      return (int)@$this->claims[$cache_key];
    }
    
    
    function claim_several( $cache_keys, $block = true, $timeout = 0 )
    {
      $cache = $this->get_cache($cache_keys[0]);
      
      if( Features::debugging_enabled() )
      {
        $cache_id = spl_object_hash($cache);
        foreach( array_slice($cache_keys, 1) as $key )
        {
          $requested_cache = $this->get_cache($key);
          $requested_cache_id = spl_object_hash($requested_cache);
          
          $requested_cache_id == $cache_id or throw_internal_exception("claim_several_target_multiple_caches");
        }
      }
      
      if( $claims = $cache->claim_several($cache_keys, $block, $timeout) )
      {
        foreach( $cache_keys as $cache_key )
        {
          @$this->claims[$cache_key] += 1;
        }
      }
      
      return $claims;
    }


    function is_claimed( $cache_key )
    {
      return array_key_exists($cache_key, $this->claims);
    }
    
    
    function get_static( $cache_key, $max_age = null )
    {
      return $this->get($cache_key, $max_age, $static = true);
    }
    
    function set_static( $cache_key, $value, $settings = null )
    {
      return $this->set($cache_key, $value, $settings, $static = true);
    }

    function delete_static( $cache_key )
    {
      return $this->delete($cache_key, $static = true);
    }
    
    


  //===============================================================================================
  // SECTION: Basic database operations.

    //
    // Returns a results set handle. Always bypasses the cache and goes directly to the database.

    function query()
    {
      return $this->call_db("query", func_get_args(), $offset = 0);
    }
    
    function on_next_query_results( $callback )
    {
      if( $this->connector )
      {
        $this->on_next_query[] = $callback;
      }
      else
      {
        $this->on_last_query[] = $callback;
        $this->db->on_next_query_results($callback);
      }
    }
    
    function clear_next_query_results_filters()
    {
      if( $this->connector )
      {
        $this->on_next_query = array();
      }
      else
      {
        $this->db->clear_next_query_results_filters();
      }
      
      $this->on_last_query = array();
    }

    function on_next_query_structure_results( $callback )
    {
      if( $this->connector )
      {
        $this->on_next_query_structure[] = $callback;
      }
      else
      {
        $this->on_last_query_structure[] = $callback;
        $this->db->on_next_query_structure_results($callback);
      }
    }
    
    function clear_next_query_structure_results_filters()
    {
      if( $this->connector )
      {
        $this->on_next_query_structure = array();
      }
      else
      {
        $this->db->clear_next_query_structure_results_filters();
      }
      
      $this->on_last_query_structure = array();
    }


    //
    // function query_all(       $cache_key, [$max_age, ] . . . )
    // function query_first(     $cache_key, [$max_age, ] . . . )
    // function query_exists(    $cache_key, [$max_age, ] . . . )
    // function query_value(     $cache_key, [$max_age, ] . . . )
    // function query_column(    $cache_key, [$max_age, ] . . . )
    // function query_map(       $cache_key, [$max_age, ] . . . )
    // function query_structure( $cache_key, [$max_age, ] . . . )

    function __call( $method_name, $parameters )
    {
      if( substr($method_name, 0, 6) == "query_" )
      {
        assert('count($parameters) > 2');
        
        $static = false;
        if( strpos($method_name, "_static_") )
        {
          $static      = true;
          $method_name = str_replace("_static_", "_", $method_name);
        }
        
        if( is_numeric($parameters[1]) || is_null($parameters[1]) )
        {
          return $this->get_from_cache_or_db($cache_key = $parameters[0], $max_age = $parameters[1], $method_name, $parameters, $offset = 2, $static);
        }
        else
        {
          return $this->get_from_cache_or_db($cache_key = $parameters[0], $max_age = null          , $method_name, $parameters, $offset = 1, $static);
        }
      }
      else
      {
        if( method_exists($this->cache, $method_name) )
        {
          return call_user_func_array(array($this->cache, $method_name), $parameters);
        }
        else
        {
          return $this->call_db($method_name, $parameters, $offset = 0);
        }
      }

      abort("Not supported: $method_name");
    }
    
    
    function execute()
    {
      return $this->call_db("execute", func_get_args(), $offset = 0);
    }


    function insert()
    {
      return $this->call_db("insert", func_get_args(), $offset = 0);
    }



  //===============================================================================================
  // SECTION: Database convenience routines.
  
  
    function query_table( $table_name, $max_age = null, $query = null, $cache_key = null, $static = false )
    {
      $cache_key or $cache_key = "table:$table_name";
      $query     or $query     = "SELECT * FROM $table_name";

      $method = $static ? "query_static_all" : "query_all";
      return $this->$method($cache_key, $max_age, $query);
    }

    function query_static_table( $table_name, $max_age = null, $query = null, $cache_key = null )
    {
      return $this->query_table($table_name, $max_age, $query, $cache_key, $static = true);
    }
    
    function query_map_of_table( $table_name, $key_field, $value_field, $max_age = null, $query = null, $cache_key = null, $static = false )
    {
      $cache_key or $cache_key = "map_of_table:$table_name:" . (is_array($key_field) ? implode(",", $key_field) : $key_field) . ":" . $value_field;

      $field_list = (array)($value_field == "*" ? "*" : array_merge(array_values((array)$key_field), array_values((array)$value_field)));
      $query or $query = "SELECT " . implode(", ", $field_list) . " FROM $table_name";

      $method = $static ? "query_static_map" : "query_map";
      return $this->$method($cache_key, $max_age, $key_field, $value_field, $query, $static);
    }

    function query_map_of_static_table( $table_name, $key_field, $value_field, $max_age = null, $query = null, $cache_key = null, $static = true )
    {
      return $this->query_map_of_table($table_name, $key_field, $value_field, $max_age, $query, $cache_key, $static = true);
    }

    function query_natural_map_of_table( $table_name, $max_age = null, $excluded_fields = array(), $static = false )
    {
      $cache_key = "natural_map_of_table:$table_name";
      $map = $this->get($cache_key, $max_age, $static);
      if( !$map )
      {
        assert('$this->db');
        if( $table = $this->db->schema->get_table($table_name) )
        {
          $all_fields   = $table->get_field_names();
          $key_fields   = $table->get_pk_field_names();
          $value_fields = array_values(array_diff($all_fields, $key_fields, $excluded_fields));
          $map = $this->query_map_of_table($table_name, $key_fields, $value_fields, $max_age, $query = null, $cache_key, $static);
        }
      }

      return $map;
    }

    function query_natural_map_of_static_table( $table_name, $max_age = null, $excluded_fields = array(), $static = false )
    {
      return $this->query_natural_map_of_table($table_name, $max_age, $excluded_fields, $static = true);      
    }




  //===============================================================================================
  // SECTION: Internals.


    function get_from_cache_or_db( $cache_key, $max_age, $query_method, $parameters, $offset, $static = false )
    {
      if( !$cache_key )
      {
        $data = $this->call_db($query_method, $parameters, $offset);
        $this->clear_next_query_results_filters();
        return $data;
      }

      //
      // If we're still here, we're dealing with the cache.
      
      $delayed = false;

      start:
      $data = $this->get($cache_key, $max_age, $static);
      if( is_null($data) )
      {
        //
        // To reduce the chances of a thundering herd trying to build static data at once, we'll 
        // sometimes wait a small interval to give someone else a chance to do the work for
        // us. However, to avoid making this script wait too long overall, we have a limited
        // budget of delay to use across all such attempts.
        //
        // NOTE: this feature proved highly suboptimal with non-static data. 
        
        if( $static && !$delayed && !Features::disabled("data_source_creation_avoidance") && $this->delay_budget > 0 && mt_rand(0, 9) > 2 ) 
        {
          $delay = mt_rand(3, floor($this->delay_budget / mt_rand(3,5)) + 3);
          $this->delay_budget -= $delay;
          usleep($delay);
          $delayed = true; 
          debug("CACHE DELAY of $delay microseconds on $cache_key");
          goto start;          
        }

        //
        // Determine if we can write the data back to cache on creation.

        $effectively_claimed = $static || array_key_exists($cache_key, $this->claims) || Script::filter("is_cache_key_writable", false, $cache_key, $this);

        //
        // Unclaimed, non-static data cannot be written back to the cache. However, this can lead 
        // to a lot of unnecessary database work. So, we give the world a chance to build it for
        // us (presumably in a way that is cache-friendly).

        if( Features::enabled("cache_filling") )   // BUG: Not sufficiently tested; don't enable!
        {
          debug("CACHE WRITE BACK on $cache_key is " . ($effectively_claimed ? "true" : "false"));
          if( !$effectively_claimed )
          {
            $data = Script::filter("unclaimed_db_backed_cache_miss", $data, $this, $cache_key, $max_age, $query_method, $parameters, $offset);
            debug("USING CACHE FILL for $cache_key");
          }
        }
         
        //
        // If we still don't have the data, it's time to hit the database.
        
        if( is_null($data) )
        {
          $data = $this->call_db($query_method, $parameters, $offset);
          if( !is_null($data) )
          {
            if( $effectively_claimed )
            {
              Features::enabled("data_source_data_dump") and debug("CACHED DATA for $cache_key:", $data);
              $this->set($cache_key, $data, $settings = null, $static);
            }
          }
        }
      }
      
      $this->clear_next_query_results_filters();

      return $data;
    }
    
    
    protected function call_db( $method_name, $parameters = array(), $offset = 0, $static = false )
    {
      $this->connect_db();  // Can't avoid it any longer.
      
      $key = null;
      if( substr($method_name, -15) == "_and_rows_found" )
      {
        $method_name = substr($method_name, 0, -15);
        $key         = substr($method_name, 6);
      }
      
      $results = call_user_func_array(array($this->db, $method_name), array_slice($parameters, $offset));
      
      if( $key )
      {
        $results = (object)array($key => $results, "rows_found" => $this->db->query_rows_found());
      }
      
      return $results;
    }


    protected function release_claims()
    {
      debug("CALLED TO RELEASE: ", $this->claims);
      
      foreach( $this->claims as $cache_key => $count )
      {
        $cache = $this->get_cache($cache_key);
        for( $i = 0; $i < $count; $i++ )
        {
          $cache->release($cache_key);
        }
      }
      
      $this->claims = array();
    }


    protected function commit_to_cache()
    {
      Script::signal("data_source_cache_committing", $this, $this->sets);
      foreach( $this->sets as $cache_key => $value )
      {
        debug("CACHE WRITING BACK: $cache_key");
        if( is_null($value) )
        {
          $this->get_cache($cache_key)->delete($cache_key);
        }
        else
        {
          $this->get_cache($cache_key)->set($cache_key, unserialize($value), @$this->set_settings[$cache_key]);
        }
      }

      $this->sets         = array();
      $this->set_settings = array();
    }
    
    
    protected function get_cache( $cache_key, $static = false )
    {
      $cache = array_fetch_value($this->routing, $cache_key);
      if( !$cache )
      {
        $cache = Script::filter("data_source_cache", $this->cache, $cache_key, $static, $this);
        foreach( $this->routers as $router )
        {
          $cache = Callback::do_call_with_array($router, array($cache, $cache_key, $static, $this));
        }

        $this->routing[$cache_key] = $cache;
      }
      
      return $cache;
    }
    
    
    //
    // Connects the db, if not already done.
    
    function connect_db()
    {
      if( $this->connector )
      {
        debug("CONNECTING DataSource to database");
        $this->db = $this->connector->call();
        $this->db->begin_transaction();

        foreach( $this->on_next_query as $callback )
        {
          $this->db->on_next_query_results($callback);
        }
        
        $this->on_last_query = $this->on_next_query;
        $this->on_next_query = array();
        
        foreach( $this->on_next_query_structure as $callback )
        {
          $this->db->on_next_query_structure_results($callback);
        }
        
        $this->on_last_query_structure = $this->on_next_query_structure;
        $this->on_next_query_structure = array();
        
        $this->schema    = $this->db->schema;
        $this->connector = null;
      }
      
      return $this->db;
    }
    
    function connect_schema()
    {
      $this->connect_db();
      return $this->schema;
    }
    
    
    function copy_state( $ds )
    {
      foreach( $this->routers as $router )
      {
        $ds->on_route($router);
      }

      foreach( $this->on_last_query as $callback )
      {
        $ds->on_next_query_results($callback);
      }
      
      foreach( $this->on_next_query as $callback )
      {
        $ds->on_next_query_results($callback);
      }

      foreach( $this->on_last_query_structure as $callback )
      {
        $ds->on_next_query_structure_results($callback);
      }
      
      foreach( $this->on_next_query_structure as $callback )
      {
        $ds->on_next_query_structure_results($callback);
      }
    }
  }
