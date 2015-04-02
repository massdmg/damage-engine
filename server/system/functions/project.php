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
  // Given a single or list of objects and list of property names, returns a single or list of
  // objects containing only those properties. Any properties that don't exist on the supplied
  // object will be returned as null.

  function project( $inputs )
  {
    $outputs       = array();
    $i_love_php    = func_get_args();
    $it_is_so_wise = array_slice($i_love_php, 1);
    $inclusions    = array_flatten($it_is_so_wise);
    $singular      = !is_array($inputs);

    is_array($inputs) or $inputs = array($inputs);
    foreach( $inputs as $input )
    {
      $outputs[] = $output = (object)null;
      foreach( $inclusions as $name )
      {
        $output->$name = $input->$name;
      }
    }

    return $singular ? $outputs[0] : $outputs;
  }

