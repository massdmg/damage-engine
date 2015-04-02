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

  class Changeling
  {
    public $_pretend_to_be;
    
    function __construct( $pretend_to_be )
    {
      $this->_pretend_to_be = $pretend_to_be;
    }
    
   
    function __isset( $name )
    {
      $result = Metaclass::do__isset($name, $this, get_class($this)) and $result->is_set()
        or
      $result = Metaclass::do__isset($name, $this, $this->_pretend_to_be);
      
      return $result->get();
    }


    function &__get( $name )
    {
      $result = Metaclass::do__get($name, $this, get_class($this)) and $result->is_set()
        or
      $result = Metaclass::do__get($name, $this, $this->_pretend_to_be);
      
      return $result->get();
    }
    
    
    function __set( $name, $value )
    {
      $result = Metaclass::do__set($name, $value, $this, get_class($this)) and $result->is_set()
        or
      $result = Metaclass::do__set($name, $value, $this, $this->_pretend_to_be);
      
      return $result->get();
    }
    
    
    function __call( $name, $args )
    {
      $result = Metaclass::do__call($name, $args, $this, get_class($this), $fail = false) and $result->is_set()
        or 
      $result = Metaclass::do__call($name, $args, $this, $this->_pretend_to_be, $fail = true);

      return $result->get();
    }
    
    
    function __sleep()
    {
      return array();   // No serialization
    }
    
    
    function get_id()
    {
      return 0;
    }
    
    
    function is_cacheable()
    {
      return false;
    }
    
    function is_claimable()
    {
      return false;
    }
    
    
  }