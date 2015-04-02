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
  // When hooked into CacheConnection's "claim acquired", "claim released", and
  // "claim release in progress" events, logs cache claiming data to the log database:
  //
  // CREATE TABLE `LogClaim` (
  //   `claim_id`           int(11) unsigned NOT NULL AUTO_INCREMENT,
  //   `ws_id`              int(11)          NOT NULL               ,
  //   `ws`                 varchar(50)      NOT NULL DEFAULT ''    ,
  //   `user_id`            int(11)          NOT NULL               ,
  //   `key`                varchar(100)     NOT NULL DEFAULT ''    ,
  //   `timestamp`          datetime         NOT NULL               ,
  //   `completed_u`        decimal(17,6)    NOT NULL               ,
  //   `acquired`           tinyint(4)       NOT NULL               ,
  //   `wait_time_ms`       int(11)          NOT NULL               ,
  //   `initial_blocker_id` int(11)          DEFAULT NULL           ,
  //   `blocker_summary`    text                                    ,
  //   `blocker_log`        text                                    ,
  //   `released`           datetime         DEFAULT NULL           ,
  //   `released_u`         decimal(17,6)    DEFAULT NULL           ,
  //   PRIMARY KEY (`claim_id`)
  // );

  class ClaimTracker
  {
    static function register_event_handlers()
    {
      $instance = new static();
      Script::register_event_handler("claim acquired"           , array($instance, "log_claim"        ));
      Script::register_event_handler("claim released"           , array($instance, "log_release"      ));
      Script::register_event_handler("claim release in progress", array($instance, "log_release_track"));
    }


    function __construct()
    {
      $this->claim_ids      = array();
      $this->release_tracks = array();
    }

    //
    // Logs claim data to the database and updates the claim_keys collection.

    function log_claim( $key, $completed, $acquired, $wait_time, $blocker_log, $blocker_summary )
    {
      if( $log_db = Script::fetch("log_db") )
      {
        $fields = array();
        $fields["ws_id"       ] = Script::get_id();
        $fields["ws"          ] = Script::get_script_name();
        $fields["user_id"     ] = Script::get("user_id", 0);
        $fields["key"         ] = $key;
        $fields["timestamp"   ] = $log_db->format_time($completed);
        $fields["completed_u" ] = sprintf("%.6f", $completed);
        $fields["acquired"    ] = $acquired ? "1" : "0";
        $fields["wait_time_ms"] = ceil($wait_time * 1000);

        if( !empty($blocker_log) )
        {
          reset($blocker_log);
          $first = current($blocker_log);

          $fields["initial_blocker_id"] = $first ? $first->by : null;
          $fields["blocker_summary"   ] = json_encode($blocker_summary);
          $fields["blocker_log"       ] = json_encode($blocker_log    );
        }

        $this->claim_ids[$key] = $log_db->build_and_execute_insert("LogClaim", $fields);
        $this->release_tracks[$key] = array();
      }
    }


    function log_release( $key )
    {
      if( $log_db = Script::fetch("log_db") )
      {
        if( array_key_exists($key, $this->claim_ids) )
        {
          $now      = microtime(true);
          $criteria = array("claim_id" => $this->claim_ids[$key]);
          $fields   = array();
          $fields["released"  ] = $log_db->format_time($now);
          $fields["released_u"] = sprintf("%.6f", $now);

          $log_db->build_and_execute_update("LogClaim", $fields, $criteria);

          unset($this->claim_ids[$key]);
          unset($this->release_tracks[$key]);
        }
      }
    }


    function log_release_track( $key, $stage )
    {
      if( $log_db = Script::fetch("log_db") )
      {
        if( array_key_exists($key, $this->release_tracks) )
        {
          $this->release_tracks[$key][] = $stage;

          $now      = microtime(true);
          $criteria = array("claim_id"      => $this->claim_ids[$key]);
          $fields   = array("release_track" => implode("\n", $this->release_tracks[$key]));

          $log_db->build_and_execute_update("LogClaim", $fields, $criteria);
        }
      }
    }

  }
