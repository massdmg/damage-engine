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

  require_once "tree_fetch.php";

  //
  // Given a structure (array or object or tree of such), a list of offsets, and a default, returns the value from that 
  // coordinate within the structure, coerced to the same type as the default, or the default.
  // 
  //    $a = array("m" => array("b" => (object)array("x" => 10, "y" => 20), "c" => (object)array("x" => 30, "y" => 40)));
  //
  //    structure_fetch_value($a, array("m", "c", "x"),     100);   // 30
  //    structure_fetch_value($a, array("m", "d", "x"),     100);   // 100
  //    structure_fetch_value($a, array("m", "c")     , array());   // array("x" => 30, "y" => 40)
  //    structure_fetch_value($a, array("m", "d")     , array());   // array()
  //    structure_fetch_value($a, array("m", "c")     ,    null);   // (object)array("x" => 30, "y" => 40)
  //    structure_fetch_value($a, array("m", "d")     ,    null);   // null
  
  function structure_fetch_value( $structure, $path, $default = null )
  {
    $path  = is_string($path) && strpos($path, ",") ? preg_split('/,\s*/', $path) : (array)$path;
    return tree_fetch($structure, $path, $default);
  }
  
