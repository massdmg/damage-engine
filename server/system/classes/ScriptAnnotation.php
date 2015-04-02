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

  class ScriptAnnotation
  {
    function __construct( $elapsed, $label, $notes )
    {
      $this->elapsed = $elapsed;
      $this->label   = $label;
      $this->notes   = $notes;
    }
    
    function get_notes_as_string( $allow_null = true )
    {
      if( $allow_null && is_null($this->notes) )
      {
        return null;
      }
      
      return is_string($this->notes) ? $this->notes : capture_var_dump($this->notes);
    }

    function __toString()
    {
      return sprintf("%dms %s%s", intval($this->elapsed * 1000), $this->label, $this->notes ? sprintf(": %s", $this->get_notes_as_string()) : "");
    }
  }
