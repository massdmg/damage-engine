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

  class ParameterParserCountHandler extends ParameterParserBase
  {
    static function build( $handler, $minimum, $maximum )
    {
      return new static($handler, $minimum, $maximum);
    }


    function __construct( $handler, $minimum, $maximum )
    {
      $this->handler = $handler;
      $this->minimum = $minimum;
      $this->maximum = $maximum;
    }


    function get_stop_words()
    {
      return $this->handler->get_stop_words();
    }

    function is_required()
    {
      return $this->minimum > 0;
    }


    function parse_from_tokenizer( $tokenizer, &$into, $pluralize = false, $stop_words = array() )
    {
      // debug("ENTERING COUNT $this->minimum to $this->maximum at: $tokenizer->remaining");
      // debug("STOP WORDS: " . implode(", ", $stop_words));

      if( $this->maximum > 1 )
      {
        $pluralize = true;
      }

      $occurrences = 0;

      //
      // Any exceptions in parsing the minimum occurences get out.

      for( $i = 0; $i < $this->minimum; $i++ )
      {
        $mark = $tokenizer->mark();
        if( !$this->handler->parse_from_tokenizer($tokenizer, $into, $pluralize) )
        {
          $tokenizer->restore($mark);
          break;
        }

        $occurrences++;
      }

      //
      // Any exceptions in parsing past the minimum occurences simply terminate our work.

      $last_position = -1;
      $curr_position = $tokenizer->get_position();
      for( ; $i < $this->maximum && $curr_position != $last_position; $i++ )
      {
        //
        // Ensure we don't eat the next clause by accident.

        $la = $tokenizer->lookahead();
        if( in_array($la, $stop_words) )
        {
          break;
        }

        //
        // Try for the next element.

        $mark = $tokenizer->mark();
        $working = $into;

        try
        {
          if( $this->handler->parse_from_tokenizer($tokenizer, $working, $pluralize) )
          {
            $into = $working;
          }
          else
          {
            $tokenizer->restore($mark);
            break;
          }
        }
        catch( ParsingFailedException $e )
        {
          $tokenizer->restore($mark);
          break;
        }

        $last_position = $curr_position;
        $curr_position = $tokenizer->get_position();

        $occurrences++;
      }

      //
      // If we have too few tokens, it's an error.

      // debug("EXITING COUNT $this->minimum to $this->maximum at: $tokenizer->remaining");
      // debug($into);

      return $occurrences >= $this->minimum;
    }
  }
