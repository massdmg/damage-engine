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
  // array must be of objects / maps, each of which has a 'weight' property / key
  //
  // function returns one key chosen from a uniform distributino across the keys by weight

  function array_fetch_random_weighted_value( &$array )
  {
    if( !empty($array) )
    {
      $total_weight = 0;
      foreach( $array as $element )
      {
        $weight = is_array($element) ? (isset($element['weight']) ? $element['weight'] : 0) : (isset($element->weight) ? $element->weight : 0);
        $total_weight += max(0, $weight);
      }

      if( $total_weight <= 0 )
      {
        return null;
      }

      $roll = mt_rand(0, $total_weight-1);

      foreach( $array as $key => $element )
      {
        $weight = is_array($element) ? (isset($element['weight']) ? $element['weight'] : 0) : (isset($element->weight) ? $element->weight : 0);
        $roll -= max(0, $weight);
        if( $roll < 0 )
        {
          return $element;
        }

      }
    }

    return null;
  }
