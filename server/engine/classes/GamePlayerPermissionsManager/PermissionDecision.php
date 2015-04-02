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

  class PermissionDecision
  {
    public $state;
    public $reasons;
    protected $parameters;
    protected $trail;
    
    function __construct( $state = null, $reason = null )
    {
      $this->state             = $state;
      $this->reasons           = array();
      $this->reason_parameters = array();
      $this->trail             = array();
      
      if( !is_null($state) and $reason )
      {
        $parameters = array_pair_slice(func_get_args(), 2);
        $this->record($state, $reason, $parameters);
      }
    }
    
    function is_made()
    {
      return !is_null($this->state);
    }
    
    function is_not_made()
    {
      return is_null($this->state);
    }
    
    function is_yes()
    {
      return $this->state;
    }
    
    function is_no()
    {
      return $this->state === false;
    }
    
    function has_reason( $reason )
    {
      return in_array($reason, $this->reasons);
    }
    
    function throw_first_reason_as_exception( $player = null, $what = "", $required = true )
    {
      if( $reason = array_fetch_value($this->reasons, 0) )
      {
        $game = Script::fetch("game");
        
        $parameters = array_fetch_value($this->reason_parameters, $reason, array());
        $parameters = array_merge(array("player" => $game->get_player($player), "what" => $what, "required" => $required, "error_code" => "permission_denied"), $parameters);
          
        throw_exception($reason, $parameters);
      }
      
      return false;
    }
    
    function import( $rhs )
    {
      foreach( $rhs->trail as $record )
      {
        $this->record($record->vote, $record->reason, $record->parameters);
      }
      
      return $this->state;
    }
    
    
    function record_no( $reason )
    {
      $parameters = array_pair_slice(func_get_args(), 1);
      return $this->record(false, $reason, $parameters);
    }
    
    
    function record_yes( $reason )
    {
      $parameters = array_pair_slice(func_get_args(), 1);
      return $this->record(true, $reason, $parameters);
    }
    
    
    function record( $direction, $reason, $parameters )
    {
      $parameters = array_pair_slice(func_get_args(), 2);
      
      if( is_null($this->state) or !is_null($direction) && $this->state ^ $direction )
      {
        $this->state             = $direction;
        $this->reasons           = array();
        $this->reason_parameters = array();
        $this->messages          = array();
      }
      
      $this->reasons[] = $reason;
      $this->reason_parameters[$reason] = $parameters;
      $this->trail[] = (object)array("vote" => $direction, "reason" => $reason, "parameters" => $parameters);
      
      return $this->state;
    }
    
  }
