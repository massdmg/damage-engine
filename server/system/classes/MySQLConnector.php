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
  // Provides a reusable connector for MySQL databases.

  class MySQLConnector
  {
    public  $masters;
    public  $slaves;
    public  $user;
    public  $database;
    private $password;

    static private $connectors;


    //
    // Creates a connector from a URL-encoded descriptor. The following fields are expected (you really
    // should supply at least one master):
    //   db      => database name
    //   user    => connection user name
    //   pass    => connection password
    //   master  => the name of a writeable master server
    //   masters => an array of master names, if there are more than one
    //   slave   => the name of a read-only slave server
    //   slaves  => an array of slave names, if there are more than one

    static function build( $descriptor )
    {
      if( empty(static::$connectors) )
      {
        static::$connectors = array();
      }

      if( !array_key_exists($descriptor, static::$connectors) )
      {
        $details = null;
        parse_str($descriptor, $details);
        if( !empty($details) )
        {
          $user      = $details["user"];
          $pass      = $details["pass"];
          $db        = $details["db"  ];
          $masters   = array_unique(array_merge(array_fetch_value($details, "masters", array()), array_fetch_value($details, "master", array())));
          $slaves    = array_unique(array_merge(array_fetch_value($details, "slaves" , array()), array_fetch_value($details, "slave" , array())));

          static::$connectors[$descriptor] = new static($masters, $slaves, $user, $pass, $db);
        }
      }

      return array_fetch_value(static::$connectors, $descriptor, null);
    }



    function __construct( $masters, $slaves, $user, $password, $database )
    {
      $this->masters  = $masters;
      $this->slaves   = $slaves;
      $this->user     = $user;
      $this->password = $password;
      $this->database = $database;
    }

    function connect_for_writing( $statistics_collector = null )
    {
      return $this->connect($statistics_collector, $for_writing = true);
    }

    function connect_for_reading( $statistics_collector = null )
    {
      return $this->connect($statistics_collector, $for_writing = false);
    }

    function connect( $statistics_collector = null, $for_writing = true )
    {
      $servers  = ($for_writing ? $this->masters  : array_merge($this->masters, $this->slaves));
      $user     = $this->user;
      $pass     = $this->password;
      $db       = $this->database;
      $error    = null;

      if( !empty($servers) )
      {
        shuffle($servers);
        foreach( $servers as $server )
        {
          if( $handle = @mysql_connect($server, $user, $pass, $new_link = true) )    // CP 2013-06 ditched pconnect over poor resource-use: $for_writing ? mysql_connect($server, $user, $pass, true) : mysql_connect($server, $user, $pass, true) )
          {
            if( mysql_select_db($db, $handle) )
            {
              if( mysql_query("set names 'utf8'", $handle) )   // Does not return a resource
              {
                $utc_offset = date('P');
                if( $resource = mysql_query(sprintf("set time_zone = '%s'", $utc_offset)) )   // Put the connection into PHP's time zone; does not return a resource
                {
                  debug("Connected database on $server:$db with tz $utc_offset");
                  return new MySQLConnection($handle, $this, $server, $statistics_collector);
                }
              }
            }
          }
          else
          {
            $error = mysql_error();
          }
        }

      }

      trigger_error("unable to connect to the database [$db]" . ($error ? ": $error" : ""), E_USER_ERROR);
      return null;
    }
  }
