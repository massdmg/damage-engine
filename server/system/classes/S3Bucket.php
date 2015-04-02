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
   // Provides a simple, bucket-specific wrapper on the AWS S3 Class.

   require_once path("../aws-sdk/sdk.class.php"        , __FILE__);
   require_once path("../aws-sdk/services/s3.class.php", __FILE__);

   class S3Bucket
   {
     function __construct( $name, $key, $secret )
     {
       $this->name = $name;
       $this->s3   = new AmazonS3(array("key" => $key, "secret" => $secret));
     }

     //
     // Retrieves the specified object from S3 into the path or file handle you specify. Returns
     // true if the data was received.

     function download_object( $name, $to_file, $to_file_is_directory = false )
     {
       $options = array("fileDownload" => $to_file_is_directory ? $to_file . "/" . $name : $to_file);
       if( $response = $this->s3->get_object($this->name, $name, $options) )
       {
         if( $response->isOK() )
         {
           return true;
         }

         var_dump($response);
       }

       return false;
     }

     //
     // Writes the specified object to S3.

     function upload_object( $name, $from_file, $from_file_is_directory = false )
     {
       $options = array("fileUpload" => $from_file_is_directory ? $from_file . "/" . $name : $from_file);
       if( $response = $this->s3->create_object($this->name, $name, $options) )
       {
         return $response->isOK();
       }

       return false;
     }

     //
     // Lists the files available in the bucket.

     function list_objects()
     {
       $objects = null;
       if( $response = $this->s3->list_objects($this->name) )
       {
         $objects = array();
         foreach( $response->body->Contents as $record )
         {
           $objects[] = (string)$record->Key;
         }
       }

       return $objects;
     }
   }
