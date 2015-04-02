<?php if (defined($inc = "ENGINE_LOGGING_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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


  function log_to_game( $level, $severity, $args )
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
    
    $message   = ob_get_clean();
    $signature = capture_trace_signature((!empty($args) && is_a($args[0], "Exception")) ? $args[0] : null);

    error_log(sprintf("ERROR IN %s:%d\n%s", Script::get_script_name(), Script::get_id(), $message));
    Script::signal("logging_to_game", $level, $severity, $message, $signature);
  }

  Script::register_event_handler("log", "log_to_game");
  

  //
  // Email errors to devs.
  
  function notify_support_about_log_message( $level, $severity, $message, $signature )
  {
    $subject = sprintf("%s condition in %s", strtoupper($level), Script::get_script_name());
    
    if( $level == "critical" )
    {
      notify_support($subject, $message);
    }
    elseif( $severity >= Logger::level_warning )  // Try to coordinate messaging with other servers, to reduce traffic
    {
      $interval  = Conf::get("MAIL_SUPPORT_INTERVAL_$signature") or $interval = Conf::get("MAIL_SUPPORT_INTERVAL");
      $interval  = max(Logger::level_error / $severity, 1) * max((int)$interval, ONE_MINUTE);
      $cache     = null;
      $cache_key = "notifying_support_about_$signature";
      
      try
      {
        $ds    = Script::get("ds", null) or throw_exception("unable_to_get_ds"                 );
        $cache = @$ds->cache             or throw_exception("unable_to_get_cache_from_ds"      );
        $cache->claim($cache_key)        or throw_exception("unable_to_claim_notification_lock");
        
        $last = $occurrences = 0;
        $data = @$cache->get($cache_key) and @list($last, $occurrences) = explode("|", $data);
        
        $last        = (int)$last;
        $occurrences = (int)$occurrences;
        $notified    = false;
        
        if( !$last || ($diff = time() - (int)$last) >= (int)$interval )
        {
          if( notify_support_if_reasonable($subject, $message, $signature) )
          {
            $notified = true;
          }
        }
        elseif( Features::enabled("log_support_not_notified") )
        {
          error_log("SUPPORT NOT NOTIFIED (cache says " . max($interval - $diff, 1) . " second(s) too soon)");
          error_log($subject);
        }
        
        if( $notified )
        {
          $last        = time();
          $occurrences = 0;
        }
        else
        {
          $occurrences += 1;
        }

        @$cache->set($cache_key, "$last|$occurrences");
        @$cache->release($cache_key);
      }
      catch( Exception $e )
      {
        $cache and @$cache->release($cache_key);
        notify_support_if_reasonable($subject, $message, $signature);
      }
    }
  }
  
  Script::register_event_handler("logging_to_game", "notify_support_about_log_message", $priority = 1);
  
  
  //
  // Send alerts to the log db.
  
  function log_message_to_db( $level, $severity, $message, $signature )
  {
    if( $log_db = Script::get("log_db") )
    {
      try
      {
        $data = array();
        $data["user_id"       ] = Script::get("user_id", -1);
        $data["level"         ] = $level;
        $data["message"       ] = $message;
        $data["client_version"] = Script::get("client_version", null);
        $data["ws"            ] = Script::get_script_name();
        $data["ws_id"         ] = Script::get_id();
        $data["timestamp"     ] = $log_db->format_time();
        $data["signature"     ] = $signature;

        $log_db->insert_into("ServerAlertLog", $data);
      }
      catch( Exception $e )
      {
        // Eat it
      }
    }
  }
  
    Script::register_event_handler("logging_to_game", "log_message_to_db");
  


  
//=================================================================================================
// SECTION: ENVIRONMENT SET UP


  //
  // Ensure uncaught exceptions are logged to the alert table.

  function dump_exception_to_log( $exception )
  {
    if( $exception )  
    {
      try 
      { 
        if( is_a($exception, "GameException") )
        {
          Logger::log($exception->get_effective_level(), $exception);
        }
        else
        {
          Logger::log(Logger::level_error, $exception);
        }
      } 
      catch( Exception $e ) {}
    }
  }
  
  function dump_exception_to_log_and_exit( $exception )
  {
    dump_exception_to_log($exception);
    exit;
  }

  Script::register_event_handler("killed_by_uncaught_exception", "dump_exception_to_log_and_exit", $priority = 99999);   // Should be last to run; preempts report_uncaught_exception_to_support()


  //
  // Set up event handlers.

  if( Conf::get("CACHE_CONNECTION_LOGGING") )
  {
    ClaimTracker::register_event_handlers();
  }



//=================================================================================================
// SECTION: LOG THE EXECUTION


  //
  // Log the starting of the script and set up to log the finish. We don't do this earlier 
  // because this is the first database-related 

  $query = "INSERT INTO ServerExecutionLog SET timestamp = now(), index_s = ?, index_u = ?, app = ?, service = ?, client_ip = ?, client_version = ?, client_platform = ?, server_ip = ?";
  Script::set("script_id", $log_db->insert($query, Script::$start_time, Script::$start_micros, APPLICATION_NAME, Script::$script_name, $client_ip, $client_version, $client_platform, $_SERVER["SERVER_ADDR"]));

  function log_script_summary()
  {
    $script_id        = Script::get_id();
    $log_db           = Script::fetch("log_db");
    $user_id          = Script::get("user_id"         , null);
    $duration         = Script::get("duration"        ,    0);  $response_time    = ceil($duration         * 1000);
    $response_size    = Script::get("response_size"   ,    0);  
    $db_reads         = Script::get("db_reads"        ,    0);
    $db_writes        = Script::get("db_writes"       ,    0);
    $db_time          = Script::get("db_time"         ,  0.0);  $db_time          = ceil($db_time          * 1000);
    $cache_attempts   = Script::get("cache_attempts"  ,    0);
    $cache_hits       = Script::get("cache_hits"      ,    0);
    $cache_writes     = Script::get("cache_writes"    ,    0);
    $cache_wait_time  = Script::get("cache_wait_time" ,  0.0);  $cache_wait_time  = ceil($cache_wait_time  * 1000);
    $cache_claim_time = Script::get("cache_claim_time",  0.0);  $cache_claim_time = ceil($cache_claim_time * 1000);
    $peak_memory      = Script::get("peak_memory_usage",   0);
    $tag              = Script::get("execution_tag"   , null);

    $query = "UPDATE ServerExecutionLog SET user_id = ?, response_time_ms = ?, response_size = ?, db_reads = ?, db_writes = ?, db_time_ms = ?, cache_attempts = ?, cache_hits = ?, cache_writes = ?, cache_wait_time_ms = ?, cache_claim_time_ms = ?, peak_memory_usage = ?, tag = ? WHERE ws_id = ?";
    $log_db->execute($query, $user_id, $response_time, $response_size, $db_reads, $db_writes, $db_time, $cache_attempts, $cache_hits, $cache_writes, $cache_wait_time, $cache_claim_time, $peak_memory, $tag, $script_id);

    if( Features::enabled("profiling") )
    {
      $query = "INSERT INTO DebugExecutionReportLog (ws_id, report) VALUES (?, ?)";
      @$log_db->execute($query, $script_id, Script::report("json"));

      if( Features::enabled("profiling_detail") )
      {
        try
        {
          $query = "INSERT INTO DebugExecutionTimingLog (ws_id, `index`, elapsed_ms, label, notes) VALUES (?, ?, ?, ?, ?)";
          foreach( (array)Script::get("timings") as $index => $record )
          {
            @$log_db->execute($query, $script_id, $index, (int)($record->elapsed * 1000), $record->label, $record->get_notes_as_string());
          }
        }
        catch( MySQLConnectionException $e )
        {
          /* ignore it */
        }
      }
    }
  }

  register_teardown_function("log_script_summary");
  
  
  
  
