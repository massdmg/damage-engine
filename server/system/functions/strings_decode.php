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

  function strings_decode( $string )
  {
    $lines   = explode("\n", $string);
    $current = 0;
    
    return strings_decode_lines($lines, $current);
  }
  
  
  function strings_decode_lines( &$lines, &$current, $level = 0 )
  {
    // BUG: are trim, rtrim, and strspn workable in UTF8 without actual multibyte awareness?
    
    $decoded = null;    
    while( $current < count($lines) )
    {
      if( $line = rtrim($lines[$current]) )                                // if the line isn't blank, evaluate it 
      {
        $indent = strspn($line, " ");                                      
        if( $indent >= $level )                                            // if the indent is at our $level or deeper, it's ours to worry about
        {
          $decoded or $level = $indent;                                    // correct for extra indent
          
          $m = null;
          if( preg_match("/^ {" . $indent . "}(\w+):(.*)$/", $line, $m) )
          {
            $name    = $m[1];
            $value   = $m[2];
            $decoded = (object)$decoded;
            if( $value )                                                   // if there's a value, use it
            {
              $decoded->$name = trim($value);
              $current += 1;
            }
            else                                                           // otherwise, recurse to build it
            {
              $current += 1;
              $children = strings_decode_lines($lines, $current, $indent+1);
              $decoded->$name = is_null($children) ? "" : $children;
            }
          }
          elseif( !$decoded )                                              // if we are a text block, read it and return 
          {
            ob_start();
            while( $current < count($lines) and (strspn($lines[$current], " ") >= $level or strlen(rtrim($lines[$current])) == 0) )
            {
              print substr($lines[$current], $level);
              print "\n";
              $current += 1;
            }
            
            $decoded = rtrim(ob_get_clean());
            break;
          }
          else                                                             // it's an parse error; let trigger_error decide how to proceed (stop or ignore)
          {
            trigger_error("strings_decode() failed on line " . $current + 1, E_USER_WARNING);
            $current += 1;
          }
        }
        else                                                               // indent is no longer in our scope, so we're done
        {
          break;   
        }
      }
      else                                                                 // ignore empty lines
      {
        $current += 1;
      }
    }
    
    return $decoded;
  }
