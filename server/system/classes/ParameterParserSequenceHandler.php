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

  class ParameterParserSequenceHandler extends ParameterParserBase
  {
    static function build( $handlers )
    {
      $flattened = array();
      while( $handler = array_shift($handlers) )
      {
        if( is_a($handler, __CLASS__) )
        {
          $handlers = array_merge($handler->handlers, $handlers);
        }
        else
        {
          $flattened[] = $handler;
        }
      }

      return new static($flattened);
    }


    function __construct( $handlers )
    {
      $this->handlers   = $handlers;
      $this->stop_words = array();
    }


    function get_stop_words( $from_index = 0 )
    {
      if( !array_key_exists($from_index, $this->stop_words) )
      {
        $stop_words = array();
        foreach( array_slice($this->handlers, $from_index) as $handler )
        {
          $additions = $handler->get_stop_words();
          if( !empty($additions) )
          {
            $stop_words = array_merge($stop_words, $additions);
            if( $handler->is_required() )
            {
              break;
            }
          }
        }

        $this->stop_words[$from_index] = $stop_words;
      }

      return $this->stop_words[$from_index];
    }


    function parse_from_tokenizer( $tokenizer, &$into, $pluralize = false, $stop_words = array() )
    {
      // debug("ENTERING SEQUENCE at: " . $tokenizer->remaining);
      // dump_trace_to_debug();
      // debug("RECEIVED");
      // debug($into);

      $index = 0;
      foreach( $this->handlers as $handler )
      {
        $current_stop_words = array_merge($stop_words, $this->get_stop_words($index + 1));
        if( !$handler->parse_from_tokenizer($tokenizer, $into, $pluralize, $current_stop_words) )
        {
          // debug("BAD");
          // debug($intp);
          return false;
        }
        // debug("LOOPING");
        // debug($into);

        $index++;
      }
      // debug("EXITING SEQUENCE at: " . $tokenizer->remaining);
      // dump_trace_to_debug();
      // debug("RETURNING");
      // debug($into);

      return true;
    }
  }
