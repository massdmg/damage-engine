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

  class HTTPResponse
  {
    function __construct( $content_type = "text/html", $status = "200 Okay" )
    {
      $this->headers      = array();
      $this->content_type = $content_type;
      $this->status       = $status;
    }
    
    function set_status( $status )
    {
      $this->status = $status;
    }

    function add_header( $name, $content )
    {
      $this->headers[] = sprintf("%s: %s", $name, $content);
    }

    function reset()
    {
      $this->headers = array();
      $this->status  = "200 Okay";
    }

    function send( $body, $sender = null )
    {
      $sender or $sender = Script::$class;

      $this->add_header("Content-Length", strlen($body));
      $this->send_headers();
      $sender->send_text($body);
    }

    function send_headers()
    {
      header("HTTP/1.0 $this->status");
      header("Content-Type: " . $this->content_type);
      foreach( $this->headers as $header )
      {
        header($header);
      }
    }
    
    
    function send_unavailable( $message = "", $until = null )
    {
      $this->set_status("503 Service Unavailable");
      $until and $this->add_header("Retry-After", $until);
      $this->send($message);
    }
    
    
    function send_forbidden( $message = "" )
    {
      $this->set_status("403 Forbidden");
      $this->send($message);
    }
    
    
  }
