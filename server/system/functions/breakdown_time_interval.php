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

  function breakdown_time_interval( $seconds )
  {
    $years   = 0;
    $days    = 0;
    $hours   = 0;
    $minutes = 0;

    if( $seconds >= ONE_YEAR )
    {
      $years   = floor($seconds / ONE_YEAR);
      $seconds = $seconds - ($years * ONE_YEAR);
    }

    if( $seconds >= ONE_DAY )
    {
      $days    = floor($seconds / ONE_DAY);
      $seconds = $seconds - ($days * ONE_DAY);
    }

    if( $seconds >= ONE_HOUR )
    {
      $hours   = floor($seconds / ONE_HOUR);
      $seconds = $seconds - ($hours * ONE_HOUR);
    }

    if( $seconds >= ONE_MINUTE )
    {
      $minutes = floor($seconds / ONE_MINUTE);
      $seconds = $seconds - ($minutes * ONE_MINUTE);
    }

    return array("years" => $years, "days" => $days, "hours" => $hours, "minutes" => $minutes, "seconds" => $seconds);
  }


