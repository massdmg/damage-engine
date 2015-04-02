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
  // Thrown by DataHandlers when checking for discrepancies and discovering the memory and database
  // structures are different.

  class DataHandlerError extends Exception
  {
    function __construct( $context, $member_name, $error_message )
    {
      $this->class_name    = get_class($context);
      $this->member_name   = $member_name;
      $this->error_message = $error_message;

      parent::__construct($this->class_name . "::$member_name: $error_message");
    }
  }
