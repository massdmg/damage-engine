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

  class HTMLResponse extends HTTPResponse
  {
    function __construct( $title = null, $charset = "UTF-8", $content_type = "text/html" )
    {
      parent::__construct($content_type . "; charset=$charset");
      $this->title        = $title or $this->title = Script::get_script_name();
      $this->html_headers = array(meta_charset($charset));
      $this->html_body    = array();
      $this->body_classes = array();
      $this->body_prefix  = "";
    }
    
    
    function reset()
    {
      $this->html_body = array();
    }




  //===============================================================================================
  // SECTION: Response construction helpers.
  
    function set_title( $title )
    {
      $this->title = $title;
      return $title;
    }
    
    function add_html_header( $text )
    {
      $this->html_headers[] = $text;
    }
    
    function add_body_element( $text )
    {
      $this->html_body[] = $text;
    }
    
    function add_body_class( $class )
    {
      $this->body_classes[] = $class;
      $this->body_prefix    = trim($this->body_prefix . " .$class");
    }






  //===============================================================================================
  // SECTION: Send functionality


    //
    // Sends this response to the user.

    function send( $sender = null, $ignored = null )
    {
      $sender or $sender = Script::$class;
      $html_body_elements = $this->html_body;
      $this->body_prefix and array_unshift($html_body_elements, $this->body_prefix);

      Features::enabled("debugging") and $this->add_body_element(Script::report("comment"));
      $html = html(head(title($this->title), tags($this->html_headers)), body($html_body_elements));
      parent::send($html, $sender);
    }
    
    



  //===============================================================================================
  // SECTION: Internals.



  }
