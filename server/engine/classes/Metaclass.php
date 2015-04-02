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

  require_once "convert_camel_to_snake_case.php";


  class Metaclass
  {
    function __isset( $name )
    {
      return Metaclass::do__isset($name, $this, get_class($this))->get();
    }

    function __get( $name )
    {
      return Metaclass::do__get($name, $this, get_class($this))->get();
    }
    
    function __set( $name, $value )
    {
      return Metaclass::do__set($name, $value, $this, get_class($this))->get();
    }
    
    function __call( $name, $args )
    {
      return Metaclass::do__call($name, $args, $this, get_class($this))->get();
    }
    


    function is_cacheable()
    {
      return false;
    }
    
    function is_claimable()
    {
      return false;
    }
    
    
    
    
    
    
    
    static function do__isset( $name, $object, $class_name, $snake = null )
    {
      $snake or $snake = convert_camel_to_snake_case($name);
      $result = new Result();

      if( $snake != $name and isset($object->$snake) )
      {
        $result->set(true);
      }
      elseif( $method = "get_{$snake}" and $game = Script::fetch("game") and $game->can_route($method, $object, $class_name, 1) )
      {
        $value = $object->$method();
        $result->set(isset($value));
      }
      else
      {
        $handlers = static::make_names("isset", $class_name, $snake);
        Script::dispatch($handlers, $result = new Result(false), $name, $snake, $object);
      }

      return $result;
    }

    static function do__get( $name, $object, $class_name, $snake = null )
    {
      $snake or $snake = convert_camel_to_snake_case($name);
      $result = new Result();
      
      if( $snake != $name and isset($object->$snake) )
      {
        $result->set($object->$snake);
      }
      elseif( $method = "get_{$snake}" and $game = Script::fetch("game") and $game->can_route($method, $object, $class_name, 1) )
      {
        $result->set($object->$method());
      }
      else
      {
        $handlers = static::make_names("get", $class_name, $snake);
        Script::dispatch($handlers, $result = new Result(), $name, $snake, $object);
      }

      return $result;
    }
    
    static function do__set( $name, $value, $object, $class_name, $snake = null )
    {
      $snake or $snake = convert_camel_to_snake_case($name);
      $result = new Result();
      
      if( $snake != $name and isset($object->$snake) )
      {
        $object->$snake = $value;
        $result->set($object->$snake);
      }
      elseif( method_exists($object, $method = "set_{$snake}") or $game = Script::fetch("game") and $game->can_route($method, $object, $class_name) )
      {
        $result->set($object->$method($value));
      }
      else
      {
        $handlers = static::make_names("set", $class_name, $snake);
        Script::dispatch($handlers, $result = new Result(), $name, $snake, $object, $value);
      }

      return $result;
    }

    
    static function do__call( $name, $args, $object, $class_name, $fail = true )
    {
      $game   = Script::fetch("game");
      $result = new Result();
      $m      = null;
      
      if( $game->can_route($name, $object, $class_name) )
      {
        $result->set($game->route($name, $object, $args, $class_name));
      }
      elseif( preg_match('/^(get|claim)_(.*?)(_or_fail)?$/', $name, $m) and $id_getter = "get_{$m[2]}_id" and method_exists($object, $id_getter) || $game->can_route($id_getter, $object, $class_name) )
      {
        if( $id = call_user_func_array(array($object, $id_getter), $args) )
        {
          $result->set(call_user_func_array(array($game, $name), array_merge(array($id), array_slice($args, 1))));
        }
        elseif( @$m[3] )
        {
          throw_exception("unable_to_get_child_id", "child_class", $m[2], "parent_class", $class_name, "parent_id", method_exists($object, "get_object_id") ? $object->get_object_id() : "?");
        }
        else
        {
          $result->set(null);
        }
      }
      else
      {
        $fallbacks = array("result_of_missing_%s_method_$name", "result_of_missing_%s_method");
        $filters   = ClassLoader::sprintf_with_pedigree($fallbacks, $class_name, $name);
        $result    = Script::filter($filters, $result, $name, $object, $args);
      }
      
      !$result->is_set() and $fail and abort(sprintf("unknown method %s::%s", $class_name, $name, $object));
      return $result;
    }
    
    
    
    protected static function make_names( $method, $class_name, $snake )
    {
      $first_resort = ClassLoader::sprintf_with_pedigree(array("undefined_%s_property_{$snake}_{$method}", "undefined_%s_property_{$method}"), $class_name);
      $last_resort  = ClassLoader::sprintf_with_pedigree(array("undefined_%s_property_{$snake}_{$method}_last_resort", "undefined_%s_property_{$method}_last_resort"), $class_name);
      
      return array_merge($first_resort, $last_resort);
    }
    
    
  }
