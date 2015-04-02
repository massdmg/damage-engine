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
  // Provides read and write operations on an S3-stored, JSON-encoded archive of old database
  // content, as well as functionality to move data to the store from the database. We generally
  // store on file per day, so this class provides the means to access the sequence.

  class S3Archive
  {
    function __construct( $s3_bucket, $base_name, $working_directory )
    {
      $this->base_name = $base_name;
      $this->bucket    = $s3_bucket;
      $this->working_directory = $working_directory;
    }


    //
    // Downloads the archive for the specified date and stores it in the working directory.
    // Returns the full path to the file, or null on failure.

    function download( $date, $extension = null )
    {
      $local_path = $this->make_local_path($date, $extension);
      return $this->bucket->download_object($this->make_object_name($date), $local_path) ? $local_path : null;
    }


    //
    // Uploads a complete archive for the specified date. Be careful with this. It will replace
    // any existing data on the server.

    function upload( $date, $path )
    {
      return $this->bucket->upload_object($this->make_object_name($date), $path);
    }


    //
    // Downloads any existing object from the database and returns a JSONArrayWriter ready to add
    // more records to it. Creates a new file if there is no existing object. Clear $append if you
    // want a new file.

    function open_as_json_array_for_writing( $date, $append = true )
    {
      $local_path = $this->make_local_path($date);

      //
      // We will be writing a single array of JSON objects. First up, we need to create the file
      // with the array marker.

      $writer = JSONArrayWriter::open($local_path);
      if( !$writer )
      {
        trigger_error("unable to open local archive for writing");
        return false;
      }

      //
      // If we are running in append mode, download the file and append the data.

      if( $append && ($existing = $this->download($date, "existing")) )
      {
        $copied = false;
        if( $reader = JSONArrayReader::open($existing) )
        {
          if( $writer->write_from($reader) && $reader->is_complete() )
          {
            $copied = true;
          }
        }

        unlink($existing);

        if( !$copied )
        {
          $writer->close();
          $writer = null;
          unlink($local_path);
          trigger_error("unable to copy over existing archive data", E_USER_ERROR);
        }
      }

      return $writer;
    }




    //
    // Returns the S3 object name for the specified date.

    private function make_object_name( $date )
    {
      $instance    = date("Ymd", is_numeric($date) ? $date : strtotime($date));
      $object_name = $this->base_name . $instance;

      return $object_name;
    }

    //
    // Returns a local path in the working directory appropriate for the specified date and extension.

    private function make_local_path( $date, $extension = null )
    {
      return sprintf("%s/%s-%d%s", $this->working_directory, $this->make_object_name($date), getmypid(), $extension ? ".$extension" : "");
    }

    //
    // Returns true if the specified metric is relevant for this
  }
