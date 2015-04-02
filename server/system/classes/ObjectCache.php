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

  class ObjectCache
  {
    function __construct( $size_limit = 1000, $invalidation_policy = "LRU" )
    {
      $invalidation_class = "ObjectCacheInvalidationPolicy" . $invalidation_policy;
      ClassLoader::is_loadable($invalidation_class) or abort("object_cache_invalidation_policy_not_defined", "policy", $invalidation_policy);
      
      $this->objects     = array();
      $this->sizes       = array();
      $this->total_size  = 0;
      $this->size_limit  = $size_limit;
      $this->invalidator = new $invalidation_class($size_limit);
      
      $this->touch_callbacks        = array();
      $this->invalidation_callbacks = array();
    }
  
    function has( $key )
    {
      return array_has_member($this->objects, $key);
    }
  
    function get( $key, $default = null )
    {
      $this->touch($key);
      return array_fetch_value($this->objects, $key, $default);
    }
  
    function set( $key, $value, $size = 1 )
    {
      $this->invalidate($key);
      
      $this->objects[$key] = $value;
      $this->sizes[$key]   = $size;
      $this->total_size   += $size;
      $this->touch($key);

      $this->shrink_if_at_limit();
      
      return $value;
    }
  
    function touch( $key )
    {
      if( $this->has($key) )
      {
        $this->invalidator->touch($key);
        foreach( $this->touch_callbacks as $callback )
        {
          Callback::do_call($callback, $key);
        }
        return true;
      }
      
      return false;
    }
    
    function shrink_if_at_limit()
    {
      if( $this->total_size > $this->size_limit and count($this->objects) > 1 )
      {
        $this->shrink();
      }
    }
    
    function shrink()
    {
      if( $victim = $this->invalidator->pick_victim() )
      {
        $this->invalidate($victim);
      }
      
      return $victim;
    }
    
    function invalidate( $victim )
    {
      if( $this->has($victim) )
      {
        $object = $this->objects[$victim];

        $this->total_size -= $this->sizes[$victim];
        unset($this->objects[$victim]);
        unset($this->sizes[$victim]);
        $this->invalidator->invalidate($victim);
        
        foreach( $this->invalidation_callbacks as $callback )
        {
          Callback::do_call($callback, $victim, $object);
        }
      }
    }
    
    
    
    function on_invalidate( $callback )
    {
      $this->invalidation_callbacks[] = $callback;
    }
    
    function on_touch( $callback )
    {
      $this->touch_callbacks[] = $callback;
    }
  }
  