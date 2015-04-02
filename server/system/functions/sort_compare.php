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

  function sort_compare( $a, $b )
  {
    if( $a < $b )
    {
      return -1;
    }
    elseif( $a > $b )
    {
      return 1;
    }
    else
    {
      if( func_num_args() > 2 )
      {
        $args  = func_get_args();
        $chain = array_slice($args, 2);
        
        return call_user_func_array("sort_compare", $chain);
      }
      else
      {
        return 0;
      }
    }
  }
  