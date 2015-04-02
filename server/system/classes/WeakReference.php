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
  
  class WeakReference
  {
    function __construct( &$object, $watchers = array() )
    {
      $this->object          = &$object;
      $this->is_still_loaded = true;
      $this->watchers        = $watchers;
    }
    
    function &get()
    {
      if( $this->is_still_loaded )
      {
        foreach( $this->watchers as $watcher )
        {
          Callback::do_call($watcher, $this);
        }
      }
      
      return $this->object;
    }
    
    function is_still_loaded()
    {
      return $this->is_still_loaded;
    }
    
    function discard()
    {
      $this->object          = null;
      $this->is_still_loaded = false;
      $this->watchers        = array();
    }
  }