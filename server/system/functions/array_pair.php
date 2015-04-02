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
  // Returns a map produced by using alternating elements for key and value. If the last
  // (unpaired) value in the array is a map or object, it will be used as defaults for
  // any pair not already set.

  function &array_pair( $array )
  {
    is_scalar($array) and $array = func_get_args();
    
    $pairs = array();
    while( !empty($array) )
    {
      if( count($array) == 1 and array_key_exists(0, $array) and (is_null($array[0]) or is_array($array[0]) or is_object($array[0])) )
      {
        $tail  = array_shift($array);
        $pairs = array_merge(is_object($tail) ? get_object_vars($tail) : (array)$tail, $pairs);
      }
      else
      {
        $key   = array_shift($array);
        $value = @array_shift($array);

        $pairs[(string)$key] = $value;
      }
    }

    return $pairs;
  }