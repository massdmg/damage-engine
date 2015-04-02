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

  if( !defined("DOCUMENT_ROOT_MADE_REAL") )
  {
    $_SERVER["DOCUMENT_ROOT"] = realpath($_SERVER["DOCUMENT_ROOT"]);
    define("DOCUMENT_ROOT_MADE_REAL", true);
  }


  require_once __DIR__ . "/../functions/is_filename.php";
  require_once __DIR__ . "/../classes/Features.php"     ;
  require_once __DIR__ . "/../classes/Callback.php"     ;

  class ClassLoader
  {
    /* protected */ static $roots           = null;
    /* protected */ static $signature       = "";
    /* protected */ static $index_base      = null;
    /* protected */ static $index_path      = null;
    /* protected */ static $ctimes          = null;
    /* protected */ static $epoch           = 0;
    /* protected */ static $pedigrees       = null;
    /* protected */ static $snake_pedigrees = null;


    static function add_directory( $path, $append = true )
    {
      $append ? array_push(static::$roots, $path) : array_unshift(static::$roots, $path);
      
      static::$signature  = hash("md5", implode("\n", static::$roots));
      static::$index_path = null;
    }

    
    static function is_loadable( $class_name )
    {
      static::build_index();
      return file_exists(static::$index_path . "/" . $class_name);
    }


    static function pick_class()
    {
      $args = func_get_args();
      foreach( array_flatten($args) as $name )
      {
        if( static::is_loadable($name) )
        {
          return $name;
        }
      }

      return null;
    }
    
    
    static function pick_class_or_fail()
    {
      foreach( func_get_args() as $name )
      {
        if( static::is_loadable($name) )
        {
          return $name;
        }
      }

      abort("couldn't find class");
    }


    static function enumerate_classes_matching( $pattern )
    {
      $matches = array();
      
      static::build_index();
      if( $handle = @opendir(static::$index_path) )
      {
        while( $name = readdir($handle) )
        {
          if( is_filename($name) && preg_match($pattern, $name) )
          {
            $matches[$name] = readlink(static::$index_path . "/" . $name);
          }
        }

        closedir($handle);
      }
      
      return $matches;
    }


    static function get_epoch()
    {
      if( static::$epoch == 0 )
      {
        $canary = __DIR__ . "/../../../.git/index";
        if( Features::development_mode_enabled() || !file_exists($canary) )
        {
          $callback = Callback::for_method(__CLASS__, "add_to_epoch");
          foreach( static::$roots as $root )
          {
            static::walk_directory($root, $callback);
          }
        }
        else
        {
          static::$epoch = filectime($canary);
        }
        
        if( Features::enabled("log_class_loader_epoch") or Features::enabled("log_class_loader_epoch_occassionally") and time() % 60 == 0 )
        {
          error_log("ClassLoader:get_epoch() on $canary is " . date("Y-m-d H:i:s", static::$epoch));
        }
      }
      
      return static::$epoch;
    }
    

    static function load_class( $class_name )
    {
      $full_class_name = $class_name;
      if( strpos($class_name, "\\") )
      {
        $pieces = explode("\\", $class_name);
        $class_name = array_pop($pieces);
      }
    
      $pass = 1;
      
    start:  
      static::build_index($class_name);
      if( is_filename($class_name) )
      {
        //
        // We support to class name => file name mappings:
        //   ClassName => ClassName.php
        //   ClassName_Specialiation => ClassName.php

        $names = array($class_name);
        strpos($class_name, "_") and $pieces = explode("_", $class_name, 2) and $names[] = array_shift($pieces);

        //
        // Search the index.

        foreach( $names as $name )
        {
          if( static::is_loadable($name) )
          {
            safe_require_once(readlink(static::$index_path . "/" . $name));
            class_exists($full_class_name) or Script::fail("class_not_present_in_class_file", "class_name", $full_class_name);
            return true;
          }
        }
        
        //
        // In general, we aren't asked to load stuff that doesn't exist. If it isn't in the
        // index, the index might be bad. Check.
        
        if( $pass == 1 )
        {
          static::$index_path = null;
          $pass = 2;
          goto start;
        }
      }

      return false;
    }


    static function get_pedigree( $class_name )
    {
      is_object($class_name) and $class_name = get_class($class_name);

      if( !isset(static::$pedigrees[$class_name]) and static::is_loadable($class_name) )
      {
        static::$pedigrees[$class_name] = array();
        
        $current = $class_name;
        do
        {
          static::$pedigrees[$class_name][] = $current;
        } while( $current = get_parent_class($current) );
      }
      
      return array_fetch_value(static::$pedigrees, $class_name);
    }
    
    static function get_snake_case_pedigree( $class_name )
    {
      is_object($class_name) and $class_name = get_class($class_name);
      if( !isset(static::$snake_pedigrees[$class_name]) and static::is_loadable($class_name) )
      {
        if( $pedigree = static::get_pedigree($class_name) )
        {
          static::$snake_pedigrees[$class_name] = array_map('convert_pascal_to_snake_case', $pedigree);
        }
      }

      return array_fetch_value(static::$snake_pedigrees, $class_name);
    }
    
    
    static function sprintf_with_pedigree( $format, $class_name, $snake_case = true, $be_tolerant = false )
    {
      $names = array();
      $method = $snake_case ? "get_snake_case_pedigree" : "get_pedigree";
      if( $class_names = static::$method($class_name) )
      {
        foreach( $class_names as $string )
        {
          foreach( (array)$format as $f )
          {
            $names[] = sprintf($f, $string);
          }
        }
      }
      elseif( $be_tolerant )
      {
        foreach( (array)$format as $f )
        {
          $names[] = sprintf($f, $snake_case ? convert_pascal_to_snake_case($class_name) : $class_name);
        }
      }

      return $names;
    }



  //=================================================================================================
  // SECTION: Internals


    static function initialize()
    {
      if( is_null(static::$roots) )
      {
        static::$roots           = array();
        static::$signature       = "";
        static::$index_base      = sprintf("/tmp/%s.class_index", defined("APPLICATION_NAME") ? APPLICATION_NAME : "php");
        static::$index_path      = null;
        static::$epoch           = 0;
        static::$pedigrees       = array();
        static::$snake_pedigrees = array();
        
      }
    }
    
    
    static function build_index( $canary = null )
    {
      if( empty(static::$index_path) )
      {
        static::$index_path = $index_path = sprintf("%s/%s", static::$index_base, static::$signature);
        
        $retried = false; 
        retry:
        if( !file_exists($index_path) || filectime($index_path) <= static::get_epoch() || ($canary && !file_exists("$index_path/$canary")) )
        {
          if( !$retried )
          {
            usleep(mt_rand(3, 15000));  // Avoid a thundering herd by waiting a random interval for somebody else to build it
            $retried = true;
            goto retry;
          }
          else
          {
            $id = getmypid() . "." . time();

            $build_path = sprintf("%s.%s"      , $index_path, $id);
            $trash_path = sprintf("%s.%s.trash", $index_path, $id);

            $canary == "Stronghold" and @alert_with_trace("Stronghold loaded from cache!");
            @warn("REBUILDING class index for [$canary] in: $build_path");
            

            mkdir($build_path, $mode = 0755, $recursive = true) or abort("could not create class index working directory");
            $builder = Callback::for_method_with_dynamic_offset(__CLASS__, "add_to_index", $dynamic_offset = 0, $build_path);
            foreach( static::$roots as $root )
            {
              static::walk_directory($root, $builder);
            }

            file_exists($index_path) and rename($index_path, $trash_path);
            rename($build_path, $index_path);

            if( file_exists($trash_path) )
            {
              @exec("rm -rf '$trash_path'");
            }
          }
        }
      }      
    }


    static function add_to_index( $path, $index_path )
    {
      $class_name = basename($path, ".php");
      $link_path  = $index_path . "/" . $class_name;      
      if( !file_exists($link_path) )
      {
        symlink($path, $link_path) or abort("could not create class index entry");
      }
    }
    
    
    static function add_to_epoch( $path )
    {
      static::$epoch = max(static::$epoch, filectime($path));
    }
    
    
    static function walk_directory( $directory, $file_processor )
    {
      if( file_exists($directory) && is_dir($directory) && ($handle = @opendir($directory)) )
      {
        while( $name = readdir($handle) )
        {
          if( is_filename($name) )
          { 
            $path = sprintf("%s/%s", $directory, $name);
            if( is_dir($path) )
            {
              static::walk_directory($path, $file_processor);
            }
            elseif( preg_match('/([A-Z][a-z0-9]*)+\.php/', $name) )
            {
              $file_processor->call(array($path));
            }
          }
        }

        closedir($handle);
      }
    }
  }
  
  ClassLoader::initialize();
