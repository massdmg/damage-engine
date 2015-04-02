<?php if (defined($inc = "ENGINE_DEVELOPMENT_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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

  function add_player_name_to_script_report( $player )
  {
    Script::set("username", $player->username);
  }

  Script::register_event_handler("session_player_ready", "add_player_name_to_script_report");

  Features::enable("log_response");

  //
  // Update dicts.
  
  // if( $csv_paths = Script::find_system_components_matching("*.csv", GAME_CONFIGURATION_BASE_DIRECTORY) )
  // {
  //   $results = $game->load_changed_dicts_from_csvs($csv_paths, $ds);
  //   $changes = array_filter($results);
  // 
  //   if( !empty($changes) )
  //   {
  //     $ds->commit();
  //     throw_notice("dicts_were_reloaded", "dicts", array_keys($changes));
  //   }
  // }
  // 
