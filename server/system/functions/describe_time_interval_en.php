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

  require_once "breakdown_time_interval.php";

  function describe_time_interval_en( $seconds, $max_precision = 2 )
  {
    $pieces    = array();
    $started   = false;
    $precision = 0;

    foreach( breakdown_time_interval($seconds) as $interval => $count )
    {
      if( $count > 0 )
      {
        $started = true;

        $count == 1 and $interval = substr($interval, 0, -1);
        $pieces[] = sprintf("%d %s", $count, $interval);
      }

      if( $started )
      {
        $precision += 1;
        if( $precision == $max_precision )
        {
          break;
        }
      }
    }

    return implode(", ", $pieces);
  }
