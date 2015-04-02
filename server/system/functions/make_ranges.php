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

  function make_ranges( $count, $first, $last, $overlap = 0 )
  {
    $items  = $last - $first + 1;
    $extras = $overlap * ($count - 1);
    $size   = ($items + $extras) / $count;
    $step   = $size - $overlap;

    $ranges = array();
    for( $c = 0; $c < $count; $c++ )
    {
      $range = (object)array('first' => floor($first + $c * $step), 'last' => floor($first + $c * $step + $size) - 1);
      $ranges[] = $range;
    }

    return $ranges;
  }
  