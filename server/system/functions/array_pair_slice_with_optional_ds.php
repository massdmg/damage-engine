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

  function array_pair_slice_with_optional_ds( $args, $from, $length = null )
  {
    $args  = array_slice($args, $from, $length);
    $ds    = null;
    
    if( $args )
    {
      if( is_object($args[0]) and method_exists($args[0], "reconnect_for_writing") )
      {
        $ds = array_shift($args);
      }
      elseif( $last_index = count($args) - 1 and is_object($args[$last_index]) and method_exists($args[$last_index], "reconnect_for_writing") )
      {
        $ds = array_pop($args);
      }
    }
    
    return array($ds, array_pair($args));
  }
