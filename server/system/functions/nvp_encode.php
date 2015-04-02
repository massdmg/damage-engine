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

  function nvp_encode( $pairs )
  {
    $list = array();
    foreach( $pairs as $name => $value )
    {
      if( !is_scalar($value) )
      {
        return sprintf("json=%s; ", json_encode($pairs));
      }

      strpos($value, ';') === false or Script::fail("invalid_content_for_name_value_pair_field", array("name" => $name, "value" => $value));
      $list[] = sprintf("%s=%s;", $name, $value); 
    }
    
    return implode(" ", $list) . (empty($list) ? "" : " ");
  }
  
  
