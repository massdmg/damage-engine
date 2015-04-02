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

  require_once "pluralize.php";
  require_once "convert_camel_to_snake_case.php";
  

  //
  // Base class for game objects that are build on DataSource and DataHandler. Does all the heavy
  // lifting.

  class GameObject extends Metaclass
  {
    protected $_id_name;
    protected $_object_id;
    protected $_class_name;
    protected $_properties;
    protected $_loaded_test;
    protected $_ds;

    //
    // Makes a cache key from parts. You must override this to pass $class, as it's not
    // available to us otherwise.

    static function make_cache_key( $id, $class )
    {
      $args = func_get_args(); $args = array_slice($args, 2);
      $rest = array_flatten($args);
      $base = sprintf("%s(%s)", strtolower(convert_pascal_to_snake_case($class)), $id);
      return $rest ? $base . ":" . implode(":", $rest) : $base;
    }

    static function get_class_name()
    {
      abort("you must override GameObject::get_class_name()");
    }
    
    
    
    
    static function requires_claim_for_write_back()
    {
      return true;
    }


    //
    // Makes an instance of this GameObject.

    static function load( $id_value, $ds = null )
    {
      if( $object = static::build($id_value, $ds) )
      {
        $object->load_handler("master");
        if( $object->is_loaded() )
        {
          return $object;
        }
        else
        {
          $object->disengage();
        }
      }

      return null;
    }
    
    static function build( $id_value, $ds = null )
    {
      $ds or $ds = Script::fetch("ds");
      return $id_value !== 0 ? new static($id_value, $ds) : null;
    }
    
    static function flush( $id_value, $ds = null )
    {
      if( $object = static::build($id_value, $ds) )
      {
        $object->disengage();
      }
      
      return null;
    }


    //
    // Sets $this->$id_name for you, unless $id_value is null.

    function __construct( $id_name, $id_value = null, $loaded_test = null, $ds = null )
    {
      if( !is_null($id_value) )
      {
        $this->$id_name = $id_value;
      }

      $this->_id_name     = $id_name;
      $this->_class_name  = convert_pascal_to_snake_case(get_class($this));
      $this->_object_id   = sprintf("%s:%d", $this->_class_name, $id_value);
      $this->_loaded_test = $loaded_test;
      $this->_ds          = $ds or $this->_ds = Script::fetch("ds");
      $this->_properties  = DataHandlerProxy::find($this->_object_id . $this->_ds->get_id(), $key_pairs = $this->get_key_pairs());
    }
    
    function __sleep()
    {
      return array();   // No serialization
    }

    
    
    
    function get_source()
    {
      return $this->_ds;
    }
    
    function get_ds( $ds = null )
    {
      return $ds ? $this->_ds->limit($ds) : $this->_ds;
    }
    
    function is_claimed( $ds = null )
    {
      $ds or $ds = $this->_ds;
      return $ds->is_claimed($this->get_cache_key());
    }
    


    function get_object_id()
    {
      return $this->_object_id;
    }


    function is_loaded()
    {
      if( $this->_loaded_test )
      {
        $name = $this->_loaded_test;
        return isset($this->_properties->$name);
      }

      return true;
    }
    
    
    function is_cacheable()
    {
      return true;
    }

    function is_claimable()
    {
      return true;
    }
    
    //
    // Returns this object's cache key.

    function get_cache_key()
    {
      $id_name = $this->_id_name;
      return static::make_cache_key($this->$id_name);
    }
    
    
    function get_class_key()
    {
      return convert_pascal_to_snake_case(static::get_class_name());
    }


    //
    // Returns this object's key pairs, for use by DataHandler.

    function get_key_pairs()
    {
      $id_name = $this->_id_name;
      return array($id_name => $this->$id_name);
    }


    //
    // Returns the ID value for this object.

    function get_id()
    {
      $id_name = $this->_id_name;
      return $this->$id_name;
    }
    
    
    function get_id_name()
    {
      return $this->_id_name;
    }


    //
    // Returns this object's DataHandlerProxy.

    function get_proxy()
    {
      return $this->_properties;
    }


    function get_child_property( $child_name, $property_name, $default = null )
    {
      if( $child_record = $this->$child_name )
      {
        return object_fetch_property($child_record, $property_name, $default);
      }
      
      return $default;
    }
    
    
    //
    // Claims some single element of the object. NOTE: claiming is by convention, so be
    // sure your compatriots all agree on the protocol.
    
    function claim_handler( $handler_name )
    {
      $handler = $this->build_handler($handler_name, $error_if_missing = true);
      if( $ds = $this->get_source() )
      {
        $claim_key = $handler->get_cache_key();
        if( $count = $ds->claim($claim_key) )
        {
          $count == 1 and $handler->disengage();
          return true;
        }
      }
      
      return false;
    }
    
    
    function claim_handler_or_fail( $handler_name )
    {
      $this->claim_handler($handler_name) or throw_exception("unable_to_claim_handler", "handler_name", $handler_name, "object", $this);
    }


    //
    // Adds a record to one of the object's DataHandlers.
    
    function add( $type )
    {
      $pairs = array_pair_slice(func_get_args(), 1);
      return $this->manipulate_handler("add", $type, $pairs);
    }


    //
    // Replaces a record in one of the object's DataHandlers.
    
    function replace( $type )
    {
      $pairs = array_pair_slice(func_get_args(), 1);
      return $this->manipulate_handler("replace", $type, $pairs);
    }
    
    
    //
    // Deletes a record from one of the object's DataHandlers.
    
    function delete( $type )
    {
      $pairs = array_pair_slice(func_get_args(), 1);
      return $this->manipulate_handler("delete", $type, $pairs);
    }


    //
    // Updates a record in one of the object's DataHandlers.
    
    function update( $type )
    {
      $pairs = array_pair_slice(func_get_args(), 1);
      return $this->manipulate_handler("update", $type, $pairs);
    }
    
    
    //
    // A specialized update for a record with a numeric field that tends to increase over time.
    
    function increment( $type )
    {
      $pairs = array_pair_slice(func_get_args(), 1);
      return $this->manipulate_handler("increment", $type, $pairs);
    }


    //
    // Saves any changes back to the DataSource.

    function save( $signal = true )
    {
      if( $result = $this->_properties->save() )
      {
        $signal and Script::signal($this->_class_name . "_saved", $this);
      }
      
      return $result;
    }
    
    
    function save_all_to_cache( $signal = true )
    {
      if( $result = $this->_properties->save_all_to_cache() )
      {
        $signal and Script::signal($this->_class_name . "_saved", $this);
      }
      
      return $result;
    }


    //
    // Disengages all handlers and clears the in-process cache, to ensure this object doesn't
    // become cyclic garbage.

    function disengage()
    {
      foreach( $this->_properties->get_handler_names() as $name )
      {
        $handler = $this->_properties->get_handler($name, $remove = true);
        $handler->disengage();
      }
    }
    
    
    //
    // Passes all property gets on to the appropriate handler. Loads the handler if not already in
    // memory.
    //
    // Prepend "in_" to any handler name to get the handler itself.

    function &__get( $name )
    {
      $value = null;
      $snake = null;
      
      if( !property_exists($this->_properties, $name) )
      {
        $snake or $snake = convert_camel_to_snake_case($name);
        $this->load_handler($snake);
      }
      
      if( property_exists($this->_properties, $name) )
      {
        if( is_scalar($this->_properties->$name) )
        {
          $value = $this->_properties->$name;   // If you return a reference to a scalar member, $object->value -= 10 will get set by reference and then by setter; which is bad, as $old_value in __set() will be wrong
        }
        else
        {
          $value =& $this->_properties->$name;
        }
      }
      elseif( substr($name, 0, 3) == "in_" )
      {
        $value = $this->in(substr($name, 3));
      }
      else
      {
        $value = parent::__get($name, $snake);
      }

      return $value;
    }


    //
    // Passes all property sets on to the appropriate handler. Loads the handler if not already in
    // memory.

    function __set( $name, $new_value )
    {
      if( !$this->_properties->has_handler($name) && !$this->_properties->has_handler("master") )
      {
        $this->$name;   // Ensure the appropriate handler is loaded by triggering __get().
      }

      $old_value = null;
      $handled   = false;
      
      if( !property_exists($this->_properties, $name) )
      {
        $handled = parent::__set($name, $new_value);
      }
      
      if( !$handled )
      {
        $old_value = @$this->_properties->$name;
        $this->_properties->$name = $new_value;
        $handled = true;
      }
      
      if( $handled )
      {
        $this->signal_value_changed($name, $new_value, $old_value);
      }
    }


    function __isset( $name )
    {
      $snake = null;
      
      if( !property_exists($this->_properties, $name) )
      {
        $snake or $snake = convert_camel_to_snake_case($name);
        $this->load_handler($snake);
      }

      if( !property_exists($this->_properties, $name) and !property_exists($this->_properties, $snake) )
      {
        if( parent::__isset($name, $snake) )
        {
          return true;
        }
      }

      return isset($this->_properties->$name) || isset($this->_properties->$snake);
    }



    
    
  //===============================================================================================
  // SECTION: Handler support

    function build_handler_from_table( $name, $table_name, $details = array(), $ds = null )
    {
      $ds       = $this->get_source_for_handler($name, $ds);
      $game     = Script::fetch("game");
      $class    = get_class($this);
      $artifact = convert_pascal_to_snake_case($class) . "_${name}_query";
      $id_field = $this->get_id_name();
      $id       = $this->get_id();
      
      is_object($details) and $ds = $details and $details = array();
      is_string($details) and parse_str($details, $details);
      
      //
      // Determine the handler type.
      
      $type = array_fetch_value($details, "type"     );
      $type or array_has_member($details, "key"      ) and $type = "map";
      $type or array_has_member($details, "list"     ) and $type = "list";
      $type or array_has_member($details, "timestamp") and $type = "log";
      $type or array_has_member($details, "program"  ) and $type = "structure";
      $type or $type = "master";

      $timestamp = null;
      if( $type == "log" )
      {
        $timestamp = array_fetch_value($details, "timestamp", "effective_to");
        $details["order_by"] = array_merge(array($timestamp, -1), array_fetch_value($details, "order_by", array()));
      }
      
      //
      // Allow the id field name to be overridden.
      
      $id_field_name = $id_field;
      if( $alt_id_field = array_fetch_value($details, "id_field") )
      {
        $id_field_name = $alt_id_field;
      }
      

      //
      // Load or generate the query for the handler.
      
      $query = $game->load_query($artifact, $ds);
      if( !$query )
      {
        $schema = $ds->schema;
        $query  = $schema->get_base_query($table_name)->where(array_fetch_value($details, "criteria", "$id_field_name = {{$id_field}}"));

        array_has_member($details, "additional_criteria") and $query = $query->where($details["additional_criteria"]);
        array_has_member($details, "order_by"           ) and $query = $query->order_by($details["order_by"]);
        array_has_member($details, "limit"              ) and $query = $query->limit($details["limit"]);
        
        if( $extra_fields = array_fetch_value($details, "extras", array()) )
        {
          foreach( $extra_fields as $field => $value )
          {
            $query = $query->define($field, $ds->format_parameter($value));
          }
        }
        
        $query  = Script::filter($artifact, $query, $schema, $ds);
        $query  = $game->simplify_and_store_query($artifact, $query, $ds);
        $query or throw_alert("query_generation_failed", "class", $class, "handler", $name);
      }
      
      
      //
      // Set in the runtime parameters.
      
      $query->parameters = Script::filter("{$artifact}_parameters", array_merge(array($id_field => $id), array_fetch_value($details, "parameters", array())));
      
      
      //
      // Build the handler.
      
      $proxy          = $this->get_proxy();
      $cache_key      = $this->get_cache_key();
      $post_processor = array_fetch_value($details, "post_processor"); $post_processor and is_string($post_processor) and method_exists($this, $post_processor) and $post_processor = Callback::for_method($this, $post_processor);
      $load_filter    = array_fetch_value($details, "load_filter"   ); $load_filter    and is_string($load_filter   ) and method_exists($this, $load_filter   ) and $load_filter    = Callback::for_method($this, $load_filter   );
      $post_processor_error_handler = array_fetch_value($details, "post_processor_error_handler");
      
      if( array_has_member($details, "max_age") )
      {
        $ds = $ds->filter_by("age", array_fetch_value($details, "max_age", 60));
      }
      
      $handler = null;
      switch( $type )
      {
        case "map":
          $key   = array_fetch_value($details, "key"       ) or abort("no key for map handler");
          $value = array_fetch_value($details, "value", "*");
          
          is_string($key  ) and strpos($key  , ",") and $key   = preg_split('/,\s*/', $key  );
          is_string($value) and strpos($value, ",") and $value = preg_split('/,\s*/', $value);
          
          $handler = new DataHandlerForMapProperty($proxy, $name, $ds, $query, $key, $value, $cache_key, $post_processor, $load_filter, $post_processor_error_handler);
          break;
          
        case "list":
          $column  = array_fetch_value($details, "list", "*");
          $handler = new DataHandlerForListProperty($proxy, $name, $ds, $query, $column, $cache_key, $post_processor, $post_processor_error_handler);
          break;

        case "master":
        case "property_set":
          $handler = new DataHandlerForPropertySet($proxy, $name, $ds, $query, $cache_key, $post_processor, $post_processor_error_handler);
          break;

        case "log":
          $handler = new DataHandlerForLogProperty($proxy, $name, $ds, $query, $timestamp, $cache_key);
          break;
          
        case "child":
        case "child_record":
          $handler = new DataHandlerForChildRecord($proxy, $name, $ds, $query, $cache_key, $post_processor, $post_processor_error_handler);
          break;
          
        case "structure":
          $handler = new DataHandlerForStructuredProperty($proxy, $name, $ds, $query, array_fetch_value($details, "program"), $cache_key);
          break;
          
        default:
          abort("unrecognized handler type [$type]");
      } 
      
      //
      // Deal with legacy mode.
      
      if( $legacy_mode = array_fetch_value($details, "legacy_mode", array_fetch_value($details, "legacy")) )
      {
        is_array($legacy_mode) or $legacy_mode = coerce_type($legacy_mode, false);
        $handler->use_legacy_mode($legacy_mode);
      }
      
      return $handler;
    }
    

    //
    // Returns the data handler for the named data element. For most purposes, you won't need
    // to call this directly.

    function load_handler( $name, $try_plural = false, $load_data = true )
    {
      $error_if_missing = false;
      while( $name && !$this->_properties->has_handler($name) )
      {
        if( $handler = $this->build_handler($name, $error_if_missing) )
        {
          $class_name = get_class($this);
          if( $load_data )
          {
            Script::signal(sprintf("loading_%s_%s", $class_name, $name), $this, $this->_ds);
            $this->_properties->add_handler_and_load($handler);
            if( $this->is_loaded() )
            {
              Script::signal(sprintf("%s_%s_loaded", $class_name, $name), $this, $this->_ds);
            }
          }
          else
          {
            $this->_properties->add_handler($handler);
          }
          
          $this->_properties->has_handler($name) or abort(static::get_class_name() . ":$name handler loaded with wrong name: " . $handler->name);
          break;    //<<<<<<<< FLOW CONTROL <<<<<<<<
        }
        elseif( $try_plural )
        {
          $name = pluralize($name);
          $try_plural = false;
        }
        elseif( $name != "master" )
        {
          $name = "master";
          $error_if_missing = true;
        }
        else
        {
          $name = null;
        }
      }

      return $this->_properties->get_handler($name);
    }
    
    function load_handler_or_fail( $name, $try_plural = false, $load_data = true, $exclude_master = false )
    {
      $handler = $this->load_handler($name, $try_plural, $load_data) and !$exclude_master || $handler->name != "master" or throw_exception("unable_to_load_data_handler", "object", $this, "handler_name", $name);      
      return $handler;
    }
    
    
    //
    // Invalidates the named handler's data from the cache. If you pass multiple names, they will
    // all be invalidated. If you pass no names, all handlers will be invalidated.

    function invalidate( $handler_name = null )
    {
      $args          = func_get_args();
      $handler_names = array_flatten($args);

      //
      // If no handler is specified, invalidate everything about the object.

      if( empty($handler_names) )
      {
        abort("NYI: whole object invalidation");
      }

      //
      // Invalidate the targets.

      $handler = null;
      foreach( $handler_names as $handler_name )
      {
        if( $handler = $this->load_handler($handler_name, $try_plural = false, $load_data = false) )
        {
          $this->_properties->invalidate($handler);
        }
      }
    }


    //
    // Reloads the named handler's data from the database.
    //
    // Actually, it doesn't. You'll get the data reloaded next time you use it. It's functionally
    // the same as invalidate(), and will likely remain so.

    function reload( $handler_name = null )
    {
      $this->invalidate(func_get_args());
    }
    
    
    //
    // Used by the CRUD routines to perform an action on a handler. You shouldn't need to call this
    // directly.
    
    function manipulate_handler( $op, $type, $pairs )
    {
      $handler = $this->load_handler_or_fail($type, $try_plural = true, $load_data = true, $exclude_master = true);
      
      method_exists($handler, $op) or throw_exception("data_handler_operation_not_defined", "op", $op, "handler", $handler->name, "type", get_class($handler));
      $result = $handler->$op($pairs);
      $this->announce_change($op, $handler->name, $pairs);

      return $result;
    }


    //
    // Builds the named handler. You can override but, architecturally speaking, you are probably better
    // of implementing dispatch handlers in your GameSubsystem instead.

    function build_handler( $name, $trigger_error_if_missing = true )
    {
      $ds      = $this->get_source_for_handler($name);
      $signals = array_merge(ClassLoader::sprintf_with_pedigree("%s_build_{$name}_handler", $this), ClassLoader::sprintf_with_pedigree("%s_build_handler", $this));

      foreach( $signals as $event_name )
      {
        if( $handler = Script::dispatch($event_name, $name, $this, $ds) )
        {
          return $handler;
        }
      }

      $trigger_error_if_missing and abort("NYI: " . get_class($this) . "::build_handler($name)");
      return null;
    }


    //
    // Returns a cache-controllable DataSource for your handler. Control point examples:
    //   Player         — all data for all Player objects
    //   Player:master  — all data for all Player "master" handlers
    //   player21       — all data for Player 21
    //   player21:haven — all data for Player 21 "haven" handler

    function get_source_for_handler( $handler_name, $ds = null )
    {
      $class_name  = get_class($this);
      $object_name = $this->get_cache_key();

      return Script::fetch("game")->limit_data_source_age_by($this->get_ds($ds), $class_name, $class_name . ":" . $handler_name, $object_name, $object_name . ":" . $handler_name);
    }
    
    
    function signal_value_changed( $name, $new_value, $old_value )
    {
      Script::signal($this->_class_name . "_{$name}_changed", $this, $new_value, $old_value);

      $new_pairs = array($this->_id_name => $this->get_object_id(), $name => $new_value);
      $old_pairs = array($this->_id_name => $this->get_object_id(), $name => $old_value);
      
      $this->announce_change("set_value", $name, $new_pairs, $old_pairs, $signal_handler_changed = false);
    }
    
    
    function announce_change( $op, $name, &$new_pairs, $old_pairs = null, $signal_handler_changed = true )
    {
      $base_name = sprintf("%s_%s", $this->_class_name, $name);
      
      switch( $op )
      {
        case "set_value":
          /* no op */
          break;
          
        case "increment":
        case "update":
          Script::signal("{$base_name}_record_changed", $this, $new_pairs, $old_pairs);  // $old_pairs is almost guaranteed to be null at the moment; sorry
          break;

        case "add":
        case "replace":
          Script::signal("{$base_name}_record_added", $this, $new_pairs);
          break;
          
        case "delete":
          Script::signal("{$base_name}_record_deleted", $this, $new_pairs);   // $new_pairs is just the criteria; this may need to be revisited
          break;
          
        default:
          abort("NYI: announce_change for $op");
      }
      
      $signal_handler_changed and Script::signal("{$base_name}_changed", $this, $new_pairs, $old_pairs);
      Script::signal("{$this->_class_name}_changed", $name, $this, $new_pairs, $old_pairs);
    }


    protected function enumerate_handlers()
    {
      abort();
      $class_path = sprintf("%s/%s.php", dirname(__FILE__), get_class($this));
      if( $file = fopen($class_path) )
      {

      }
    }
  }
