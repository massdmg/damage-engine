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
  // Base class for things that convert one object into another (generally simpler) object, usually
  // by removing fields.

  class Projector
  {
    function __construct()
    {
      
    }
    
    function project( $object )
    {
      return $object ? clone $object : null;
    }
    
    static function project_once()
    {
      $projector = new static();
      return $projector->project_with(func_get_args());
    }
    
    function project_with( $args )
    {
      return call_user_func_array(array($this, "project"), $args);
    }

    function get_options( $args, $first = 1 )
    {
      return array_pair(array_slice($args, $first));
    }

  }
  