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

  require_once "tree_fetch.php";
  require_once "coerce_type.php";

  function object_fetch_property( $object, $property, $default = null )
  {
    $value = $default;

    if( is_object($object) )
    {
      if( isset($object->$property) )
      {
        $value = coerce_type($object->$property, $default);
      }
    }
    else
    {
      $value = tree_fetch($object, $property, $default);
    }

    return $value;
  }
