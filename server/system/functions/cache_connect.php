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

  Conf::define_term("CACHE_CONFIGURATION", "a comma-separated list of memcache host names to use as the default cache");
  Conf::define_term("CACHE_TIMEOUT"      , "number of seconds to wait for a connection to the server", 1);


  //
  // Opens a connection to the caching system.
  //
  // Retrieves ${series}_CONFIGURATION, optionally specialized by DB_ENVIRONMENT. If no
  // configuration is set, falls back to an in-process cache.
  //
  // Configuration string should be a comma-separated list of memcache host names, if you
  // plan on using memcache. You can include port numbers, if not the default (11211).
  //
  // Example 1:
  //   DB_ENVIRONMENT = "prod"
  //   CACHE_CONFIGURATION_prod  = "cache.myhost.com"
  //   CACHE_CONFIGURATION_other = "cache.myhost.com:84983"
  //   CACHE_CONFIGURATION_other = "cache.myhost.com:84792,secondary_cache.myhost.com"
  //
  // Example 2:
  //   CACHE_CONFIGURATION = "cache.myhost.com"

  function cache_connect( $statistics_collector = null, $series = "CACHE" )
  {
    $handle = null;

    $series = strtoupper($series);
    $configuration = Conf::get("${series}_CONFIGURATION");
    if( !empty($configuration) )
    {
      if( class_exists("Memcache") )
      {
        $servers = explode(",", $configuration);
        shuffle($servers);

        $memcache = new Memcache;
        $retries  = Conf::get("${series}_RETRIES"    , 3);
        $delay    = Conf::get("${series}_RETRY_DELAY", 1);
        $timeout  = Conf::get("${series}_TIMEOUT"    , 1);
        $failure  = null;
        
        while( !$handle && $retries )
        {
          $retries -= 1;
          
          foreach( $servers as $server )
          {
            $server = trim($server);
            if( !empty($server) )
            {
              @list($host, $port) = explode(":", $server);
              $port = $port ? 0 + $port : 11211;
              if( @$memcache->connect($host, $port, $timeout) )
              {
                debug("Connected cache on $host:$port");
                $handle = $memcache;
                break;
              }
            }
          }
          
          if( !$handle && $retries && $delay )
          {
            sleep($delay);
          }
        }

        if( empty($handle) )
        {
          Script::fail("unable_to_connect_memcache", "error", get_last_error());
        }
      }
      else
      {
        Script::fail("php_lacks_memcache");
      }
    }

    $handle or debug("Using in-process cache ONLY");
    $cache = new CacheConnection($handle ? $handle : new InProcessCache(), $statistics_collector);

    if( $handle )
    {
      register_teardown_function(array($cache, "release_all"));
    }

    return $cache;
  }
