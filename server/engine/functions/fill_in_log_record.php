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

  require_once "pack_main_database_field.php";
  

  function fill_in_log_record( $value, $field_name, $pairs, $table, $ds )
  {
    switch( $field_name )
    {
      case "timestamp":
        return time();

      case "service":
      case "script_name":
      case "ws":
        return substr(Script::get_script_name(), 0, 30);

      case "client_version":
        return Script::get("client_version", null);

      case "user_id":
        return Script::get("user_id", 0);

      case "run_id":
      case "ws_id":
        return Script::get_id();
        
      default:
        if( $data = pack_main_database_field($field_name, $pairs) )
        {
          list($input_name, $packed) = $data;
          return $packed;
        }
    }
    
    return $value;
  }