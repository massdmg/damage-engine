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


  require_once "convert_snake_to_camel_case.php";

  //
  // Recursively converts an object with snake-case names to an associative array with camel-case
  // names. Handles arrays of, too.

  function convert_snake_object_to_camel_assoc( $datum )
  {
    if( is_object($datum) || (is_array($datum) && (reset($datum) || true) && is_string(key($datum))) )
    {
      $converted = array();
      foreach( $datum as $key => $value )
      {
        $converted[convert_snake_to_camel_case($key)] = convert_snake_object_to_camel_assoc($value);
      }

      return $converted;
    }
    elseif( is_array($datum) )
    {

      $converted = array();
      foreach( $datum as $key => $value )
      {
        $converted[$key] = convert_snake_object_to_camel_assoc($value);
      }

      return $converted;
    }
    else
    {
      return $datum;
    }
  }
