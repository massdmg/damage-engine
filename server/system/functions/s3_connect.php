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

  Conf::define_term("S3_CONFIGURATION", "a url-encoded configuration string, with values for bucket, key, and secret");


  //
  // Opens a "connection" to an Amazon S3 file store (bucket).
  //
  // Retrieves S3_CONFIGURATION, optionally specialized by DB_ENVIRONMENT.
  //
  // S3_CONFIGURATION_<name> should be a url-encoded configuration string with values
  // for bucket, key, and secret.
  //
  // Example 1:
  //   S3_ENVIRONMENT = "prod"
  //   S3_CONFIGURATION_prod  = "bucket=xyz&key=slkjdfs&secret=slkjdflskjfs"
  //   S3_CONFIGURATION_other = "bucket=xyz&key=siehslc&secret=slppzienskal"
  //
  // Example 2:
  //   S3_CONFIGURATION = "bucket=xyz&key=slkjdfs&secret=slkjdflskjfs"

  function s3_connect()
  {
    $configuration = Conf::get("S3_CONFIGURATION", null);
    if( !empty($configuration) )
    {
      $details = array();
      parse_str($configuration, $details);
      if( !empty($details) )
      {
        $bucket = $details["bucket"];
        $key    = str_replace(" ", "+", $details["key"   ]);   // BUG: + to space conversion in parse_str may make it inappropriate for this use.
        $secret = str_replace(" ", "+", $details["secret"]);   // BUG: ditto.

        if( !empty($bucket) && !empty($key) && !empty($secret) )
        {
          return new S3Bucket($bucket, $key, $secret);
        }
      }
    }

    return null;
  }
