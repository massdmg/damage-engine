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

  class Result
  {
    function __construct( $result = null, $is_set = false )
    {
      $this->result = $result;
      $this->is_set  = $is_set;
    }
    
    function set( $result )
    {
      $this->result = $result;
      $this->is_set = true;
      
      return $this;
    }
    
    function clear()
    {
      $this->result = null;
      $this->is_set = false;
      
      return $this;
    }
    
    function &get()
    {
      return $this->result;
    }
    
    function is_set()
    {
      return $this->is_set;
    }
  }