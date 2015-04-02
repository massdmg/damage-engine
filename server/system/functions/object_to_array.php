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
  // Recursively converts (the public members of) an object into a tree of associative arrays.
  // You can optionally pass a conversion routine to be applied to each key (useful from
  // converting between naming schemes).

  function object_to_array( $object, $key_converter = null )
  {
    $array = array();
    foreach( $object as $key => $value )
    {
      $key_converter and $key = $key_converter($key);
      $array[$key] = is_object($value) ? object_to_array($value) : $value;
    }

    return $array;
  }
