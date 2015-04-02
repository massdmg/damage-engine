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
  // Returns true if the passed file is the called script.

  function is_called_script( $path )
  {
    return realpath($_SERVER["SCRIPT_FILENAME"]) == realpath($path);
  }

  //
  // Returns true if the script is running at a terminal (instead of in a web server).

  function at_terminal()
  {
    return isset($_SERVER["TERM"]) || !isset($_SERVER["SHELL"]);
  }

  //
  // Returns true if the passed file is the called script and was run from a terminal.

  function is_called_from_terminal( $path )
  {
    return is_called_script($path) && at_terminal();
  }
