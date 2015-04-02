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
  // Reverses convert_number_to_alpha_string().

  function convert_alpha_to_numeric_string( $string, $base = 10 )
  {
    $ord_offset = ord("a") - 1;
    $string     = strtolower($string);

    //
    // First, convert the $number string to a BCMath integer.

    $int = "0";
    $length = strlen($string);
    for( $i = 0; $i < $length; $i++ )
    {
      $letter = $string[$i];
      $value = $letter == "z" ? 0 : (ord($letter) - $ord_offset);
      $int   = bcadd(bcmul($int, 26), $value);
    }

    //
    // Then, convert the $int string to a $base number.

    $converted = "";
    while( bccomp($int, $zero = '0', $scale = 0) > 0 )
    {
      $remainder = intval(bcmod($int, $base));
      $converted = base_convert($remainder, $from_base = 10, $base) . $converted;
      $int       = bcdiv($int, $base, $scale = 0);
    }

    return $converted;
  }
