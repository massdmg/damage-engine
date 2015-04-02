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
  
  $name = Script::get_parameter("name");
  $dump = Script::get_parameter("dump", false);    // if set, dumps the structure instead; defaults to false
  
  $object = $game->get_named_object($name);
  if( !$object && substr($name, 0, 7) == '$game->' )
  {
    if( $path = array_slice(explode("->", $name), 1) )
    {
      $object = $game;
      foreach( $path as $leg )
      {
        if( preg_match('/^\w+$/', $leg) )
        {
          $object = @$object->$leg;
        }
        else
        {
          $object = null;
        }
      } 
    }
  }
  
  $object or throw_exception("not_found");

  Script::respond_success(array("name" => $name, "object" => $dump ? capture_var_dump($object) : $object));
