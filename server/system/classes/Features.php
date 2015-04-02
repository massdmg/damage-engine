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

  class Features
  {
    static $features = null;

    
    static function enabled( $name )
    {
      return array_key_exists($name, static::$features) && static::$features[$name];
    }
    
    static function disabled( $name )
    {
      return array_key_exists($name, static::$features) && !static::$features[$name];
    }
    
    static function reset( $name )
    {
      unset(static::$features[$name]);
    }
    
    
    static function enable( $name )
    {
      static::$features[$name] = true;
      class_exists("Script") and Script::signal("feature_enabled", $name);
    }
    
    static function disable( $name )
    {
      static::$features[$name] = false;
      class_exists("Script") and Script::signal("feature_disabled", $name);
    }
    
    
    static function enable_all( $list )
    {
      foreach( $list as $name )
      {
        static::enable($name);
      }
    }
        
    static function disable_all( $list )
    {
      foreach( $list as $name )
      {
        static::disable($name);
      }
    }
    
    
    static function __callStatic( $name, $args )
    {
      if( substr($name, -8) == "_enabled" )
      {
        return static::enabled(substr($name, 0, -8));
      }
      elseif( substr($name, -9) == "_disabled" )
      {
        return static::disabled(substr($name, 0, -9));
      }
      elseif( substr($name, 0, 7) == "enable_" )
      {
        return static::enable(substr($name, 7));
      }
      elseif( substr($name, 0, 8) == "disable_" )
      {
        return static::disable(substr($name, 8));
      }
      
      abort($name);
    }
    
    static function initialize()
    {
      static::$features = array();
    }
  }
  
  Features::initialize();