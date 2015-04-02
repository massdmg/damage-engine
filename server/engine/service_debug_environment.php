<?php if (defined($inc = "ENGINE_SERVICE_DEBUG_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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
  // In debug mode, even non-reportable exceptions should be sent to the client via JSON.

  function debug_uncaught_exceptions_via_json( $exception, $dump )
  {
    if( Features::debugging_enabled() && !(is_a($exception, "GameException") && $exception->is_reportable()) )
    {
      $dump_lines = explode("\n", $dump);
      $minidump = $dump_lines[0] . "\n" . implode("\n", array_slice($dump_lines, 3, 6));
      
      $response = Script::$response or $response = new JSONResponse();
      $response->reset($to_empty = true);
      $response->set_status(500);
      $response->set("error_code", is_a($exception, "GameException") ? $exception->identifier : $exception->getCode());
      $response->set("details", "\n" . $dump           );
      $response->add_message($minidump, $translate = false);
      $response->set_in_root("report" , Script::report("array"));
      $response->set_in_root("parameters", $_REQUEST);
      $response->send_failure();
    }
  }

  Script::register_event_handler("killed_by_uncaught_exception", "debug_uncaught_exceptions_via_json", $priority = 10000);  


  //
  // Capture all log messages for output in the response.

  function log_messages_to_script( $level_name, $level_number, $args )
  {
    $first = true;
    ob_start();
    foreach( $args as $arg )
    {
      if ($first) { $first = false; } else { print "\n----\n"; }
      print is_string($arg) ? $arg : capture_var_dump($arg);
    }
    
    if( $severity >= Logger::level_error )
    {
      print "\n----\n";
      print "Request:\n";
      print capture_var_dump($_REQUEST);
      print "\n";
    }
    
    $message = ob_get_clean();

    Script::append("log_messages", $message);
  }

  Script::register_event_handler("log_all", "log_messages_to_script");  

  
  //
  // Finally, dump the request, if appropriate.

  if( Features::enabled("dump_request") )
  {
    dump($_REQUEST);
  }


