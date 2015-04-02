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

  function determine_accepted_languages( $default = "en", $string = null )
  {
    static $parser = "/([[:alpha:]]{1,8})(-([[:alpha:]|-]{1,8}))?(\s*;\s*q\s*=\s*(1(\.0{0,3})?|0(\.\d{0,3})?))?\s*(,|$)/i";
    $string or $string = array_fetch_value($_SERVER, 'HTTP_ACCEPT_LANGUAGE', '');

    $accepted = array(strtolower($default) => 0.001);
    if( preg_match_all($parser, strtolower($string), $hits, PREG_SET_ORDER) )
    {
      foreach( $hits as $hit )
      {
        @list($ignored, $major, $ignored, $minor, $ignored, $value) = $hit;
        if( $major )
        {
          $value = $value ? (float)$value : 1.000;
          if( $minor )
          {
            $accepted[$major . "-" . $minor] = $value;
            $accepted[$major] = max($value * .9, array_fetch_value($accepted, $major, 0));
          }
          else
          {
            $accepted[$major] = max($value, array_fetch_value($accepted, $major, 0));
          }
        }
      }

      arsort($accepted);
    }

    return array_keys($accepted);
  }
