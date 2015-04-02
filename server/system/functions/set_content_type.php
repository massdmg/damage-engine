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
  // Sets the content type, if possible.

  function set_content_type( $type )
  {
    @header("Content-Type: $type; charset=UTF-8");
  }

  function enter_text_mode()
  {
    set_content_type("text/plain");
  }

  function enter_html_mode()
  {
    set_content_type("text/html");
  }

  function enter_json_mode()
  {
    set_content_type("application/json");
  }
