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
  // A log stored in a database table. The primary requirement is that the records have a timestamp
  // we can sort/filter on. Provides query production services (only).

  class DBLog
  {
    function __construct( $name, $timestamp_field = "timestamp" )
    {
      $this->name = $name;
      $this->timestamp_field = $timestamp_field;
    }

    //
    // Returns a WHERE clause expression limiting the results to a date or range of dates.

    function make_criteria( $db, $alias, $from_date, $to_date = null )
    {
      if( empty($to_date) ) { $to_date = $from_date; }

      $from_time = strtotime("midnight", is_numeric($from_date) ? $from_date : strtotime($from_date));
      $to_time   = strtotime("23:59:59", is_numeric($to_date  ) ? $to_date   : strtotime($to_date  ));

      return sprintf("%s%s between '%s' and '%s'", $alias ? "$alias." : "", $this->timestamp_field, $db->format_date($from_time), $db->format_date($to_time));
    }


    //
    // Returns the DML to delete records in the given date range (NB: dates, not times).

    function make_delete( $db, $from_date, $to_date = null )
    {
      return "DELETE FROM $this->name WHERE " . $this->date_criteria($db, "", $from_date, $to_date);
    }


    //
    // Returns a SELECT * query for records in the given date range (NB: dates, not times).

    function make_select( $db, $from_date, $to_date = null )
    {
      return "SELECT * FROM $this->name WHERE " . $this->date_criteria($db, "", $from_date, $to_date);
    }


  }
