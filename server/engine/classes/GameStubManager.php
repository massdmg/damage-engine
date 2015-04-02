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

  class GameStubManager extends GameSubsystem
  {
    function __construct( $engine )
    {
      parent::__construct($engine, __FILE__);
      $this->rules = array();
    }


    function get_stub( $request_uri, $parameters, $ds = null )    // game method
    {
      if( $rules = $this->get_rules_for($request_uri, $ds) )
      {
        foreach( $rules as $rule )
        {
          if( $this->is_rule_relevant($rule, $parameters) )
          {
            return array($rule->response_code, $rule->response_type, $rule->response_text);
          }
        }
        
        return array(500, "application/json", sprintf('{"request":"%s","response":{"success":false}}', $request_uri));
      }
      
      return null;
    }
    



  //===============================================================================================
  // SECTION: Internals

    function get_rules_for( $request_uri, $ds )
    {
      $ds   = $this->get_ds($ds);
      $game = $this->engine;
      
      if( !array_has_member($this->rules, $request_uri) )
      {
        $this->rules[$request_uri] = $ds->query_all("service_stubs_for:$request_uri", "SELECT * FROM ServerStubDict WHERE request_uri = ? ORDER BY rule_priority ASC", $request_uri);
      }
      
      return array_fetch_value($this->rules, $request_uri, array());
    }


    function is_rule_relevant( $rule, &$parameters )
    {
      $relevant = true;
      foreach( $rule->parameters as $name => $template )
      {
        //
        // A question mark (?) on the end of a name makes its presence optional. Parse that out.
        
        $required = true;
        if( substr($name, -1) == "?" )
        {
          $required = false;
          $name     = substr($name, 0, -1);
        }

        
        //
        // Check the value against the template.
        
        if( array_has_member($parameters, $name) )
        {
          if( $template == "*" )
          {
            // no-op: even an empty parameter matches, so we're good
          }
          elseif( $template == "+" )
          {
            strlen($parameters[$name]) or $relevant = false;
          }
          elseif( substr($template, 0, 1) == '/' )
          {
            preg_match($template, $parameters[$name]) or $relevant = false;
          }
          else
          {
            $template == $parameters[$name] or $relevant = false;
          }
        }
        elseif( $required )
        {
          $relevant = false;
        }
        

        //
        // Bail out if the rule failed.
        
        if( !$relevant ) 
        {
          break;
        }
      }
      
      return $relevant;
    }
  
  
  }
