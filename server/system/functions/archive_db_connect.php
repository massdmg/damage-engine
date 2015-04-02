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
  // Opens a Connection to the database. Set $shared if you don't need an exclusive connection
  // to the database (and can therefore afford to save some system resources).
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

  function archive_db_connect( $shared = false, $statistics_collector = null, $series = "DB" )
  {    
    $user     = 'psc';
    $pass     = 'redfredbed';
    $server   = 'prod-archive.ceq17vojolhh.us-east-1.rds.amazonaws.com';
    $db       = 'archive';
    $servers  = empty($server) ? array() : array($server);
  
    //
    // Try to override the defaults with data from the DB_CONFIGURATION parameters.

/*
    $series = strtoupper($series);
    $configuration = Conf::get("${series}_CONFIGURATION");
    if( !empty($configuration) )
    {
      $details = null;
      parse_str($configuration, $details);
      if( !empty($details) )
      {
        $user = $details["user"];
        $pass = $details["pass"];
        $db   = $details["db"  ];

        $keys = $shared ? array("slaves", "slave", "masters", "master") : array("masters", "master");
        
        //
        // In order to allow failover of read-only connections to the masters when a slave is
        // not available, we make a long list, shuffled in sections.
        
        $servers = array();
        foreach( $keys as $key )
        {
          if( !empty($details[$key]) )
          {
            $set = is_array($details[$key]) ? $details[$key] : array($details[$key]);
            shuffle($set);
            
            $servers = array_merge($servers, $set);
            break;
          }
        }
      }
    }
*/
    
    //
    // If we have any server options, go with them (chosen in random order, to improve
    // load balancing. We'll try to validate any connection before committing to it.
  
    $last_error = null;
    if( !empty($servers) )
    {
      foreach( $servers as $server )
      {
        if( $handle = mysql_pconnect($server, $user, $pass) )
        {
          if( mysql_select_db($db, $handle) )
          {
            if( $resource = mysql_query("set names 'utf8'", $handle) )
            {
              @mysql_free_result($resource);
              debug("Connected database on $server:$db");
              return new MySQLConnection($handle, $db, $user, $server, $environment, $statistics_collector);
            }
          }
        }
        else
        {
          $last_error = mysql_error();
        }
      }
    }
  
    trigger_error("unable to connect to the database" . ($last_error ? ": $last_error" : ""), E_USER_ERROR);
    return null;
  }


