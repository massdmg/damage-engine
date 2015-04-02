<?php if (defined($inc = "SYSTEM_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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


   isset($_SERVER["REDIRECT_URL"]) or $_SERVER["REDIRECT_URL"] = $_SERVER["SCRIPT_NAME"];
   
   if( !defined("DOCUMENT_ROOT_MADE_REAL") )
   {
     $_SERVER["DOCUMENT_ROOT"] = realpath($_SERVER["DOCUMENT_ROOT"]);
     define("DOCUMENT_ROOT_MADE_REAL", true);
   }




//=================================================================================================
// SECTION: COMPONENT LOADING ROUTINES AND SETUP

  //
  // Converts a relative path to an absolute one (generally __FILE__). Operates
  // relative the API base dir, if $relative_to is null.

  function path( $path, $relative_to = null )
  {
    empty($relative_to) and $relative_to = $_SERVER["DOCUMENT_ROOT"];

    $dirname = dirname($relative_to);
    $path = $dirname . "/" . $path;

    while( is_numeric(strpos($path, "/../")) )
    {
      $path = preg_replace("/[^\/]+\/\.\.\//", "", $path, 1);
    }

    return $path;
  }
  
  
  function parse_switches_csv( $csv )
  {
    $on  = array();
    $off = array();
    foreach( preg_split('/,\s*/', $csv) as $command )
    {
      if( substr($command, 0, 1) == "!" )
      {
        $off[] = substr($command, 1);
      }
      else
      {
        $on[] = $command;
      }
    }
      
    return array($on, $off);
  }
  
  
  //
  // Load the basics.
  
  require_once path("system/classes/Features.php");
  require_once path("system/classes/Conf.php"    );
  require_once path("system/classes/Script.php"  );
  
  
  //
  // Configure features.
  
  Conf::define_term("FEATURES", "a comma-separated list of feature names to enable or (!) disable", "production_mode, security, !debugging");
  
  if( $string = Conf::get("FEATURES") )
  {
    list($on, $off) = parse_switches_csv($string);
    Features::enable_all($on);
    Features::disable_all($off);
  }


  //
  // Determine the application name. We'll need it for the ClassLoader (and other things).
  
  Conf::define_term("APPLICATION_NAME", "a (preferably short) name used to identify the application's resource in a shared environment", "app");
  define("APPLICATION_NAME", Conf::get("APPLICATION_NAME"));
  
  
  //
  // Bail out if addressed in the wrong protocol.
  
  if( Features::enabled("https_only") && $_SERVER["SERVER_PORT"] != 443 )
  {
    Script::respond_forbidden("HTTPS only");
  }
  
  
  //
  // Bail out if system_maintenance mode is enabled.
  
  if( Features::enabled("system_maintenance") )
  {
    $until = @strtotime(Conf::get("SYSTEM_MAINTENANCE_END"));
    Script::respond_unavailable("System maintenance under way. Please try again later.\n", $until ? $until - time() : null);
  }
  
  //
  // Set up the ClassLoader.

  require_once path("system/classes/ClassLoader.php");

  function register_class_path( $path, $append = true )
  {
    ClassLoader::add_directory($path, $append);
  }

  if( !function_exists("__autoload") )
  {
    function __autoload( $class_name )
    {
      if( ClassLoader::load_class($class_name) )
      {
        return true;
      } 
      elseif( error_reporting() )
      {
        Script::fail("class_not_found", array("class_name", $class_name));
      } 

      return false;
    }
  }

  function safe_require_once( $path )
  {
    ob_start();
    require_once $path;

    $filth = ob_get_clean();
    if( Features::enabled("debugging") && strlen($filth) > 0 )
    {
      warn("$path outputs data during loading:", capture_var_dump($filth));
      abort("$path outputs data during loading");
    }
  }


  //
  // Registers include paths for simplified includes and class loading. Automatically
  // registers path/classes as a class path, if it exists. Won't register any path that
  // doesn't exist.

  global $include_paths;
  $include_paths = array_filter(explode(PATH_SEPARATOR, get_include_path()));
  
  function register_include_path( $path, $append = true, $register_class_path = false )
  {
    global $include_paths;
    if( file_exists($path) )
    {
      if( !in_array($path, $include_paths) )
      {
        if( file_exists($functions_path = "$path/functions") )
        {
          $append ? array_push($include_paths, $functions_path) : array_unshift($include_paths, $functions_path);
        }
        else
        {
          $append ? array_push($include_paths, $path) : array_unshift($include_paths, $path);
        }
        
        set_include_path(implode(PATH_SEPARATOR, $include_paths));

        if( $register_class_path )
        {
          if( file_exists("$path/classes") )
          {
            register_class_path("$path/classes", $append);
            file_exists($current = "$path/exceptions") and register_class_path($current, $append);
            file_exists($current = "$path/templates" ) and register_class_path($current, $append);
            file_exists($current = "$path/projectors") and register_class_path($current, $append);
          }
          else
          {
            register_class_path($path, $append);
          }
        }
      }
    }
  }


  //
  // Set up the system component search path.
  
  if( file_exists($components_path = $_SERVER["DOCUMENT_ROOT"] . "/system_components.php") )
  {
    require_once $components_path;
    if( defined("SYSTEM_COMPONENTS") )
    {
      Script::set_system_component_names(explode(",", SYSTEM_COMPONENTS));
    }
  }


  //
  // Register the system component paths, if available.

  if( $paths = Script::get_system_component_paths() )
  {
    foreach( $paths as $path )
    {
      register_include_path($path, $append = true, $register_class_path = true);
    }      
  }
  else
  {
    register_include_path( path("../", __FILE__) );

    register_class_path( path("classes"   , __FILE__) );
    register_class_path( path("exceptions", __FILE__) );
  }
  



//=================================================================================================
// SECTION: TEAR DOWN ROUTINES AND SETUP

  //
  // The PHP register_shutdown_function() is a great idea, but unfortunately runs things in FIFO
  // order. This is pretty much useless for anything in the real world. So we create a parallel
  // system that operates FILO.

  global $teardown_functions, $in_teardown;
  $teardown_functions = array();
  $in_teardown = false;

  function register_teardown_function( $callback )
  {
    global $teardown_functions;
    $teardown_functions[] = $callback;
  }

  function tear_down()
  {
    global $teardown_functions, $in_teardown;

    $in_teardown = true;
    while( !empty($teardown_functions) )
    {
      if( $callback = array_pop($teardown_functions) )
      {
        set_time_limit(3);
        @Callback::do_call($callback);
      }
    }
  }

  function in_teardown()
  {
    global $in_teardown;
    return !!$in_teardown;
  }

  register_shutdown_function("tear_down");




//=================================================================================================
// SECTION: MISCELLANEOUS UTILITY ROUTINES

  function flush_output_buffers()
  {
    $length = 0;
    while( ($current = ob_get_length()) !== false )
    {
      if( Features::enabled("debugging") )
      {
        $length += $current;
        @ob_end_flush();
      }
      else
      {
        @ob_end_clean();
      }
    }

    return $length;
  }

  function discard_output_buffers()
  {
    while( @ob_end_clean() );
  }


  //
  // A convenient version of Conf::get(), included mostly for historical reasons.

  function conf_get( $name /* more names, $default */ )
  {
    return Conf::get($name, func_num_args() > 1 ? array_pop(func_get_args()) : null);
  }
  
  
  function &parse_query_string( $string )
  {
    $map = array();
    parse_str($string, $map);
    return $map;
  }


  function order_ascending( $a, $b )
  {
    return ($a == $b) ? 0 : ($a < $b ? -1 : 1);
  }
  
  function order_descending( $a, $b )
  {
    return ($a == $b) ? 0 : ($a < $b ? 1 : -1);
  }
  
  




//=================================================================================================
// SECTION: DEBUGGING ROUTINES

  function abort()
  {
    $args   = func_get_args();
    $reason = (count($args) > 0 && (is_string($args[0]) || is_null($args[0]))) ? array_shift($args) : null;
    $data   = $args;

    @header("Content-Type: text/plain");
    try
    {
      throw new Exception("ABORTED" . ($reason ? ": $reason" : ""));
    }
    catch( Exception $e )
    {
      for( $i = 0; $i < count($data); $i++ )
      {
        $name = "p$i";
        @$e->$name = $data[$i];
      }

      report_uncaught_exception_and_exit($e);
    }
  }

  function enable_notices()
  {
    error_reporting(E_ALL | E_STRICT);
  }

  function disable_notices()
  {
    $current = error_reporting();    
    $current & E_NOTICE      and $current = $current ^ E_NOTICE     ;
    $current & E_USER_NOTICE and $current = $current ^ E_USER_NOTICE;
    $current & E_STRICT      and $current = $current ^ E_STRICT     ;
    
    error_reporting($current);
  }
  
  function disable_strict()
  {
    $current = error_reporting();
    $current & E_STRICT and $current = $current ^ E_STRICT;
    
    error_reporting($current);
  }
  
  
  //
  // Returns var_dump() in a string. As this routine has the potential to crash the process with
  // excessive memory use, we try to at least mitigate that risk by not blindly copying. If the output
  // hits $limit, you'll receive your $discarded message instead.
  
  function capture_var_dump( $value, $limit = 5000000, $discarded = "TOO LARGE TO VAR_DUMP" )
  {
    if( is_object($value) && is_a($value, "Exception") )
    {
      return format_exception_data($value);
    }
    else
    {
      ob_start();
      var_dump($value);

      if( $limit and ob_get_length() > $limit )
      {
        ob_end_clean();    // Sadly, there is no way to get a part of the buffer, so we just have to discard it.
        print $discarded;
      }
      
      return ob_get_clean();
    }
  }
  
  
  //
  // Returns a formatted stack trace at the current execution point.
  
  function capture_trace()
  {
    try
    {
      throw new Exception();
    }
    catch( Exception $e )
    {
      return format_exception_trace($e);
    }
  }
  
  
  //
  // Returns a SHA1 hash (hopefully) unique to this point in the call chain.
  
  function capture_trace_signature( $exception = null )
  {
    if( !$exception )
    {
      try { throw new Exception(); } catch( Exception $e ) { $exception = $e; }
    }
    
    $trace  = $exception->getTrace();
    $levels = array();
    $leader = "";

    if( $pos = strripos(__FILE__, "/system/system_environment.php") )
    {
      $leader = substr(__FILE__, 0, $pos);
    }
    
    foreach( $trace as $level )
    {
      $function = @$level["function"];
      array_key_exists("class", $level) and $function = $level["class"] . "::" . $function;
      
      $file = @$level["file"] and strpos($file, ".php") and $file = @realpath($file);
      $leader and $file = str_ireplace($leader, "", $file);
      
      $levels[] = sprintf("%s() at %s(%d)", $function, $file, @$level["line"]);
    }
    
    return hash("sha1", implode("\n", $levels));
  }

  
  
  //
  // Formats an exception trace for display.
  
  function format_exception_trace( $exception )
  {
    $trace = $exception->getTraceAsString();

    //
    // Strip out any unnecessary path information.

    if( $leader = strripos(realpath(__FILE__), "/system/system_environment.php") )
    {
      $leader = substr(realpath(__FILE__), 0, $leader);
      $trace  = str_ireplace($leader, "", $trace);
    }

    //
    // For the sake of readability, break up the trace lines.

    $lines        = explode("\n", $trace);
    $locations    = array();
    $descriptions = array();

    if( count($lines) > 50 ) 
    {
      $lines = array_slice($lines, 0, 50);
      $lines[] = "...: [truncated]";
    }

    foreach( $lines as $line )
    {
      @list($location, $description) = explode(": ", $line    , 2);
      @list($number  , $location   ) = explode(" " , $location, 2);

      if( empty($description) || trim($description) == "" )
      {
        break;
      }

      $locations[]    = $location;
      $descriptions[] = $description;
      
    }
    
    $width = max(array_map("strlen", $locations));

    ob_start();
    while( !empty($locations) )
    {
      $location    = array_shift($locations);
      $description = array_shift($descriptions);

      printf(" %-${width}s: %s\n", $location, $description);
    }

    return ob_get_clean();
  }


  function format_exception_data( $exception, $preamble = "Error: " )
  {
    static $depth = 0; $depth += 1;
    if( $depth > 5 )
    {
      $depth -= 1;
      return "RECURSION DETECTED: format_exception_data failed\n";
    }
        
    ob_start();
    print $preamble;
    print $exception->getMessage();
    print "\n\n";
    print "Trace:\n";
    print format_exception_trace($exception);

    $first = true;
    foreach( $exception as $property => $value )
    {
      if( $first )
      {
        print "\n";
        $first = false;
      }

      print "\n$property:\n";
      if( is_scalar($value) )
      {
        print $value;
        print "\n";
      }
      elseif( is_object($value) && is_a($value, "Exception") )
      {
        if( spl_object_hash($exception) == spl_object_hash($value) )
        {
          print "==RECURSION==\n";
        }
        else
        {
          print format_exception_data($value);
        }
      }
      else
      {
        print capture_var_dump($value, $limit = 1000000, $discarded = "==TOO LARGE TO VAR_DUMP==\n");
      }
    }

    $depth -= 1;
    return ob_get_clean();
  }

  
  

//=================================================================================================
// SECTION: LOGGING ROUTINES


  //
  // Logs data at various levels, to the error_log (at least). Sends strings verbatim, dumps everything else.
  // If you need a string dumped, pass the dump.

  require_once path("system/classes/Logger.php");

  function alert() { Logger::log_with_args(Logger::level_critical, func_get_args()); }  
  function warn()  { Logger::log_with_args(Logger::level_warning , func_get_args()); }
  function note()  { Logger::log_with_args(Logger::level_debug   , func_get_args()); }
  function dump()  { Logger::log_with_args(Logger::level_debug   , func_get_args()); }

  function alert_with_trace() { Logger::log_with_args_and_trace(Logger::level_critical, func_get_args()); }
  function error_with_trace() { Logger::log_with_args_and_trace(Logger::level_error   , func_get_args()); }
  function warn_with_trace()  { Logger::log_with_args_and_trace(Logger::level_warning , func_get_args()); }
  function note_with_trace()  { Logger::log_with_args_and_trace(Logger::level_debug   , func_get_args()); }
  function dump_with_trace()  { Logger::log_with_args_and_trace(Logger::level_debug   , func_get_args()); }

  function abort_if_in_development_mode_else_alert( $message )
  {
    Logger::log_with_args_and_trace(Logger::level_error, func_get_args());
    if( Features::enabled("development_mode") )
    {
      abort($message);
    }
  }

  //
  // Logs a debug message IFF in debug mode at the current position.

  function debug()
  {
    $enabled = Features::enabled("debugging") || Features::enabled("debug_logging");
    if( $enabled && !Features::disabled("debug_logging") && Script::filter("debug_logging_enabled", Features::enabled("debug_logging")) )
    {
      Features::disable("debugging");
      try { Logger::log_with_args(Logger::level_debug, func_get_args()); } catch (Exception $e) {}
      $enabled and Features::enable("debugging");
    }
  }

  //
  // Logs a debug message with trace IFF in debug mode at the current position.

  function debug_with_trace()
  {
    $enabled = Features::enabled("debugging") || Features::enabled("debug_logging");
    if( $enabled && !Features::disabled("debug_logging") && Script::filter("debug_logging_enabled", Features::enabled("debug_logging")) )
    {
      Features::disable("debugging");
      try { Logger::log_with_args_and_trace(Logger::level_debug, func_get_args()); } catch (Exception $e) {}
      $enabled and Features::enable("debugging");
    }
  }




//=================================================================================================
// SECTION: MAIL TO SUPPORT STAFF

  //
  // Make it easy to email the support personnel (useful for error handling).

  Conf::define_term("MAIL_SUPPORT_FROM", "email address"    , ""                     );
  Conf::define_term("MAIL_SUPPORT_TO"  , "email address(es)", "awsalarms@massdmg.com");

  function notify_support( $subject, $body, $preamble = "" )
  {
    $notified = false;
    $preamble === false or $preamble = sprintf("%s%sScript ID: %s\nServer IP: %s\n\n", $preamble, $preamble ? "\n" : "", Script::get_id(), $_SERVER["SERVER_ADDR"]);
    
    if( $from = Conf::get("MAIL_SUPPORT_FROM") )
    {
      if( $to = Conf::get("MAIL_SUPPORT_TO") )
      {
        $headers = "From: $from\r\n";
        error_log("SUPPORT NOTIFIED BY MAIL:");
        error_log($subject);
        $notified = mail($to, sprintf("[%s %s] %s", APPLICATION_NAME, Conf::get("DB_ENVIRONMENT"), $subject), str_replace("\n", "\r\n", $preamble . $body), $headers);
      }
      elseif( Features::enabled("log_support_not_notified") )
      {
        error_log("SUPPORT NOT NOTIFIED (no \"to\" address):");
        error_log($subject);
      }
    }
    elseif( Features::enabled("log_support_not_notified") )
    {
      error_log("SUPPORT NOT NOTIFIED (no \"from\" address):");
      error_log($subject);
    }
    
    return $notified;
  }


  //
  // Make it easy to email support personnel on a recurring basis.

  Conf::define_term("MAIL_SUPPORT_INTERVAL", "minimum seconds between emails for the same signature", 600);

  function notify_support_if_reasonable( $subject, $body, $signature = null, $preamble = "" )
  {
    $signature or $signature = capture_trace_signature();

    $notified = false;
    $reason   = "too soon";
    $path     = "/tmp/" . APPLICATION_NAME . ".last_mailed";
    $tracker  = $path . "/" . $signature;
    $interval = Conf::get("MAIL_SUPPORT_INTERVAL_$signature") or $interval = Conf::get("MAIL_SUPPORT_INTERVAL");
    
    @mkdir($path, $mode = 0755, $recursive = true);
    if( !file_exists($tracker) || ($diff = time() - (int)filectime($tracker)) >= (int)$interval )
    {
      $reason = "not trackable";
      if( touch($tracker) )
      {
        $reason = "not notifiable";
        $notified = notify_support($subject, $body, $preamble = "Signature: $signature");
      }
    }
    else
    {
      $reason = max($interval - $diff, 1) . " second(s) too soon";
    }

    if( !$notified and Features::enabled("log_support_not_notified") )
    {
      error_log("SUPPORT NOT NOTIFIED ($reason):");
      error_log($subject);
    }

    return $notified;
  }




//=================================================================================================
// SECTION: ERROR HANDLING ROUTINES


  //
  // Dumps an exception to the error log and standard output and exits. Sends an HTTP 500 instead
  // if not debug mode.
  //
  // Signals:
  //   signal("dying_from_uncaught_exception", $exception)       -- exit to prevent any uncaught exception processing
  //   signal("killed_by_uncaught_exception", $exception, $dump) -- exit to prevent the default response processing

  function report_uncaught_exception_and_exit( $exception )
  {
    Script::signal("dying_from_uncaught_exception", $exception);
    Script::signal("killed_by_uncaught_exception", $exception, $dump = format_exception_data($exception, sprintf("ERROR in %s: ", Script::get_script_name())));

    error_log(Script::get_script_name() . " DIED FROM UNCAUGHT EXCEPTION: " . $exception->getMessage());
    exit;
  }
  
  
  function report_uncaught_exception_to_client( $exception, $dump )
  {
    if( Script::get("response_size", 0) == 0 )
    {
      if( Features::enabled("debugging") )
      {      
        @header("Content-type: text/plain");
        if( flush_output_buffers() )
        {
          print "\n\n";
          print str_repeat("-", 80);
          print "\n\n";
        }

        print $dump;
      }
      else
      {
        discard_output_buffers();
        @header("HTTP/1.0 500 Service failed");
      }
    }
  }
  
  Script::register_event_handler("killed_by_uncaught_exception", "report_uncaught_exception_to_client", 25000);


  function report_uncaught_exception_to_support( $exception, $dump )
  {
    @notify_support_if_reasonable(Script::get_script_name() . " died of an uncaught exception", $dump, capture_trace_signature($exception));
  }
  
  Script::register_event_handler("killed_by_uncaught_exception", "report_uncaught_exception_to_support", 100000);
  

  //
  // Throws an ErrorException for the supplied PHP trigger_error() data.

  function convert_errors_to_exceptions( $errno, $errstr, $errfile = null, $errline = null, $errcontext = null )
  {
    if( $level = error_reporting() )
    {
      if( $level & $errno and strpos($errstr, "Indirect modification") === false )    // We are expressly ignoring this one because it's spurious
      {
        error_log("THROWING ErrorException: $errstr");
        throw new ErrorException($errstr, $code = 0, $severity = $errno, $errfile, $errline);
      }
    }
    elseif( $errno & (E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING) )  // because, for whatever reason, error_get_last() doesn't seem to work reliably
    {
      $GLOBALS["last_error"] = array("type" => $errno, "message" => $errstr, "file" => $errfile, "line" => $errline);
    }
  }

  function get_last_error()
  {
    return @$GLOBALS["last_error"];
  }




//=================================================================================================
// SECTION: COMPLETE THE RUNTIME ENVIRONMENT


  //
  // Set up error handling.

  set_error_handler("convert_errors_to_exceptions", E_ALL | E_STRICT);
  set_exception_handler('report_uncaught_exception_and_exit');
  
  
  //
  // Set up the logging environment.
  
  Conf::define_term("LOGGING_LEVEL", "error, warn, info, or debug", "error");
  Logger::$level = Features::enabled("debugging") ? Logger::level_debug : Logger::set_level_by_name(Conf::get("LOGGING_LEVEL"));
  

  //
  // Load various globally useful functions.

  require_once "functions/set_content_type.php";
  require_once "functions/is_called_from.php";
  require_once "functions/array_fetch_value.php";
  require_once "functions/array_has_member.php";
  require_once "functions/array_flatten.php";
  require_once "functions/array_pair.php";
  require_once "functions/array_pair_slice.php";
  require_once "functions/object_fetch_property.php";
  require_once "functions/structure_fetch_value.php";
  require_once "functions/tree_has.php";
  require_once "functions/tree_fetch.php";
  require_once "functions/limit_to_range.php";
  require_once "functions/is_in_range.php";
  

  //
  // Enable garbage collection.

  gc_enable();


  //
  // Ensure all scripts run to completion, regardless of user behaviour.

  ignore_user_abort(true);


  //
  // Configure error reporting levels.

  if( Features::enabled("debugging") )
  {
    ini_set("display_errors", "1");
  }

  enable_notices();
  
  
  //
  // Fix $_REQUEST, if PHP decided to break it
  
  if( ini_get("request_order") != "CPG" )
  {
    $_REQUEST = array_merge($_COOKIE, $_POST, $_GET);
  }
  
  
  if( !Script::was_called_as_get() )
  {
    header("Cache-Control: no-cache");
  }


  //
  // Define some useful time constants.

  define('ONE_SECOND'  ,   1             );
  define('ONE_MINUTE'  ,  60             );
  define('ONE_HOUR'    ,  60 * ONE_MINUTE);
  define('THREE_HOURS' ,   3 * ONE_HOUR  );
  define('SIX_HOURS'   ,   6 * ONE_HOUR  );
  define('TWELVE_HOURS',  12 * ONE_HOUR  );
  define('ONE_DAY'     ,  24 * ONE_HOUR  );
  define('THREE_DAYS'  ,   3 * ONE_DAY   );
  define('ONE_WEEK'    ,   7 * ONE_DAY   );
  define('ONE_MONTH'   ,  30 * ONE_DAY   );
  define('ONE_YEAR'    , 365 * ONE_DAY   );

  define('FAR_FUTURE'       , '2037-01-01 00:00:00');
  define('DISTANT_PAST'     , '2000-01-01 00:00:00');
  define('TIMESTAMP_PATTERN', '/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/');
      
  //
  // And, finally, a new value "keyword", for E_STRICT nanny purposes when adding required parameters 
  // to a subclassed method. Because PHP won't tell you about a likely typo in a variable name unless
  // it also gets to tell you you wouldn't like programming in Java.

  define('required', null);

