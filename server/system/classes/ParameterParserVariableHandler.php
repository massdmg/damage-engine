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


  class ParameterParserVariableHandler extends ParameterParserBase
  {
    static function build( $name, $pattern )
    {
      return new static($name, $pattern);
    }


    function __construct( $name, $pattern )
    {
      $this->name     = $name;
      $this->pattern  = $pattern;
      $this->compiled = null;
    }


    function get_stop_words()
    {
      return array();
    }


    function parse_from_tokenizer( $tokenizer, &$into, $pluralize = false, $stop_words = array() )
    {
      $token = null;
      switch( $this->pattern )
      {
        case "integer":
        case "number":
          if( is_numeric($tokenizer->lookahead()) )
          {
            $token = $tokenizer->consume();
          }
          break;

        case null:
          $token = $tokenizer->consume();
          break;

        default:
          $this->compiled or $this->compiled = '/' . str_replace('/', '\\/', $this->pattern) . '/';
          if( preg_match($this->compiled, $tokenizer->lookahead()) )
          {
            $token = $tokenizer->consume();
          }
      }

      if( $token )
      {
        $this->store_value($token, $into, $this->name, $pluralize);
        return true;
      }

      return false;
    }
  }
