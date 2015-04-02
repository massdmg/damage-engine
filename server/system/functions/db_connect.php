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

  require_once "db_build_connector.php";


  //
  // Opens a Connection to the database. Set $shared if you don't need an exclusive connection
  // to the database (and can therefore afford to save some system resources).
  //
  // See db_build_connector() for configuration documentation.

  function db_connect( $statistics_collector = null, $series = "DB", $shared = false )
  {
    if( $connector = db_build_connector($statistics_collector, $series) )
    {
      return $connector->connect($statistics_collector, $for_writing = !$shared);
    }

    return null;
  }
