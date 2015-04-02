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

  class GameSubsystem
  {
    protected $engine;
    protected $ds;
    protected $collections;
    public    $collection_slices;
    protected $slice_limit;
    protected $aspects;

    function __construct( $engine )
    {
      $this->engine            = $engine;
      $this->aspects           = array_slice(func_get_args(), 1);
      $this->ds                = $engine->limit_data_source_age_by($this->aspects);
      $this->collections       = array();
      $this->collection_slices = null;
      $this->slice_limit       = $engine->get_parameter("GAME_SUBSYSTEM_COLLECTION_SLICE_LIMIT", 10);
      $this->next_load_filter  = null;
      $this->next_load_structure_filter = null;
      
      Script::register_event_handler("aspect_invalidated", array($this, "handle_aspect_invalidated"));
    }
    
    function handle_aspect_invalidated( $aspect )
    {
      if( array_key_exists($aspect, $this->aspects) )
      {
        $this->ds          = $this->engine->limit_data_source_age_by($this->aspects);
        $this->collections = array();
      }
    }
    
    function unload_collections()
    {
      $this->collections = array();
      $this->collection_slices = null;
    }




  //===============================================================================================
  // SECTION: Magic.

    //
    // Returns (in order of preference):
    //  * the value for a GameParameterDict parameter, once converted to upper case
    //  * any loadable collection (you must provide a load_$name() routine that returns the
    //    collection)

    function &__get( $name )
    {
      if( array_key_exists($uc = strtoupper($name), $this->engine->parameters) )
      {
        warn("received deprecated direct reference to game parameters $name");
        abort("convert ->$name to explicit get_parameter() call with default");
      }
      elseif( !array_key_exists($name, $this->collections) && method_exists($this, $loader = "load_" . $name) )
      {
        $this->build_collection($name, Callback::for_method($this, "load_" . $name));
      }

      array_key_exists($name, $this->collections) or throw_internal_exception("subsystem_parameter_not_declared", "name", $name);   // Either you didn't provide a loader or you didn't declare your property or it's a typo
      return $this->collections[$name];
    }

    function __isset( $name )
    {
      return array_key_exists(strtoupper($name), $this->engine->parameters) ||
             array_key_exists($name, $this->collections)                    ||
             method_exists($this, "load_" . $name);
    }

    function __call( $name, $args )
    {
      if( substr($name, 0, 4) == "log_" )
      {
        return $this->engine->make_log($name, $args);
      }
      
      abort(sprintf("unknown method %s::%s", get_class($this), $name));
    }




  //===============================================================================================
  // SECTION: General support.


    function get_parameter( $name, $default = null )
    {
      return $this->engine->get_parameter($name, $default);
    }


    function get_ds( $ds = null )
    {
      return $ds ? $this->ds->limit($ds) : $this->ds;
    }
    
    
    function &get_collection( $name, $loader )
    {
      if( !array_key_exists($name, $this->collections) )
      {
        $this->build_collection($name, $loader);
      }

      return $this->collections[$name];
    }
    
    function flush_collection( $name )
    {
      unset($this->collections[$name]);
    }


    
    function is_collection_loaded( $name )
    {
      return array_has_member($this->collections, $name);
    }
    
    
    function get_collection_slice( $name )
    {
      $this->collection_slices or $this->collection_slices = new ObjectCache($this->slice_limit);
      
      $args  = func_get_args();
      $fqn   = implode(":::", $args);
      $keys  = array_slice($args, 1);
      $value = null;
      
      if( !$this->collection_slices->has($fqn) )
      {
        method_exists($this, $loader_name = "load_slice_of_" . convert_pascal_to_snake_case($name)) or throw_exception("collection_slice_loader_missing", "collection", $name, "loader", $loader);
        $loader = Callback::for_method($this, $loader_name);
        $value  = Callback::do_call_with_array($loader, $keys);
        if( !is_null($value) )
        {
          $this->collection_slices->set($fqn, $value);
        }
      }

      return is_null($value) ? $this->collection_slices->get($fqn) : $value;
    }
    
    
    

  //===============================================================================================
  // SECTION: Load support.
  
    protected function filter_next_load_using( $method )
    {      
      $this->next_load_filter = is_a($method, "Callback") ? $method : Callback::for_method($this, $method);
    }
    
    protected function filter_next_load_structure_using( $method )
    {
      $this->next_load_structure_filter = is_a($method, "Callback") ? $method : Callback::for_method($this, $method);
    }
    
    protected function load_all( $cache_key, $query )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_all($cache_key, array_slice(func_get_args(), 1));
    }

    protected function load_natural_map_of_table( $table_name )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_natural_map_of_static_table($table_name);
    }

    protected function load_map_of_table( $table_name, $key, $value = "*" )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_map_of_static_table($table_name, $key, $value);
    }

    protected function load_table( $table_name )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_table($table_name);
    }

    protected function load_map( $cache_key, $key, $value, $query )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_map($cache_key, $key, $value, array_slice(func_get_args(), 3));
    }

    protected function load_value( $cache_key, $field, $default, $query )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_value($cache_key, $field, $default, array_slice(func_get_args(), 3));
    }
    
    protected function load_first( $cache_key, $query )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_first($cache_key, array_slice(func_get_args(), 1));
    }
    
    protected function load_column( $cache_key, $field, $query )
    {
      $this->apply_next_load_filter();
      return $this->ds->query_static_column($cache_key, $max_age = null, $field, array_slice(func_get_args(), 2));
    }

    protected function load_structure( $cache_key, $program, $query )
    {
      $GLOBALS["unpack_main_database_fields_and_store_keys"] = in_array("+extra_fields", array_flatten($program));
      $this->apply_next_load_filter();
      $structure = $this->ds->query_static_structure($cache_key, $program, array_slice(func_get_args(), 2));
      $GLOBALS["unpack_main_database_fields_and_store_keys"] = false;
      return $structure;
    }

    private function apply_next_load_filter()
    {
      if( $this->next_load_filter )
      {
        $this->ds->on_next_query_results($this->next_load_filter);
        $this->next_load_filter = null;
      }
      
      if( $this->next_load_structure_filter )
      {
        $this->ds->on_next_query_structure_results($this->next_load_structure_filter);
        $this->next_load_structure_filter = null;
      }
    }
  
    protected function build_collection( $name, $loader )
    {
      $value = Callback::do_call($loader);
      if( !is_null($value) )
      {
        $this->collections[$name] = $value;
      }
      else
      {
        throw_exception($reportable = false, "unable_to_load_property_from_data_source", "property", $name);
      }
    }
  }
