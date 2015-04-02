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
  // A bit of a hack to do object-at-a-time reading of a JSON file. The file must contain a single
  // array of JSON objects for this to work. We assume the file is UTF-8 encoded.

  class JSONArrayReader
  {
    static function open( $path )
    {
      if( !empty($path) && file_exists($path) )
      {
        if( $stream = fopen($path, 'r') )
        {
          return new static($stream);
        }
      }

      return null;
    }


    function __construct( $stream )
    {
      $this->stream  = $stream;
      $this->inside  = false;
      $this->pending = null;
    }

    //
    // Returns the next object from the stream, or null if there are no more objects. If it takes
    // more than $limit bytes to read an object from the stream, the parsing will be terminated
    // on the assumption that the file is corrupt.

    function read_next( $limit = 40000 )
    {
      $object = null;

      //
      // Eat the leading [ marker that starts the array. We read until we find one.

      while( $this->stream && !$this->inside )
      {
        $c = $this->read(1) and $this->inside = ($c == '[');
      }

      //
      // Build up a buffer in memory 1k bytes at a time. With each version of the buffer, we
      // look for a matching pair of curly braces, which delineate an object. Of course, objects
      // can contain nested braces, so we test by trying each successive '}'. Eventually, the
      // json_decode() will succeed, and that's when we know we have grabbed the whole object.
      // Hence the mention of "hack", above. Hey—it's way less work than writing a parser.
      //
      // Note: we discard anything between objects (hoping it is just whitespace).

      $start = $end = $object = null;
      while( $this->stream && !$object && strlen($this->pending) <= $limit )
      {
        //
        // Read if we have no useful input to work with: if we are out of data; if we are
        // re-entering the loop with a $start already found; or if the pending data contains no
        // { markers.

        if( empty($this->pending) || is_numeric($start) || !is_numeric(strpos($this->pending, '{')) )
        {
          if( $more = $this->read(400) )
          {
            $this->pending .= $more;
          }
        }

        //
        // Look for an object that spans from the first '{' to some copy of '}'. If json_decode()
        // doesn't find a complete object before we run out of space, we'll need more input.

        if( !empty($this->pending) )
        {
          is_numeric($start) or $start = strpos($this->pending, '{');
          if( is_numeric($start) )
          {
            is_numeric($end) or $end = $start;
            while( !$object && ($end = strpos($this->pending, '}', $end + 1)) )
            {
              $chunk = substr($this->pending, $start, $end - $start + 1);
              if( $object = @json_decode($chunk) )
              {
                $this->pending = substr($this->pending, $end + 1);
                $start = null;
              }
            }
          }
        }
      }

      return $object;
    }

    //
    // Returns true if all input was used.

    function is_complete()
    {
      return empty($this->stream) && (!empty($this->pending) || trim($this->pending) == ']');
    }


    private function read( $bytes = 1 )
    {
      $data = null;
      if( $this->stream )
      {
        $data = fread($this->stream, $bytes);
        if( $bytes && empty($data) )
        {
          $this->close();
        }
      }

      return $data;
    }

    private function close()
    {
      fclose($this->stream);
      $this->stream = null;
      return true;
    }
  }
