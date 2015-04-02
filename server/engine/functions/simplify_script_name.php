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


  function simplify_script_name( $name = null )
  {
    $name or $name = substr($_SERVER["REDIRECT_URL"], 1);

    //
    // index.php is not often helpful. Discard it unless that's all we've got.

    if( $name != "/index.php" && substr($name, -10) == "/index.php" )
    {
      $name = substr($name, 0, -10);
    }

    //
    // If it looks like we have an API version in the path, use only what it follows.

    $pieces = preg_split("/\/(current|\d+((\.\d+)*))(?=\/)/", $name);
    if( count($pieces) > 1 )
    {
      $name = array_pop($pieces);
      while( substr($name, 0, 1) == "/" )
      {
        $name = substr($name, 1);
      }
    }

    //
    // Trim off leading directories until we can fit it in 50 characters.

    while( strlen($name) > 50 && is_numeric(strpos($name, "/")) )
    {
      list($discard, $name) = explode("/", $name, 2);
    }

    //
    // And just plain truncate it to 50 characters, if still over.

    if( strlen($name) > 50 )
    {
      $name = substr($name, 0, 50);
    }

    return $name;
  }
