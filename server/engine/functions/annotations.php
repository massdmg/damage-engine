<?php if (defined($inc = "ANNOTATION_FUNCTIONS_INCLUDED")) { return; } else { define($inc, true); }

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
  // Given a semicolon-separated list of "name=value", copies all proporties to $object. No quoting
  // is possible, but the routine tries to ignore spurious matches, so it shouldn't often be a
  // problem. That said, you probably shouldn't trust this routine with untrusted (ie. user-supplied)
  // data, as it would be straightforward to hack.

  function annotate_object_from_name_value_string( $object, $string )
  {
    if( !empty($string) )
    {
      if( $instructions = preg_split('/;([ ]+(?=\w+\=)|\s*$)/', $string) )
      {
        $pairs = array();
        foreach( $instructions as $instruction )
        {
          if( preg_match('/^\s*\w+=/', $instruction) )
          {
            list($name, $value) = explode("=", $instruction, 2);
            $pairs[trim($name)] = ltrim($value);
          }
        }

        return annotate_object($object, $pairs);
      }
    }

    return array();
  }


  //
  // Given a JSON object string, copies all properties to $object. Returns true iff the json
  // parses.

  function annotate_object_from_json( $object, $json )
  {
    if( $data = @json_decode($json) )
    {
      return annotate_object($object, $data);
    }

    return array();
  }


  //
  // Given a $query_string, copies all properties to $object. Returns true iff the string parses.

  function annotate_object_from_query_string( $object, $query_string )
  {
    @parse_str($query_string, $pairs);
    return annotate_object($object, $pairs);
  }


  //
  // Given an array or object of properties, copies all to $object.

  function annotate_object( $object, $pairs )
  {
    $names = array();
    if( $pairs )
    {
      foreach( $pairs as $name => $value )
      {
        $object->$name = $value;
        $names[] = $name;
      }
    }

    return $names;
  }
