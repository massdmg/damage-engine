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

  function array_map_through_method( $array, $method )
  {
    $mapped = array();
    if( func_num_args() > 2 )
    {
      $args = func_get_args();
      $args = array_slice($args, 2);

      foreach( $array as $key => $entry )
      {
        $mapped[$key] = call_user_func_array(array($entry, $method), $args);
      }
    }
    else
    {
      foreach( $array as $key => $entry )
      {
        $mapped[$key] = $entry->$method();
      }
    }

    return $mapped;
  }