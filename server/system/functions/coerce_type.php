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

  function coerce_type( $value, $exemplar )
  {
    if( !is_null($exemplar) )
    {
      if( is_array($exemplar) )
      {
        is_array($value) or $value = (array)$value;
      }
      elseif( is_bool($exemplar) )
      {
        $lower = strtolower($value);
        
        if( $value === "1" || $value === "true" || $lower === "on" || $lower === "yes" )
        {
          $value = true;
        }
        elseif( $value === "0" || $value === "false" || $lower === "off" || $lower === "no" )
        {
          $value = false;
        }
        else
        {
          $value = (bool)$value;
        }
      }
      elseif( is_float($exemplar) )
      {
        $value = (float)$value;
      }
      elseif( is_int($exemplar) )
      {
        $value = (integer)$value;
      }
      elseif( is_string($exemplar) && empty($value) )
      {
        $value = "";
      }
      elseif( is_object($exemplar) )
      {
        $value = (object)$value;
      }
    }

    return $value;
  }
