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
  // Given a string in snake case, returns one in camel case.

  function convert_snake_to_camel_case( $identifier, $exceptions = null )
  {
    if( strpos($identifier, "_") !== false && $identifier == strtolower($identifier) ) // Make sure it really is snake_case, to minimize accidental changes
    {
      $words = explode("_", $identifier);

      if( is_null($exceptions) )
      {
        global $camel_case_conversion_exceptions;
        if( !is_null($camel_case_conversion_exceptions) )
        {
          $exceptions =& $camel_case_conversion_exceptions;
        }
      }

      ob_start();
      print array_shift($words);
      foreach( $words as $word )
      {
        $converted = ucfirst($word);
        print (empty($exceptions) || !array_key_exists($converted, $exceptions)) ? $converted : $exceptions[$converted];
      }
      $converted = ob_get_clean();
      return (empty($exceptions) || !array_key_exists($converted, $exceptions)) ? $converted : $exceptions[$converted];
    }
    else
    {
      return $identifier;
    }
  }
