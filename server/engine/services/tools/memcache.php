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

  Features::disabled("security") or deny_access();
  
  function simplify_response( $json_response )
  {
    $json_response->envelope = $json_response->envelope->response;
    unset($json_response->envelope->system_epoch);
    unset($json_response->envelope->report);
  }
  
  
  $function = Script::get_parameter("function", "");
  $needle   = Script::get_parameter("needle"  , "");
  $cache    = $ds->cache;
  $m        = null;
  
  switch( strtolower($function) )
  {
    case "delete":
      $success = $cache->delete($needle);
      Script::respond_success();
      break;
      
    case "reload":
      $cache->delete($needle);
      /* fall through */

    case "load":
      if( $object = $game->get_named_object($needle) )
      {
        Script::respond_success($object);
      }
      elseif( preg_match('/^([a-z_]+)(\d+)$/', $needle, $m) )
      {
        $class_name = convert_snake_to_pascal_case($m[1]);
        if( $object = $game->get_object($class_name, (int)$m[2]) )
        {
          if( method_exists($object, "get_vital_stats") )
          {
            Script::respond_success($object->get_vital_stats());
          }
          else
          {
            Script::respond_success();
          }
        }
      }
      break;
      
    case "get":
    case "content":
      Script::register_event_handler("sending_json_response", "simplify_response");
      $object = $cache->get($needle) and Script::respond_success($object);
      break;
      
    case "sizeof":
      $memory_before = memory_get_usage();
      if( $object = $cache->get($needle) )
      {
        $memory_after = memory_get_usage();
        $memory_delta = $memory_after - $memory_before;
        Script::respond_success(array("size" => $memory_delta));
      }
      break;
      
    case "dump":
      Script::register_event_handler("sending_json_response", "simplify_response");
      $object = $cache->get($needle) and Script::respond_success(array($needle => capture_var_dump($object)));
      break;
      
    case "set":
      list($key, $value) = explode("=", $needle, 2);
      $decoded = @json_decode($value) and $value = $decoded;
      $cache->set($key, $value) and Script::respond_success($cache->get($key));
      break;
      
    case "list":
      if( Features::debugging_enabled() && ($keys = $cache->get_keys($needle)) )
      {
        Script::respond_success($keys);
      }
      break;
      
    case "clear":
      if( Features::development_mode_enabled() )
      {
        $ds->clear();
        Script::respond_success();
      }
      break;
      
    case "stats":
      if( $stats = $cache->get_stats($needle) )
      {
        Script::respond_success($stats);
      }
      break;

    default:
      throw_exception("function_not_supported", "function", $function);
  }

  $ds->commit();
  Script::respond_failure();
