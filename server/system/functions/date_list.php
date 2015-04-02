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
  // Returns a list of dates within the date range. Both end points are included.

  function date_list( $from_date, $to_date )
  {
    $from_time = strtotime("midnight", is_numeric($from_date) ? $from_date : strtotime($from_date));
    $to_time   = strtotime("midnight", is_numeric($to_date  ) ? $to_date   : strtotime($to_date  ));

    $dates = array();
    while( $from_time <= $to_time )
    {
      $dates[] = date("Y-m-d", $from_time);
      $from_time = strtotime("+1 day", $from_time);
    }

    return $dates;
  }
