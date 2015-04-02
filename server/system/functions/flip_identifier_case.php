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

  require_once "convert_camel_to_snake_case.php";
  require_once "convert_snake_to_camel_case.php";


  //
  // Given a string in camel case, returns one in snake case. Given a string in snake case,
  // returns one in camel case.

  function flip_identifier_case( $identifier )
  {
    if( is_numeric(strpos($identifier, "_")) )
    {
      return convert_snake_to_camel_case($identifier);
    }
    else
    {
      return convert_camel_to_snake_case($identifier);
    }
  }
