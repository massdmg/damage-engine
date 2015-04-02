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
  // For JSON services, this is the standard response, capable of capturing all of the common
  // elements in a way that helps ensure quality operation. Subclass this to add additional
  // data (or just add to an instance directly).

  class JSONResponse extends HTTPResponse
  {
    function __construct( $script_name = null, $content_type = "application/json" )
    {
      parent::__construct($content_type);
      $this->script_name = $script_name or $this->script_name = Script::get_script_name();
      $this->contexts    = array();
      $this->reset();
    }


    //
    // Resets the response to its empty state.

    function reset()
    {
      $game = Script::fetch("game");

      isset($this->envelope) or $this->envelope = (object)array("request" => $this->script_name, "response" => null);

      $this->envelope->request     = $this->script_name;
      $this->envelope->response    = (object)array("success" => true, "content" => (object)null);
      $this->envelope->server_time = now();
      $this->payload = $this->envelope->response;
      $this->content = $this->payload->content;
    }


    function mark_deprecated( $message )
    {
      if( Features::debugging_enabled() )
      {
        $this->set("DEPRECATED", $message);
      }
    }




  //===============================================================================================
  // SECTION: Response construction.


    //
    // Causes any set/append/merge/increment until the matching end_redirect_into() to be written
    // into the response at $path (offset by any previous redirect).

    function begin_redirect_into( $path )
    {
      $this->contexts[] = $this->get_current_path($path);
    }


    //
    // Ends the last redirect. $path must match what you passed to begin_redirect_into().

    function end_redirect_into( $path )
    {
      assert('$this->get_current_path() == $this->get_previous_path($path)');
      array_pop($this->contexts);
    }


    //
    // Gets the value at $in.$path.

    function &get( $path, $in = "" )
    {
      $ref =& $this->find($in . "." . $path);
      return $ref;
    }
    

    //
    // Sets $value at $in.$path. Replaces any old value.

    function set( $path, $value, $in = "" )
    {
      $ref =& $this->find($in . "." . $path);
      $ref = $value;
    }

    function set_content( $path, $value )
    {
      return $this->set($path, $value, $in = "content");
    }
    
    function set_in_root( $element, $value )
    {
      $this->envelope->$element = $value;
    }

    function delete( $path, $in = "" )
    {
      abort("this was broken on last test");
      $path = $in . "." . $path;
      $ref =& $this->find($in . "." . $path);
      unset($ref);
    }


    //
    // Appends $value to an array at $path. [] is added to $path if not present.

    function append( $path, $value, $in = "" )
    {
      $path = $in . "." . $path;
      $array =& $this->find(substr($path, -2) == "[]" ? $path : $path . "[]");
      $array[] = $value;
    }

    function append_content( $path, $value, $in = "" )
    {
      return $this->append("content." . $path, $value, $in);
    }


    //
    // Merges the data from the supplied object into the container at $path.

    function merge( $data, $path )
    {
      foreach( $data as $name => $value )
      {
        $this->set($name, $value, $path);
      }
    }

    function merge_content( $data, $path = "" )
    {
      return $this->merge($data, "content.$path");
    }


    //
    // Increments $in.$path by $value (can be negative).

    function increment( $path, $value = 1, $in = "" )
    {
      $ref =& $this->find("$in.$path");
      if( is_numeric($ref) )
      {
        $ref += $value;
      }
      else
      {
        $ref = $value;
      }
    }


    function increment_content( $path, $value = 1 )
    {
      return $this->increment_content($path, $value, $in = "content");
    }


    //
    // Returns a referency to an element within the response. Any element suffixed [] will ensure
    // that an array is available at that location.
    //
    // Examples:
    //  ""                -- response
    //  "content"         -- response.content
    //  "content.x"       -- response.content.x[] -- converts it to an array, if necessary
    //  "content.x[].10   -- response.content.x[10]
    //  "content.x[].10.y -- resposne.content.x[10].y
    //  "."               -- response
    //  "content."        -- response.content
    //  ".content...x"    -- response.content.x

    function &find( $path )
    {
      $container = $this->payload;
      $path = $this->get_current_path($path);
      if( !empty($path) )
      {
        foreach( explode(".", $path) as $step )
        {
          if( $step != "" )
          {
            $expect_array = false;
            if( substr($step, -2) == "[]" )
            {
              $expect_array = true;
              $step = substr($step, 0, -2);
            }

            //
            // Try to figure out the type of object.

            if( !is_array($container) )
            {
              $container = (object)$container;
              if( property_exists($container, $step) )
              {
                if( $expect_array && is_scalar($step) )
                {
                  $container->$step = (array)$container->$step;
                }
              }
              else
              {
                $container->$step = $expect_array ? array() : (object)null;
              }

              $container =& $container->$step;
            }
            else
            {
              if( array_key_exists($step, $container) )
              {
                if( $expect_array && is_scalar($step) )
                {
                  $container[$step] = (array)$container[$step];
                }
              }
              else
              {
                $container[$step] = $expect_array ? array() : (object)null;
              }

              $container =& $container[$step];
            }
          }
        }
      }

      return $container;
    }





  //===============================================================================================
  // SECTION: Response construction helpers.

    //
    // Adds a text message to the content. This is primarily for sending error messages in the
    // content.

    function add_message( $message, $parameters = array(), $translate = true )
    {
      if( is_bool($parameters) )
      {
        $translate  = $parameters;
        $parameters = array();
      }
      
      if( $translate )
      {
        $game    = Script::fetch("game");
        $message = $game->translate($message, $parameters);
      }

      $this->append("messages", $message);
    }


    //
    // Adds a DialogueChain to the response.

    function add_dialogue( $chain )
    {
      if( is_string($chain) )
      {
        if( $found = Script::fetch("game")->dialogue_manager->get_chain_by_name($chain) )
        {
          $chain = $found;
        }
      }

      if( is_string($chain) )
      {
        $acceptable_languages = Script::fetch("game")->string_manager->get_client_language_preferences_in_string();
        warn("dialogue translation unavailable: $chain in any of [$acceptable_languages]");
        Script::$response->add_to_script("advise", (object)array("message" => "dialogue_translation_unavailable", "chain" => $chain));
      }
      else
      {
        $this->add_to_script("dialogue", $chain);
      }
    }


    function add_to_script( $op, $data )
    {
      $this->append("script[]", array("op" => $op, "data" => $data));
    }


    function add_menu_of_matching_scripts( $pattern, $directory, $query_string, $exclude = null )
    {
      $this->envelope->menu = array();

      foreach( glob(path($pattern, $directory)) as $path )
      {
        if( $path !== $exclude )
        {
          $basename = basename($path, ".php");
          $bucket   = convert_snake_to_pascal_case(basename(dirname($path)));
          $script   = convert_snake_to_pascal_case($basename);

          $this->envelope->menu[] = (object)array("href" => "/$bucket/$script?o_O&$query_string", "title" => str_replace("_", " ", $basename));
        }
      }
    }




  //===============================================================================================
  // SECTION: Send functionality


    //
    // Sends this response to the user via Service.

    function send( $success = null, $sender = null )
    {
      $sender or $sender = Script::$class;

      if( !is_null($success) )
      {
        if( is_scalar($success) )
        {
          $this->set("success", (bool)$success);
        }
        else
        {
          $this->set("success", true);

          //
          // If we were passed something that has already wrapped up the content, unpack it.

          if( is_object($success) && isset($success->request) && isset($success->response) )
          {
            $success = $success->response;
          }
          elseif( is_array($success) && array_key_exists("request", $success) && array_key_exists("response", $success) )
          {
            $success = $success["response"];
          }

          //
          // Copy over the data.

          foreach( $success as $name => $value )
          {
            $this->set_content($name, $value);
          }
        }

      }

      if( Features::debugging_enabled() )
      {
        $this->set_in_root("report", Script::report("array"));
      }

      Script::signal("sending_json_response", $this);

      parent::send(json_encode($this->envelope), $sender);

      Script::signal("json_response_sent", $this);
    }


    //
    // Moves any $data into the payload content and sends it with success = true.

    function send_success( $data = null, $sender = null )
    {
      $data or $data = true;
      $this->send($data, $sender);
    }


    //
    // Clears the response of non-keepers and adds the passed exception, error code, or message
    // instead, then sends it with success = false.

    function send_failure( $error = null, $http_status = null, $sender = null )
    {
      if( is_object($error) )
      {
        if( method_exists($error, "add_to_json_response") )
        {
          $error->add_to_json_response($this);
        }
        elseif( is_a($error, "Exception") )
        {
          $this->set("error_code", $error->getCode());
          $this->add_message($error->getMessage(), $translate = false);
        }
      }
      elseif( $error )
      {
        $this->set("error_code", $error);
        $this->add_message($error);
      }
      
      if( (int)$http_status )
      {
        $this->set_status($http_status);
      }

      $this->send($success = false, $sender);
    }


    function send_deprecated( $message, $sender = null )
    {
      $this->mark_deprecated($message);
      $this->send_failure($error = "service_deprecated", $http_status = "403 Service Deprecated", $sender);
    }

    
    function send_unavailable( $message = "", $until = null )
    {
      $this->set_status("503 Service Unavailable");
      $until and $this->add_header("Retry-After", $until);
      parent::send($message);
    }
    
    



  //===============================================================================================
  // SECTION: Internals.


    protected function get_current_path( $offset = null )
    {
      $depth = count($this->contexts);
      return $this->make_path($depth <= 0 ? "" : $this->contexts[$depth - 1], $offset);
    }


    protected function get_previous_path( $offset = null )
    {
      $depth = count($this->contexts);
      return $this->make_path($depth <= 1 ? "" : $this->contexts[$depth - 2], $offset);
    }


    protected function make_path( $base, $rest )
    {
      if( empty($base) || $base == "." )
      {
        return $rest;
      }
      elseif( empty($rest) || $rest == "." )
      {
        return $base;
      }
      else
      {
        return $base . "." . $rest;
      }
    }


  }
