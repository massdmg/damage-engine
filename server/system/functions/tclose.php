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

  function &tclose( $base )
  {
    //
    // First, simplify our work by building a map of maps from a map of lists
    // or map of values. This allows us to trivially avoid duplicates.

    $map = array();
    foreach( $base as $key => $values )
    {
      foreach( (array)$values as $value )
      {
        $map[$key][$value] = $value;
      }
    }

    //
    // Next, build the transitive closure by repeatedly adding to each set the sets 
    // each element references, until the closure stabilizes.

    $old_size = $new_size = 0;

    do
    {
      $old_size = $new_size;
      foreach( $map as $key => &$values )
      {
        foreach( $values as $value )
        {
          if( array_has_member($map, $value) )
          {
            foreach( $map[$value] as $add )
            {
              $map[$key][$add] = $add;
            }
          }
        }
      }
    } while( $old_size != ($new_size = array_sum(array_map('count', $map))) );

    //
    // Finally, convert the map of maps back to a map of lists and return it.

    $result = array();
    foreach( $map as $key => $value_map )
    {
      $result[$key] = array_values($value_map);
    }

    return $result;
  }
  