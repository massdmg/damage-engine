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
  // Does a very brain-dead (at the moment) job of pluralizing a word.

  function pluralize( $singular, $count = 2, $plural_form = null )
  {
    if( $count == 1 )
    {
      return $singular;
    }
    elseif( $plural_form )
    {
      return $plural_form;
    }
    elseif( substr($singular, -1) == "y" )
    {
      return substr($singular, 0, -1) . "ies";
    }
    else
    {
      return $singular . "s";
    }
  }
