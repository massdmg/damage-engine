<?php if (defined($inc = "ENGINE_HTML_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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

  require_once __DIR__ . "/game_environment.php";  
  require_once "html.php";
  

  

//=================================================================================================
// SECTION: HTML environment.

  global $title;
  Script::$response = new HTMLResponse($game->translate($title));
  
  

  //
  // Send uncaught GameExceptions directly to the user and end the script, without
  // a dump to the alert or error log, as they really aren't programming failures.

  function handle_uncaught_reportable_exceptions( $exception )
  {
    if( is_a($exception, "GameException") && $exception->is_reportable() )
    {
      debug(Script::$script_name . " RETURNING ERROR: ", $exception);
      Script::signal("request_failed", $exception->identifier, $exception);

      $response = Script::$response;
      $response->reset();
      $response->add_body_element(div(".error", $exception->get_message()));
      $response->send();
      exit;
    }
  }

  Script::register_event_handler("dying_from_uncaught_exception", "handle_uncaught_reportable_exceptions");
  


  //
  // Give em some sugar.
  
  if( !function_exists("t") )
  {
    function t( $name, $parameters = array() )
    {
      return Script::fetch("game")->translate($name, $parameters);
    }
  }

