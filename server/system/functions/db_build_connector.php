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
  // Builds a Connector to the database.
  //
  // By default, the environment name is picked up from the DB_ENVIRONMENT configuration
  // parameter. The environment name is then used to retrieve a URL-encoded configuration
  // parameter called DB_CONFIGURATION_<name>, which should have at least some of the following
  // elements:
  //   db      => database name
  //   user    => connection user name
  //   pass    => connection password
  //   master  => the name of a writeable master server
  //   masters => an array of master names, if there are more than one (overrides master)
  //   slave   => the name of a read-only slave server
  //   slaves  => an array of slave names, if there are more than one (overrides slave)
  //
  // For simplicity, if the DB_ENVIRONMENT setting or a specific DB_CONFIGURATION setting is not
  // found, we default to a bare DB_CONFIGURATION setting, and then finally to the standard
  // mysql.default_user, mysql.default_password, and mysql.default_host settings from php.ini.
  //
  // Example 1:
  //   DB_ENVIRONMENT = "prod"
  //   DB_CONFIGURATION_prod  = "db=psc&user=server&pass=abc&master=mysql.myhost.com&slaves[]=slave1.myhost.com&slaves[]=slave2.myhost.com"
  //   DB_CONFIGURATION_local = "db=psc&user=server&pass=abc&master=:/tmp/mysql.sock"
  //   DB_CONFIGURATION_other = "db=psc&user=server&pass=abc&master=mysql.myhost.com:9389"
  //
  // Example 2:
  //   DB_CONFIGURATION = "db=psc&user=server&pass=abc&master=mysql.myhost.com&slaves[]=slave1.myhost.com&slaves[]=slave2.myhost.com"

  function db_build_connector( $statistics_collector = null, $series = "DB" )
  {
    $connector = null;
    $series    = strtoupper($series);
    if( $configuration = Conf::get("${series}_CONFIGURATION") )
    {
      $connector = MySQLConnector::build($configuration);
    }

    return $connector;
  }
