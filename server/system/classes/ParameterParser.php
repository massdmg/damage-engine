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

  class ParameterParser
  {
    protected static $token_delimiters;
    protected static $token_break_characters;

    static function build( $text )
    {
      if( empty(static::$token_delimiters) )
      {
        static::$token_delimiters       = array('""', "{}" => false, "()" => false);
        static::$token_break_characters = ",;?*+";
      }

      $elements  = array();
      $tokenizer = new SimpleTokenizer($text, static::$token_delimiters, static::$token_break_characters);

      while( $token = $tokenizer->next_token() )
      {
        if( $token == "?" || $token == "*" || $token == "+" )
        {
          $minimum = $maximum = 1;
          switch( $token )
          {
            case "?": $minimum = 0; $maximum =       1; break;
            case "*": $minimum = 0; $maximum = 1000000; break;
            case "+": $minimum = 1; $maximum = 1000000; break;
          }

          assert('!empty($elements)');
          $elements[] = ParameterParserCountHandler::build(array_pop($elements), $minimum, $maximum);
        }
        elseif( substr($token, 0, 1) == '(' && substr($token, -1, 1) == ')' )
        {
          $elements[] = ParameterParser::build(substr($token, 1, -1));
        }
        elseif( preg_match('/^{(\w+)(?::(.+))?}$/', $token, $m) )
        {
          $name    =  $m[1];
          $pattern = @$m[2];

          $elements[] = ParameterParserVariableHandler::build($name, $pattern);
        }
        else
        {
          $elements[] = ParameterParserLiteralHandler::build($token, $token);
        }
      }

      if( !$tokenizer->is_at_end() )
      {
        throw new ParsingFailedException();
      }
      elseif( count($elements) == 1 )
      {
        return $elements[0];
      }
      else
      {
        return new ParameterParserSequenceHandler($elements);
      }
    }



  }
