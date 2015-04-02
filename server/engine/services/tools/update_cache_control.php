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
  
  $aspect = Script::get_parameter("aspect");
  $create = Script::get_parameter("create_if_missing", false);  // true|false (defaults to false)
  
  $aspect and $ds->execute("INSERT INTO ServerCacheControlData (aspect, cutoff) VALUES (?, now()) ON DUPLICATE KEY UPDATE cutoff = now()", $aspect);

  $ds->delete("CacheControl");
  $ds->commit();

  $game = Script::set("game", new GameEngine($ds));
  $game->register_subsystem_event_handlers_and_filters();
  
  $object = (object)null;
  $aspect and $object->$aspect = $game->get_named_object($aspect);
  $object->cache_control = $game->cache_control;

  Script::respond_success($object);
