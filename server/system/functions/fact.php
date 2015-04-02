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

  function fact( $n )
  {
    assert('$n < 172');   // Needs bcmath if you want to go bigger
  
    switch( $n )
    {
      case  0: return 1;
      case  1: return 1;
      case  2: return 2;
      case  3: return 6;
      case  4: return 24;
      case  5: return 120;
      case  6: return 720;
      case  7: return 5040;
      case  8: return 40320;
      case  9: return 362880;
      case 10: return 3628800;
      case 11: return 39916800;
      case 12: return 479001600;
      case 13: return 6227020800;
    
      default:
        $f = 6227020800;
        for( $i = 14; $i <= $n; $i++ )
        {
          $f *= $i;
        }
      
        return $f;
    }
  }
  