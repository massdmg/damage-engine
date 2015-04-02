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

  if( !defined("DOCUMENT_ROOT_MADE_REAL") )
  {
    $_SERVER["DOCUMENT_ROOT"] = realpath($_SERVER["DOCUMENT_ROOT"]);
    define("DOCUMENT_ROOT_MADE_REAL", true);
  }

  require_once __DIR__ . "/ClassObject.php";
  require_once __DIR__ . "/HTTPResponse.php";
  require_once __DIR__ . "/Stack.php";

  //
  // Collects statistics about the run of a script and provides the machinery to summarize the
  // data. Also provides filter and event handler systems.
  //
  // Reserved keys:
  //   duration — the elapses microseconds since the Run started
  //   timings  — see note_time() for details

  class Script
  {
    public    static $class;
    public    static $response;
    public    static $start_microtime;
    public    static $start_time;
    public    static $start_micros;
    public    static $script_name;
    public    static $template_path;
    public    static $default_response_template;
    protected static $data;
    protected static $filters;
    protected static $event_handlers;
    protected static $filter_stack;
    protected static $event_stack;
    protected static $counter;
    protected static $system_component_names;
    protected static $system_component_paths;


    //
    // Starts the Script.

    static function initialize()
    {
      list($fraction, $seconds) = explode(" ", microtime());
      
      static::$class           = new ClassObject(__CLASS__);
      static::$response        = new HTTPResponse();
      static::$start_microtime = microtime(true);              
      static::$start_time      = (int)$seconds;
      static::$start_micros    = substr($fraction, 2);
      static::$script_name     = $_SERVER["SCRIPT_FILENAME"];
      static::$data            = array("duration" => 0, "memory_usage" => 0, "memory_usage_peak" => 0);
      static::$filters         = array();
      static::$event_handlers  = array();
      static::$filter_stack    = new Stack();
      static::$event_stack     = new Stack();
      static::$counter         = 0;
      
      static::$system_component_names = array();
      static::$system_component_paths = array();
      static::$default_response_template = null;
    }


    static function get_script_name()
    {
      return static::$script_name;
    }

    static function set_script_name( $name = null, $add_to_template_path = true )
    {
      $name or $name = static::$script_name;
      static::$script_name = static::filter("script_name", $name);
      
      $add_to_template_path and static::add_to_template_path($name);
    }
    
    static function add_to_template_path( $name )
    {
      static::$template_path[] = implode('', array_map('ucfirst', explode('_', preg_replace('/[^\w]/', "", $name)))) . "ResponseTemplate";
    }


    static function get_id()
    {
      $id = static::get("script_id") or $id = getmypid();
      return $id;
    }


    static function fail( $message = null, $parameters = array() )
    {
      is_string($message) or $message = "service_failed";
      static::signal("script_failing", $message, $parameters);

      header("HTTP/1.0 500 Service failed");
      abort($message);
    }




  //===============================================================================================
  // System components
  
    static function set_system_component_names( $names )
    {
      static::$system_component_names = $names;
      static::$system_component_paths = array();

      $base_path = dirname($_SERVER["DOCUMENT_ROOT"]);
      foreach( $names as $name )
      {
        static::$system_component_paths[] =  $base_path . "/" . $name;
      }
    }
    
    
    static function get_system_component_paths( $alternate_base_path = null )
    {
      if( $alternate_base_path )
      {
        $paths = array();
        foreach( static::$system_component_names as $name )
        {
          $paths[] = "$alternate_base_path/$name";
        }
      }
      else
      {
        $paths = static::$system_component_paths;
      }
      
      return $paths;
    }
    
    
    static function find_system_component( $relative_path )
    {
      substr($relative_path, 0, 1) == "/" or $relative_path = "/$relative_path";
      foreach( static::$system_component_paths as $base_path )
      {
        $path = $base_path . $relative_path;
        if( file_exists($path) )
        {
          return $path;
        }
      }
      
      return null;
    }
    
    
    static function find_system_components_matching( $relative_glob, $alternate_base_path = null )
    {
      $components = array();
      
      foreach( static::get_system_component_paths($alternate_base_path) as $base_directory )
      {
        foreach( glob("$base_directory/$relative_glob") as $path )
        {
          $filename = basename($path);
          list($component, $extension) = @explode(".", $filename, 2);
          
          array_has_member($components, $component) or $components[$component] = $path;
        }
      }
      
      return $components;
    }
    
    
    


  //===============================================================================================
  // Call method
  
    static function get_call_method()
    {
      return $_SERVER['REQUEST_METHOD'];
    }
    
    static function was_called_as( $method )
    {
      return strtolower($_SERVER["REQUEST_METHOD"]) == strtolower($method);
    }
    
    static function was_called_as_post()
    {
      return static::was_called_as("post");
    }
    
    static function was_called_as_get()
    {
      return static::was_called_as("get");
    }
    
    static function was_called_as_put()
    {
      return static::was_called_as("put");
    }
    
    static function was_called_as_delete()
    {
      return static::was_called_as("delete");
    }
    
  
  
  

  //===============================================================================================
  // Parameter management
  

    //
    // Returns true if the name is defined in $_REQUEST. Automatically checks both snake- and
    // camel-case versions of the name, unless you clear $check_alt.

    static function has_parameter( $name, $allow_empty = true, $outer = true )
    {
      $index = $name;
      $camel = false;

    retry:
      if( array_key_exists($index, $_REQUEST) && ($allow_empty || strlen($_REQUEST[$index]) != 0) )
      {
        return true;
      }
      elseif( Features::enabled("camel_case_parameters") && !$camel )
      {
        $index = static::convert_snake_to_camel_case($name);
        $camel = true;
        goto retry;         //<<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<<
      }
      
      return false;
    }


    //
    // Gets a parameter from the $_REQUEST. If you supply a default, the result will be coerced
    // to match. If Features::enabled("camel_case_parameters"), tries a (naive) camel case version
    // of the name if the name isn't present.

    static function get_parameter( $name, $default = null, $fail_if_missing = false, $fail_parameters = array() )
    {
      $value = $default;
      $index = $name;
      $camel = false;

    retry:
      if( array_key_exists($index, $_REQUEST) && (is_array($_REQUEST[$index]) || strlen($_REQUEST[$index]) != 0) )
      {
        $value = $_REQUEST[$index];
      }
      elseif( Features::enabled("camel_case_parameters") && !$camel )
      {
        $index = static::convert_snake_to_camel_case($name);
        $camel = true;
        goto retry;         //<<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<<
      }
      elseif( $fail_if_missing )
      {
        static::fail($fail_if_missing, $fail_parameters);
      }

      return coerce_type($value, $default);
    }


    //
    // A more explicitly-named synonym for get() when the parameter is required.

    static function get_parameter_or_fail( $name, $default = null, $message = null )
    {
      return static::get_parameter($name, $default, $fail_message = "missing_required_parameter", array("parameter" => $name));
    }


    //
    // A version of get_parameter() for strings that must match a pattern.

    static function filter_parameter( $name, $pattern, $default = null, $fail_if_invalid = false, $fail_parameters = array() )
    {
      $value = static::get_parameter($name, $default, $fail_if_invalid, $fail_parameters);
      if( empty($value) || !is_string($value) || !(substr($pattern, 0, 1) == "/" ? preg_match($pattern, $value) : $pattern == $value) )
      {
        if( $fail_if_invalid )
        {
          static::fail($fail_if_invalid, $fail_parameters);
        }
        else
        {
          $value = $default;
        }
      }

      return $value;
    }


    //
    // A more explicitly-named synonym for filter() when the parameter is required.

    static function filter_parameter_or_fail( $name, $pattern, $default = null, $message = null, $fail_parameters = array() )
    {
      if( !$message )
      {
        $message         = "parameter_format_mismatch";
        $fail_parameters = array("parameter" => $name, "expected" => $pattern);
      }

      return static::filter_parameter($name, $pattern, $default, $message, $fail_parameters);
    }


    //
    // Converts a value into one that matches $exemplar for type.

    static function coerce_type( $value, $exemplar )
    {
      return coerce_type($value, $exemplar);
    }



    //
    // Parses a parameter containing data of the form '0|1|2|3,1|2|3|4'. Returns an array of
    // objects with properties named by $labels or by index. If $strict, triggers an E_USER_WARNING
    // if an item elements does match the expected label count.

    static function get_parsed_parameter( $name, $labels = null, $strict = false )
    {
      return static::parse_comma_delimited_pipes_parameter($name, $labels, $strict);
    }

    static function parse_comma_delimited_pipes_parameter( $name, $labels = null, $strict = false )
    {
      $data = static::get_parameter($name, "");
      return static::parse_comma_delimited_pipes($data, $labels, $strict);
    }
    
    static function parse_comma_delimited_pipes_parameter_into_map( $name, $labels, $key, $value, $strict = false )
    {
      $map = array();
      if( $data = static::parse_comma_delimited_pipes_parameter($name, $labels, $strict) )
      {
        foreach( $data as $object )
        {
          if( property_exists($object, $key) && property_exists($object, $value) )
          {
            $map[$object->$key] = $object->$value;
          }
          elseif( $strict )
          {
            static::fail("unable_to_map_cdp_parameter");
          }
        }
      }
      
      return $map;
    }


    static function parse_comma_delimited_parameter( $name, $type_exemplar = "" )
    {
      if( $data = static::get_parameter($name, "") )
      {
        $cleaner = Callback::for_method_with_dynamic_offset(static::$class, "coerce_type", $dynamic_offset = 0, $default = $type_exemplar)->get_php_callback();
        return array_map($cleaner, array_map('ltrim', explode(',', $data)));
      }

      return array();
    }


    //
    // Parses data of form '0|1|2|3,1|2|3|4'
    // return an array of stdObjects with properties named according to $labels, or 0-indexed if no labels supplied.
    // if $strict is true, throws a warning if an item lacks the a number of elements which matches the number of $labels

    static function parse_comma_delimited_pipes( $data, $labels = null, $strict = false )
    {
      $return_list = array();
      if( $data )
      {
        $defaults = array();
        if( empty($labels) )
        {
          $labels = array();
        }
        elseif( $keys = array_keys($labels) and !is_numeric(array_shift($keys)) )
        {
          $pairs  = $labels;
          $labels = array();
          foreach( $pairs as $label => $default )
          {
            $labels[]   = $label;
            $defaults[] = $default;
          }
        }

        $label_count = count($labels);
        $comma_list  = explode(',', $data);
        foreach( $comma_list as $item )
        {
          $s_obj = new stdClass();
          $elements = explode('|', $item);
          if(!$strict || $label_count == count($elements))
          {
            foreach($elements as $index => $element)
            {
              if(isset($labels[$index]))
              {
                $s_obj->$labels[$index] = array_key_exists($index, $defaults) ? coerce_type($element, $defaults[$index]) : $element;
              }
              else
              {
                $s_obj->$index = $element;
              }
            }
            $return_list[] = $s_obj;
          }
          else
          {
            trigger_error("Incorrect number of parsed elements with strict true: '$item'", E_USER_WARNING);
          }
        }
      }
      return $return_list;
    }


    static function unset_parameter( $name )
    {
      unset($_REQUEST[$name]);
      unset($_POST[$name]);
      unset($_GET[$name]);
      unset($_COOKIE[$name]);
    }
    
    
    static function set_parameter( $name, $value )
    {
      $_REQUEST[$name] = $value;
    }



  //===============================================================================================
  // Response management

    //
    // Sends the current $response object and exits. Any parameters you pass will be sent to the
    // $response->send() verbatim.

    static function send_response_and_exit()
    {
      if( static::$response )
      {
        call_user_func_array(array(static::$response, "send"), func_get_args());
      }

      exit;
    }


    //
    // Sends data to the client. Keeps track of the amount of data sent, for reporting purposes.

    static function send_text( $data )
    {
      if( !is_string($data) )
      {
        if( Features::debugging_enabled() and !Features::disabled("include_run_report_in_response") )
        {
          if( is_object($data) )
          {
            isset($data->report) or $data->report = Script::report("array");
          }
          elseif( is_array($data) )
          {
            isset($data["report"]) or $data["report"] = Script::report("array");
          }
        }
        
        $data = json_encode($data);
      } 
      
      $data = static::filter("response_text", $data);

      flush_output_buffers();

      debug($data);

      static::accumulate("response_size", strlen($data));
      print $data;
      flush();
    }

    static function send( $data )
    {
      static::send_text($data);
    }
    
    
    static function render_response()
    {
      foreach( static::$template_path as $template_name )
      {
        if( ClassLoader::is_loadable($template_name) )
        {
          $args = func_get_args();
          array_unshift($args, static::$response);
      
          $instance = new $template_name();
          return call_user_func_array(array($instance, "render"), $args);
        }
      }
    }


    static function render_special_response( $service_template )
    {
      array_unshift(static::$template_path, $service_template);
      static::render_response();
    }
    


    

  //===============================================================================================
  // Global state


    //
    // Returns the current value of a (registered) global variable/counter/whatever.

    static function get( $name, $default = null )
    {
      switch( $name )
      {
        case "duration":
          return microtime(true) - static::$start_microtime;

        case "peak_memory_usage":
        case "memory_usage_peak":
          return memory_get_peak_usage();

        case "memory_usage":
          return memory_get_usage();

        default:
          if( isset(static::$data[$name]) )
          {
            if( is_scalar(static::$data[$name]) )
            {
              return coerce_type(static::$data[$name], $default);
            }
            else
            {
              return static::$data[$name];
            }
          }
      }

      return $default;
    }


    //
    // Returns the current value of a global variable, or triggers an error if the variable hasn't
    // been registered with the script.

    static function fetch( $name )
    {
      if( func_num_args() == 1 )
      {
        if( isset(static::$data[$name]) )
        {
          return static::$data[$name];
        }
        else
        {
          static::fail("unable_to_fetch_script_resource", "name", $name);
        }
      }
      else
      {
        return static::get($name, func_get_arg(1));
      }
    }


    static function set( $name, $amount )
    {
      static::$data[$name] = $amount;
      return $amount;
    }

    static function append( $name, $value )
    {
      isset(static::$data[$name]) && is_array(static::$data[$name]) or static::$data[$name] = array();
      static::$data[$name][] = $value;
      return $value;
    }
    
    static function concat( $name, $value )
    {
      if( is_array($value) )
      {
        isset(static::$data[$name]) && is_array(static::$data[$name]) or static::$data[$name] = array();
        static::$data[$name] = array_merge(static::$data[$name], $value);        
      }
      else
      {
        static::$data[$name] = ((string)@static::$data[$name]) . $value;
      }
    }

    static function accumulate( $statistic, $amount = 0 )
    {
      static::set($statistic, @static::$data[$statistic] + $amount);
    }

    static function increment( $statistic )
    {
      static::accumulate($statistic, 1);
    }

    static function decrement( $statistic )
    {
      static::accumulate($statistic, -1);
    }

    static function record( $statistic, $value )
    {
      if( !array_key_exists($statistic, static::$data) || !is_array(static::$data[$statistic]) )
      {
        static::$data[$statistic] = array();
      }

      static::$data[$statistic][] = $value;
    }

    static function note_time( $label, $note = null )
    {
      static::record("timings", new ScriptAnnotation(static::get("duration"), $label, $note));
    }




  //=================================================================================================
  // Filters


    //
    // Adds a filter to the named filter chain, at the specified priority.

    static function register_filter( $name, $callback, $priority = 10 )
    {
      $index = "f" . static::increment_counter();
      
      static::$filters[$name][$priority][$index] = $callback;
      ksort(static::$filters[$name]);

      return $index;
    }

    static function unregister_filter( $name, $index, $priority = 10 )
    {
      unset(static::$filters[$name][$priority][$index]);
    }


    //
    // Iterates over an object's methods looking for filter_<name>() methods, which it
    // registers as filters.

    static function register_filters_from( $object, $priority = 10 )
    {
      foreach( static::map_filters_from($object) as $event_name => $method_name )
      {
        static::register_filter($event_name, Callback::for_method($object, $method_name), $priority);
      }
    }


    //
    // Builds a map of event_name => method_name for all filter_<event>() methods on $object.

    static function map_filters_from( $object )
    {
      $map = map_event_handlers_and_filters_from($object);
      return $map["filters"];
    }


    //
    // Calls all registered filters (progressively, in order) on the specified value. As a
    // convenience, you can call filter_<name>() for any filter <name>.

    static function filter( $name, $value )
    {
      $extra_parameters = array_slice(func_get_args(), 2);

      if( is_array($name) )
      {
        $names = $name;
        foreach( $names as $name )
        {
          $value = call_user_func_array(array(__CLASS__, "filter"), array_merge(array($name, $value), $extra_parameters));
        }
      }
      else
      {
        $name = strtolower($name);
        if( array_key_exists($name, static::$filters) )
        {
          static::$filter_stack->push((object)array("name" => $name, "stop" => false));

          $args = array_slice(func_get_args(), 2);
          foreach( static::$filters[$name] as $priority => $callbacks )
          {
            foreach( $callbacks as $callback )
            {
              // error_log("$name calling into: " . Callback::describe($callback));
              $value = Callback::do_call_with_array($callback, array_merge(array($value), $extra_parameters));
              // error_log("$name returned from " . Callback::describe($callback));
              if( static::$filter_stack->top()->stop )
              {
                break 2;
              }
            }
          }

          static::$filter_stack->pop();
        }
      }

      return $value;
    }


    static function filter_and_ignore_exceptions( $name, $value )
    {
      try
      {
        $value = call_user_func_array(array("Script", "filter"), func_get_args());
      }
      catch( Exception $e )
      {
        warn("Ignored exception during $name filter:", $e);
      }
      
      return $value;
    }


    //
    // When called during filter processing, causes the value you return to be used without further
    // filtering.

    static function skip_remaining_filters()
    {
      if( $frame = static::$filter_stack->top() )
      {
        $frame->stop = true;
      }
    }




  //=================================================================================================
  // Event Handlers


    //
    // Adds an event handler to the named event chain, at the specified priority.

    static function register_event_handler( $name, $callback, $priority = 10 )
    {
      $index = "h" . static::increment_counter();
      
      array_key_exists($name    , static::$event_handlers       ) or static::$event_handlers[$name]            = array();
      array_key_exists($priority, static::$event_handlers[$name]) or static::$event_handlers[$name][$priority] = array();
      
      static::$event_handlers[$name][$priority][$index] = $callback;
      ksort(static::$event_handlers[$name]);

      return $index;
    }

    static function unregister_event_handler( $name, $index, $priority = 10 )
    {
      unset(static::$event_handlers[$name][$priority][$index]);
    }


    static function register_signal( $name, $callback, $priority = 10 )
    {
      return static::register_event_handler($name, $callback, $priority);
    }

    static function register_handler( $name, $callback, $priority = 10 )
    {
      return static::register_event_handler($name, $callback, $priority);
    }




    //
    // Iterates over an object's methods looking for handle_<event>() methods, which it
    // registers as event handlers.

    static function register_event_handlers_from( $object, $priority = 10 )
    {
      foreach( static::map_event_handlers_from($object) as $event_name => $method_name )
      {
        static::register_event_handler($event_name, Callback::for_method($object, $method_name), $priority);
      }
    }


    //
    // Builds a map of event_name => method_name for all handle_<event>() methods on $object.

    static function map_event_handlers_from( $object )
    {
      $map = map_event_handlers_and_filters_from($object);
      return $map["event_handlers"];
    }


    //
    // Calls all registered event handlers for the named event.

    static function signal( $names )
    {
      $args = null;
      foreach( (array)$names as $name )
      {
        $name = strtolower($name);
        if( array_key_exists($name, static::$event_handlers) )
        {
          static::$event_stack->push((object)array("name" => $name, "stop" => false));

          is_null($args) and $args = array_slice(func_get_args(), 1);
          foreach( static::$event_handlers[$name] as $priority => $callbacks )
          {
            foreach( $callbacks as $callback )
            {
              // error_log("$name calling into: " . Callback::describe($callback));
              $value = Callback::do_call_with_array($callback, $args);
              // error_log("$name returned from " . Callback::describe($callback));
              if( static::$event_stack->top()->stop )
              {
                break 2;
              }
            }
          }

          static::$event_stack->pop();
        }
      }
    }
    
    
    static function signal_and_ignore_exceptions( $name )
    {
      try
      {
        call_user_func_array(array("Script", "signal"), func_get_args());
      }
      catch( Exception $e )
      {
        warn("Ignored exception from $name signal:", $e);
      }
    }


    //
    // Similar to signal(), but stops at and returns the first non-null value produced by any
    // handler. Returns null otherwise.

    static function dispatch( $names )
    {
      $args   = null;
      $result = null;
      foreach( (array)$names as $name )
      {
        $name = strtolower($name);
        if( array_key_exists($name, static::$event_handlers) )
        {
          static::$event_stack->push((object)array("name" => $name, "stop" => false));

          is_null($args) and $args = array_slice(func_get_args(), 1);
          foreach( static::$event_handlers[$name] as $priority => $callbacks )
          {
            foreach( $callbacks as $callback )
            {
              $result = Callback::do_call_with_array($callback, $args);
              if( $result || static::$event_stack->top()->stop )
              {
                break 2;
              }
            }
          }

          static::$event_stack->pop();
        }
      }

      return $result;
    }


    //
    // When called during event handling, stops further processing of the event.

    static function skip_remaining_event_handlers()
    {
      if( $frame = static::$event_stack->top() )
      {
        $frame->stop = true;
      }
    }


    //
    // Iterates over an object's methods looking for handle_<event>() and filter_<name>()
    // methods, which is registers appropriately.

    static function register_event_handlers_and_filters_from( $object, $priority = 10 )
    {
      $count = 0;

      foreach( get_class_methods($object) as $method_name )
      {
        if( strpos($method_name, "filter_") === 0 )
        {
          $event_name = substr($method_name, strlen("filter_"));
          static::register_filter($event_name, Callback::for_method($object, $method_name), $priority);
          $count++;
        }
        elseif( strpos($method_name, "handle_") === 0 )
        {
          $event_name = substr($method_name, strlen("handle_"));
          static::register_event_handler($event_name, Callback::for_method($object, $method_name), $priority);
          $count++;
        }
      }

      return $count;
    }


    //
    // Builds a map of type => map of event_name => method_name for all handle_<event>() methods on $object.

    static function map_event_handlers_and_filters_from( $object )
    {
      $map = array("filters" => array(), "event_handlers" => array());
      foreach( get_class_methods($object) as $method_name )
      {
        if( strpos($method_name, "handle_") === 0 )
        {
          $event_name = substr($method_name, strlen("handle_"));
          $map["event_handlers"][$event_name] = $method_name;
        }
        elseif( strpos($method_name, "filter_") === 0 )
        {
          $filter_name = substr($method_name, strlen("filter_"));
          $map["filters"][$filter_name] = $method_name;
        }
      }

      return $map;
    }






  //=================================================================================================
  // Reporting


    static function report( $as = "comment" )
    {
      $method = "report_as_" . $as;
      return method_exists(__CLASS__, $method) ? static::$method() : static::report_as_text();
    }


    static function report_as_comment()
    {
      $pairs = array();
      foreach( static::get_sorted_keys() as $key )
      {
        $value = static::get($key);
        if( !is_object($value) )
        {
          is_array($value) or $value = array($value);
          foreach( $value as $entry )
          {
            $pairs[] = sprintf("%s=\"%s\"", $key, $entry);
          }
        }
      }

      return sprintf("<!-- %s -->", implode(" ", $pairs));
    }


    static function report_as_text( $raw = false, $width = null )
    {
      $lines = array();

      if( is_null($width) )
      {
        $width = static::get_key_width();
      }

      foreach( static::get_sorted_keys() as $key )
      {
        $first = true;
        $value = static::get($key);
        if( !is_object($value) )
        {
          $array = is_array($value) ? array_flatten($value) : array($value);
          foreach( $array as $value )
          {
            $lines[] = sprintf("%-${width}s: %s", $first ? $key : "", $value);
            $first = false;
          }
        }
      }

      return $raw ? $lines : implode("\n", $lines);
    }


    static function report_as_html()
    {
      require_once "html.php";

      $pairs = array();
      foreach( static::get_sorted_keys() as $key )
      {
        $value = static::get($key);
        if( !is_object($value) )
        {
          $pairs[] = tags(dt($key), dd("#$key", is_array($value) ? menu($value) : text($value)));
        }
      }

      return tag("dl", $pairs);
    }


    static function report_as_array()
    {
      $array = array();
      foreach( static::get_sorted_keys() as $key )
      {
        $value = static::get($key);
        if( !is_object($value) )
        {
          $array[$key] = $value;
        }
      }

      return $array;
    }


    static function report_as_json()
    {
      return json_encode(static::report_as_array());
    }


    static function report_to_error_log()
    {
      error_log(sprintf("%s %s statistics: %s", static::$script_name, static::get_id(), implode("  ", static::report_as_text(true, 0))));
    }


    static function report_to_debug_log()
    {
      debug(sprintf("%s %s statistics: %s", static::$script_name, static::get_id(), implode("  ", static::report_as_text(true, 0))));
    }




  //=================================================================================================
  // Support


    static function __callStatic( $name, $args )
    {
      if( substr($name, 0, 7) == "respond" )
      {
        call_user_func_array(array(static::$response, str_replace("respond", "send", $name)), $args);
        exit;
      }
      elseif( substr($name, 0, 7) == "filter_" )
      {
        $value  = $args[0];
        $filter = substr($name, 7);

        if( count($args) == 1 )
        {
          return static::filter($filter, $value);
        }
        else
        {
          return call_user_func_array(array(__CLASS__, "filter"), array_merge(array($value, $filter), array_slice($args, 1)));
        }
      }
      elseif( substr($name, 0, 7) == "signal_" )
      {
        $event = substr($name, 7);
        if( count($args) == 0 )
        {
          return static::signal($event);
        }
        else
        {
          return call_user_func_array(array(__CLASS__, "signal"), array_merge(array($event), $args));
        }
      }

      trigger_error(sprintf("unknown method %s::%s", __CLASS__, $name), E_USER_ERROR);
    }


    static function get_sorted_keys()
    {
      $keys = array_keys(static::$data);
      sort($keys);
      return $keys;
    }


    static function get_key_width()
    {
      $width = 0;
      foreach( array_keys(static::$data) as $key )
      {
        $length = strlen($key);
        $length <= $width or $width = $length;
      }

      return $width;
    }
    
    
    static function increment_counter()
    {
      static::$counter++;
      return static::$counter;
    }
    
    
    static function convert_snake_to_camel_case( $snake )
    {
      return lcfirst(str_replace(" ", "", ucwords(str_replace("_", " ", $snake))));
    }
  }

  Script::initialize();
  
  

  class ScriptFlagContext
  {
    function __construct( $name )
    {
      $this->name = $name;
      Script::set($name, true);
    }
    
    function __destruct()
    {
      Script::set($this->name, false);
    }
  }