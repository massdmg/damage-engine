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

  function pack_main_database_field( $output_name, $fields )
  {
    if( strpos($output_name, "_") and $pieces = explode("_", $output_name) )
    {
      $suffix = array_pop($pieces);
      $packer = null;
      switch( $suffix )
      {
        case "php" : $packer or $packer = "serialize"  ;  /* fall through */
        case "csv" : $packer or $packer = "csv_encode" ;  /* fall through */
        case "nvp" : $packer or $packer = "nvp_encode" ;  /* fall through */
        case "json": $packer or $packer = "json_encode";  /* fall through */
        
          is_array($fields) or $fields = get_object_vars($fields);  // In case somebody passed us an object
          
          $input_name = substr($output_name, 0, -(strlen($suffix) + 1));
          if( array_key_exists($input_name, $fields) )
          {
            $value  = $fields[$input_name];
            $packed = empty($value) ? null : $packer($value);
            
            return array($input_name, $packed);
          }
          break;
      }
    }
    
    return null;
  }
  
  