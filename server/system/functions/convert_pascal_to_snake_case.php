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
  // Given a string in pascal case, returns one in camel case.

  function convert_pascal_to_snake_case( $identifier )
  {
    $string = strtolower(preg_replace('/([A-Z])/', '_$1', $identifier));
    return substr($string, 0, 1) == "_" ? substr($string, 1) : $string;
  }
