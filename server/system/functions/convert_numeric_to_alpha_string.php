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
  // Given an arbitrary-length number in some base (10 or 16, for instance), returns an alphabetical
  // (base 26) version of the same number.

  function convert_numeric_to_alpha_string( $number, $base = 10 )
  {
    $chr_offset = ord("a") - 1;
    $number = (string)$number;

    //
    // First, convert the $number string to a BCMath integer.

    $int    = 0;
    $length = strlen($number);
    for( $i = 0; $i < $length; $i++ )
    {
      $nibble = base_convert($number[$i], $base, 10);
      $int    = bcadd(bcmul($int, $base), $nibble);
    }

    //
    // Then, convert the $int string to an $alpha string.

    $alpha = "";
    while( bccomp($int, $zero = '0', $scale = 0) > 0 )
    {
      $remainder = intval(bcmod($int, 26));
      $alpha     = $remainder == 0 ? "z" : chr($remainder + $chr_offset) . $alpha;
      $int       = bcdiv($int, 26, $scale = 0);
    }

    return empty($alpha) ? "z" : $alpha;
  }
