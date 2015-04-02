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
  // A bit of a hack to do object-at-a-time writing of a JSON file. We assume the file is UTF-8
  // encoded.

  class JSONArrayWriter
  {
    static function open( $path )
    {
      if( !empty($path) )
      {
        if( $stream = fopen($path, "w") )
        {
          return new static($stream);
        }
      }

      return null;
    }

    function __construct( $stream )
    {
      $this->stream = $stream;
      $this->inside = false;
      $this->first  = true;
    }


    //
    // Writes a single object to the stream.

    function write( $object )
    {
      if( !$this->stream )
      {
        trigger_error("the JSONArrayWriter stream is already closed!", E_USER_ERROR);
      }
      elseif( $string = json_encode($object) )
      {
        //
        // Write the opening array start marker.

        if( !$this->inside )
        {
          fwrite($this->stream, '[');
          $this->inside = true;
          $this->first  = true;
        }

        //
        // Write any needed record separator.

        if( $this->first )
        {
          $this->first = false;
        }
        else
        {
          fwrite($this->stream, ',');
        }

        //
        // Write the object to the stream.

        return fwrite($string) >= strlen($string);
      }

      return false;
    }


    //
    // Closes the stream properly.

    function close()
    {
      if( $this->stream )
      {
        if( !$this->inside )
        {
          fwrite($this->stream, '[');
        }

        fwrite($this->stream, ']');
        fclose($this->stream);
        $this->stream = null;
      }
    }


    //
    // Given an open JSONArrayReader, writes all the objects to our stream. You should check
    // $reader->is_complete() when done.

    function write_from( $reader )
    {
      while( $object = $reader->get_object() )
      {
        if( !$this->write($object) )
        {
          return false;  
        }
      }

      return true;
    }

  }
