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

  require path("sort_by_count_asc.php", __FILE__);

  function eliminate_proper_subsets( &$sets, $assoc = false )
  {
    if( $assoc )
    {
      uasort($sets, "sort_by_count_asc");
      reset($sets);

      $keys = array_keys($sets);
      for( $i = 0; $i < count($keys); )
      {
        $key = $keys[$i];
        $set =& $sets[$key];

        for( $j = $i + 1; $j < count($keys); $j++ )
        {
          if( count($sets[$keys[$j]]) > count($set) )
          {
            break;
          }
        }

        for( ; $j < count($keys); $j++ )
        {
          $intersection = array_intersect_assoc($set, $sets[$keys[$j]]);
          if( count($intersection) == count($set) )
          {
            unset($sets[$key]);
            $keys = array_keys($sets);
            continue 2;
          }
        }

        $i++;
      }
    }
    else
    {
      abort("NYI");
    }
  }
