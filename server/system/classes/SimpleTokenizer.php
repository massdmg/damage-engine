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

  //
  // Splits a string into words and quoted phrases on spaces.

  class SimpleTokenizer
  {
    static function tokenize( $string, $delimiters = '""', $strip_delimiters = true, $break_characters = ",;" )
    {
      if( $strip_delimiters && is_bool($delimiters) )
      {
        $strip_delimiters = $delimiters;
        $delimiters       = '""';
      }

      $tokens    = array();
      $tokenizer = new static($string, $delimiters, $strip_delimiters, $break_characters);
      while( $token = $tokenizer->next_token() )
      {
        $tokens[] = $token;
      }

      return $tokens;
    }


    //
    // $delimiters is an array or space-separated string of delimiter pairs. If your delimiters are
    // longer than one character each, separate the left from the right with a colon (:). By default,
    // delimiters are stripped. If you need them returned, within your delimiter array, pass the
    // delimiter as key and the false as the value.

    function __construct( $string, $delimiters = '""', $break_characters = ",;" )
    {
      $this->original         = $string;
      $this->remaining        = $string;
      $this->delimiters       = array();
      $this->break_characters = $break_characters;
      $this->break_pattern    = '/(?=[ ' . preg_quote($break_characters) . '])/';
      $this->stop_words       = array();

      foreach( (array)$delimiters as $pair => $strip )
      {
        if( is_numeric($pair) )
        {
          $pair  = $strip;
          $strip = true;
        }

        if( strlen($pair) == 1 )
        {
          $this->delimiters[] = array($pair, $pair, $strip);
        }
        elseif( strpos($pair, ":") )
        {
          $this->delimiters[] = array_merge(array_slice(explode(":", $pair, 3), 0, 2), array($strip));
        }
        else
        {
          $this->delimiters[] = array(substr($pair, 0, 1), substr($pair, 1, 1), $strip);
        }
      }
    }

    function mark()
    {
      return $this->remaining;
    }

    function restore( $mark )
    {
      $this->remaining = $mark;
    }

    function get_position()
    {
      return !empty($this->remaining) ? strlen($this->original) - strlen($this->remaining) : strlen($this->original);
    }

    function is_at_end()
    {
      return empty($this->remaining);
    }

    function next_token( $ignore_stop_word_frames = 0 )
    {
      $token = null;

      if( $this->remaining && ($working = trim($this->remaining)) )
      {
        foreach( $this->delimiters as $set )
        {
          list($left_delimiter, $right_delimiter, $strip) = $set;
          if( substr($working, 0, 1) == $left_delimiter )
          {
            $offset = 1;
            while( !$token && ($next_right = strpos($working, $right_delimiter, $offset)) )
            {
              $next_escaped_right = strpos($working, '\\' . $right_delimiter, $offset);
              if( !$next_escaped_right || $next_right < $next_escaped_right )
              {
                $token   = substr($working, 0, $next_right + 1);
                $working = substr($working, $next_right + 1);
              }
              else
              {
                $offset = $next_escaped_right + 1;
              }
            }

            if( $strip )
            {
              $token = str_replace('\\' . $right_delimiter, $right_delimiter, substr($token, strlen($left_delimiter), -strlen($right_delimiter)));
            }
          }
        }

        if( !$token )
        {
          $next_character = substr($working, 0, 1);
          if( is_numeric(strpos($this->break_characters, $next_character)) )
          {
            $token   = substr($working, 0, 1);
            $working = substr($working, 1);
          }
          else
          {
            @list($token, $working) = preg_split($this->break_pattern, $working, 2);
          }
        }

        //
        // Check that we aren't at a stop word.

        if( $token && !empty($this->stop_words) )
        {
          foreach( $this->stop_words as $frame )
          {
            if( $ignore_stop_word_frames > 0 )
            {
              $ignore_stop_word_frames--;
            }
            elseif( $token == $frame->stop_word )
            {
              $token = null;
              break;
            }
            elseif( $frame->eclipse_prior )
            {
              break;
            }
          }
        }


        //
        // If everything is fine, update the state.

        if( $token )
        {
          $this->remaining = $working;
        }
      }

      return $token;
    }


    function lookahead( $count = 1, $ignore_stop_word_frames = 0 )
    {
      $remaining = $this->remaining;

      for( $i = 0; $i < $count; $i++ )
      {
        $next_token = $this->next_token($ignore_stop_word_frames);
      }

      $this->remaining = $remaining;

      return $next_token;
    }


    function consume( $literal = null, $ignore_stop_word_frames = 0 )
    {
      if( is_null($literal) || $this->lookahead() == $literal )
      {
        return $this->next_token($ignore_stop_word_frames);
      }

      return false;
    }


    function restart()
    {
      $this->remaining = $this->original;
    }


    function push_stop_word( $stop_word, $eclipse_prior = true )
    {
      array_unshift($this->stop_words, (object)array("stop_word" => $token, "eclipse_prior" => $eclipse_prior));
    }


    function pop_stop_word( $stop_word )
    {
      count($this->stop_words) && $this->stop_words[0]->stop_word == $stop_word or abort("SimpleTokenizer::pop_stop_word($token) does not match last SimpleTokenizer::push_stop_word()");
      array_shift($this->stop_words);
    }
  }
