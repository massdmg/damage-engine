<?php if (defined($inc = "ENGINE_GAME_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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
  // Set multi-byte functions to use UTF-8.
  
  if( function_exists("mb_internal_encoding") )
  {
    mb_internal_encoding("UTF-8");  // encoding for data
    mb_regex_encoding("UTF-8");     // encoding for the regex as specified in code
  }




//=================================================================================================
// SECTION: Define the business logic.

  define("SYSTEM_RESOURCES_URL", (defined("BASE_URL") ? BASE_URL : "") . "/system/resources/");
  define("ENGINE_RESOURCES_URL", (defined("BASE_URL") ? BASE_URL : "") . "/engine/resources/");

  
  //
  // Load the system environment.

  require_once __DIR__ . "/../system/system_environment.php";
  require_once "db_build_connector.php"  ;
  require_once "db_connect.php"          ;
  require_once "cache_connect.php"       ;
  require_once "simplify_script_name.php";


  define("GAME_CONFIGURATION_BASE_DIRECTORY", Conf::get("GAME_CONFIGURATION_BASE_DIRECTORY", path("../config")));
  define("GAME_CONFIGURATION_DIRECTORY"     , Conf::get("GAME_CONFIGURATION_DIRECTORY", GAME_CONFIGURATION_BASE_DIRECTORY . "/" . APPLICATION_NAME));

  
  
  
//=================================================================================================
// SECTION: Define core functions for the game environment

  function now( $offset = null )
  {
    is_numeric($offset) or $offset = strtotime($offset);
    return date("Y-m-d H:i:s", $offset < (25 * ONE_YEAR) ? time() + $offset : $offset);
  }


  function now_u( $offset = null )
  {
    list($partial, $seconds) = explode(" ", microtime());
    $micros = substr($partial, 2);

    return sprintf("%s.%s", now($offset), $micros);
  }
  
  
  function deny_access()
  {
    warn("Access denied to " . Script::$script_name);
    
    header("HTTP/1.0 403 Forbidden");
    exit;
  }


  //
  // Convenience wrapper on throw GameException::build(). Any GameException for which there is a 
  // translation will go to the user, in addition to any logging the system chooses to do. 

  function throw_exception()
  {
    $args = func_get_args();
    is_bool($args[0]) and array_shift($args);   // discard vestigial prefix reportable flag
    throw GameException::build($args);
  }
  
  
  function throw_alert()   { throw GameException::build(func_get_args(), Logger::level_critical); }
  function throw_error()   { throw GameException::build(func_get_args(), Logger::level_error   ); }
  function throw_warning() { throw GameException::build(func_get_args(), Logger::level_warning ); }
  function throw_notice()  { throw GameException::build(func_get_args(), Logger::level_notice  ); }
  
  function throw_internal_exception() { throw GameException::build(func_get_args()); }   // DEPRECATED: use throw_exception()
  function throw_security_exception() { throw GameException::build(func_get_args()); }   // DEPRECATED: use throw_exception()
  
  
  //
  // Allow run-time configuration to alter GameException effective logging level.
  
  function filter_game_exception_effective_level( $level, $identifier, $exception )
  {
    if( $new = Conf::get("REPORT_{$identifier}_AT") )
    {
      if( $value = Logger::get_level_by_name($new) )
      {
        $level = $value;
      }
    }
    
    return $level;
  }
  
  Script::register_filter("game_exception_effective_level", "filter_game_exception_effective_level");


  //
  // Configure the Script with the basics.

  global $script_name;
  Script::set_script_name(isset($script_name) ? $script_name : simplify_script_name());
  Script::register_handler("script_failing", "throw_exception");


  //
  // Ensure we have a timezone.

  Conf::define_term("TZ", "the default timezone for date_default_timezone_set(), if date.timezone is not set in php.ini", "America/Toronto");
  ini_get("date.timezone") or date_default_timezone_set(Conf::get("TZ"));


  //
  // Figure out as much client information as we can. The User-Agent header would be the preferable
  // source for this info, but the client doesn't support setting it, so we are SOL.

  global $client_version, $client_platform, $client_ip;
  $client_version = $client_platform = $client_ip = null;

  if( $match = Script::filter_parameter("client_version", '/^(\d+\.\d+\.\d+)$/') )
  {
    $client_version = $match;
  }
  elseif( $match = Script::filter_parameter("client_version", '/^(\d+\.\d+)$/') )
  {
    $client_version = $match . ".0";
  }

  if( Script::has_parameter("client_platform") )
  {
    $client_name     = Script::get_parameter("client_platform", "ios");
    $client_platform = (stripos($client_name, "android") !== false ? "android" : "ios");
  }
  elseif( $ua = @$_SERVER["HTTP_USER_AGENT"] )
  {
    $client_platform = (stripos($ua, "android") !== false || stripos($ua, "unity") !== false) ? "android" : "ios";
  }
    
  $client_ip = @$_SERVER["REMOTE_ADDR"];
  $client_platform or $client_platform = "ios";
  $client_version  or $client_version  = "0.0.0";

  Script::set("client_platform", $client_platform);
  Script::set("client_version" , $client_version );




//=================================================================================================
// SECTION: Configure debugging and related features.

  function register_debug_logging_controls( $inclusions, $exclusions = array() )
  {
    if( is_string($inclusions) )
    {
      if( trim($inclusions) == "*" )
      {
        Features::enable("debug_logging");
        $inclusions = array();
      }
      else
      {
        list($on, $off) = parse_switches_csv($inclusions);
        $inclusions = $on;
        $exclusions = array_merge($exclusions, $off);
      }
    }
    
    Script::concat("debug_logging_inclusions", $inclusions);
    Script::concat("debug_logging_exclusions", $exclusions);
  }
  
  function filter_debug_logging_enabled( $should )
  {
    if( !$should && ($inclusions = Script::get("debug_logging_inclusions")) )
    {
      $exclusions = array_diff((array)Script::get("debug_logging_exclusions"), $inclusions);

      $trace = null; try { throw new Exception(); } catch( Exception $e ) { $trace = $e->getTrace(); }
      foreach( $trace as $level )
      {      
        $function = $level["function"];
        array_key_exists("class", $level) and $function = $level["class"] . "::$function";

        foreach( $exclusions as $symbol )
        {
          if( strpos($function, $symbol) === 0 )
          {
            break 2;
          }
        }

        foreach( $inclusions as $symbol )
        {
          if( strpos($function, $symbol) === 0 )
          {
            return true;
          }
        }
      }
    }

    return $should;
  }
  
  
  //
  // Configure debugging.
  
  Script::register_filter("debug_logging_enabled", "filter_debug_logging_enabled");
  Script::concat("debug_logging_exclusions", array("Script::signal", "Schema::get_table"));  // Disable unwanted detours and noise

  if( Features::debugging_enabled() )
  {
    assert_options(ASSERT_ACTIVE, 1);

    if( $log_inside = Script::get_parameter("log_inside") )
    {
      register_debug_logging_controls($log_inside);
    }
    else
    {
      Features::disable("debug_logging");
    }
    
    //
    // Log statistics to the error log, if debugging.

    register_teardown_function(array("Script", "report_to_debug_log"));
  }
  else
  {
    assert_options(ASSERT_ACTIVE, 0);
  }
  
  
  //
  // For debugging purposes.

  if( Features::enabled("log_response") )
  {
    function log_response_to_db( $text )
    {
      global $ds;
      
      try
      {
        $ds2 = $ds->reconnect_for_writing();
        $ds2->insert("INSERT INTO DebugResponseLog (script_id, script_name, response_text, request_json) VALUES (?, ?, ?, ?)", Script::get_id(), Script::get_script_name(), substr($text, 0, 100000), @json_encode($_REQUEST));
        $ds2->commit();
        $ds2->close();
      }
      catch( Exception $e )
      {
        warn("ignoring exception during response logging: " . $e->getMessage());
      }

      return $text;
    }

    Script::register_filter("response_text", "log_response_to_db");
  }



  


//=================================================================================================
// SECTION: Connect to data sources.

  global $ds, $log_db;  
  $ds = new DataSource
  (
    cache_connect($statistics_collector = "Script"), 
    db_build_connector($statistics_collector = "Script", $series = "db"),
    $for_writing = true, 
    $statistics_collector = "Script", 
    $on_abort = Callback::for_function("throw_exception", "service_failed")
  );

  if( Features::enabled("separate_log_db_connection") )
  {
    $log_db = db_connect($statistics_collector = null, $series = "db", $shared = false);   // As we have spare servers sitting about, we don't want persistent connections  
  }
  else
  {
    $log_db = $ds->db;
  }
  
  Script::set("log_db", $log_db);
  Script::set("ds"    , $ds    );

  register_teardown_function(Callback::for_method($ds, "discard"));
      
  


//=================================================================================================
// SECTION: Configure database magic.

  //
  // Set up unpacking for any "extra" field in a query result. If the body is identifiably json or
  // name-value pairs, they will be broken out and expanded into the row.

  require_once "annotations.php"                ;
  require_once "unpack_main_database_fields.php";
  require_once "pack_main_database_fields.php"  ;
  require_once "pack_sql_statement_fields.php"  ;
  require_once "fill_in_log_record.php"         ;

  Script::register_filter("query_result"        , "unpack_main_database_fields");
  Script::register_filter("data_handler_fields" , "pack_main_database_fields"  );
  Script::register_filter("sql_statement_fields", "pack_sql_statement_fields"  );
  Script::register_filter("missing_log_field"   , "fill_in_log_record"         );




//=================================================================================================
// SECTION: Initialize logging.

  require_once path("logging_environment.php", __FILE__);




//=================================================================================================
// SECTION: Start the GameEngine.

  global $game;
  $game = Script::set("game", new GameEngine($ds));


  //
  // Bail out for database-controlled system maintenance mode. You really should use Features 
  // instead.

  if( Features::enabled("db_system_maintenance") && $game->get_parameter("SYSTEM_MAINTENANCE", false) )
  {
    $until = @strtotime($game->get_parameter("SYSTEM_MAINTENANCE_END"));
    Script::respond_unavailable("System maintenance under way. Please try again later.\n", $until ? $until - time() : null);
  }


  //
  // Hook the game into the event system.

  $game->register_subsystem_event_handlers_and_filters();


  //
  // Replace the system-wide $ds with one age-limited on "everything".

  global $ds;
  $ds = Script::set("ds", $game->limit_data_source_age_by($ds, "everything"));
  
  
  //
  // Load the development environment before marking the system booted, in case it wants to 
  // customize anything.

  if( Features::enabled("development_mode") )
  {
    require_once path("development_environment.php", __FILE__);
  }
  
  function postpone()
  {
    if( !Features::enabled("development_mode") )
    {
      $args = func_get_args();
      call_user_func_array("abort", $args);
    }
  }



  //
  // Let the world know.
  
  Script::signal("game_booted");


