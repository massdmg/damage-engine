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
  // Provides managed access to dynamically-generate code elements at run time. It's essentially
  // a cache, but isn't network-enabled.

  class CodeLibrary
  {
    function __construct( $age_limit = 0, $prefix = "", $storage_path = "/tmp" )
    {
      $this->age_limit               = $age_limit;
      $this->storage_path            = $storage_path;
      $this->prefix                  = $prefix;
      $this->prefix_dot              = empty($prefix) ? "" : sprintf("%s.", $prefix);
    }
    

    //
    // Gets the named object from the library, provided it is not stale. Returns null otherwise.

    function get( $name, $age_limit = ONE_YEAR )
    {
      debug("LIBRARY GET: $name");
      $age_limit and $age_limit = CacheConnection::determine_age_limit($age_limit, $this->age_limit);
      if( $age_limit > 0 )
      {
        $path = $this->make_path($name);
        if( file_exists($path) && filectime($path) > $age_limit )
        {
          if( $serialized = file_get_contents($path) )
          {
            if( $object = @unserialize($serialized) )
            {
              debug("LIBRARY GOT: $name");
              return $object;
            }
          }
        }
      }

      return null;
    }


    //
    // Sets the object into the library.

    function set( $name, $value )
    {
      if( $value )
      {
        $path = $this->make_path($name);
        if( $serialized = @serialize($value) )
        {
          $temp_path = sprintf("%s.%s", $path, getmypid());
          if( file_put_contents($temp_path, $serialized, LOCK_EX) )
          {
            chmod($temp_path, 0666);
            rename($temp_path, $path);
            debug("LIBRARY SET: $name");
          }
        }
      }

      return $value;
    }
    
    
    function invalidate( $key )
    {
      $this->delete($key);
    }
    
    
    //
    // Deletes an object from the library, if present.

    function delete( $key, $retrieve = false )
    {
      $result = $retrieve ? $this->get($key) : null;
      
      $path = $this->make_path($key);
      @unlink($path);

      return $result;
    }

    
    
    //
    // Gets an object from the library and deletes it.
    
    function consume( $name )
    {
      return $this->delete($key, $retrieve = true);
    }
    
    
    //
    // Claims an object in the library for exclusive use. Provided primarily for interface 
    // compatibility with CacheConnection.
    
    function claim( $key, $block = true, $timeout = 0 )
    {
      abort("NYI");
    }
    
    
    //
    // Releases a claim() from an object. Provided primarily for interface compatibility with
    // CacheConnection.
    
    function release( $key )
    {
      abort("NYI");
    }


    //
    // Returns a path for an object in the library.

    protected function make_path( $name )
    {
      $slug = preg_replace("/--+/", "-", preg_replace("[^\d\w\s-\.]", "-", $name));
      if( strlen($slug) > 80 )
      {
        $slug = substr($slug, 0, 40) . "..." . md5($slug);
      }

      return sprintf("%s/%s.%s%s.serialized", $this->storage_path, defined('APPLICATION_NAME') ? APPLICATION_NAME : "php", $this->prefix_dot, $slug);
    }
  }
