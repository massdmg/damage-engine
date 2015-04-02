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

  require_once "convert_camel_to_snake_case.php";


  //
  // Recursively converts an associative array with camel-case names to an object with snake-case
  // names. Handles arrays of, too -- though you will need to set $hash_levels if using container
  // associative arrays you don't want converted.

  function convert_camel_assoc_to_snake_object( $datum, $hash_levels = 0 )
  {
    if( is_array($datum) )
    {
      $converted = null;
      foreach( $datum as $key => $value )
      {
        if( is_null($converted) )
        {
          $converted = is_numeric($key) || $hash_levels > 0 ? array() : new stdClass;
        }

        if( is_array($converted) )
        {
          $converted[$key] = convert_camel_assoc_to_snake_object($value, $hash_levels - 1);
        }
        else
        {
          $key = convert_camel_to_snake_case($key);
          $converted->$key = convert_camel_assoc_to_snake_object($value, $hash_levels - 1);
        }
      }

      return $converted;
    }
    else
    {
      return $datum;
    }
  }
