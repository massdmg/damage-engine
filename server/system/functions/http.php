<?php
//
// Copyright 2011 1889 Labs (contact: chris@courage-my-friend.org)
//
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
//     http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// ============================================================================
//
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


  function reload( $offset = "", $include_query_string = true )
  {
    $url = sprintf("%s%s", $_SERVER["REDIRECT_URL"], $offset);
    if( $include_query_string && !empty($_SERVER["QUERY_STRING"]) )
    {
      $url .= "?" . $_SERVER["QUERY_STRING"];
    }

    redirect($url);
  }

  function redirect( $url, $permanent = false )
  {
    $code = $permanent ? "302" : "301";
    if( substr($url, 0, 1) == "/" )
    {
      $url = "http://" . $_SERVER["HTTP_HOST"] . $url;
    }

    header("HTTP/1.0 $code");
    header("Location: $url");
    exit;
  }

  function mark_404( $exit = false )
  {
    header("HTTP/1.1 404");
    if( $exit )
    {
      exit;
    }
  }

  function mark_403( $exit = false )
  {
    header("HTTP/1.1 403");
    if( $exit )
    {
      exit;
    }
  }

  function url()
  {
    $args = func_get_args();

    $url = $_SERVER["REQUEST_URI"];
    if( is_string(@$args[0]) )
    {
      $offset = preg_replace("/(^|\/)\.\//", "\\1", array_shift($args));
      $url = substr($offset, 0, 1) == "/" ? $offset : $url . (substr($url, -1, 1) == "/" ? "" : "/") . $offset;
      while( is_numeric(strpos($url, "/../")) )
      {
        $url = preg_replace("/[^\/]+\/\.\.\//", "", $url, 1);
      }
    }

    if( is_numeric(strpos($url, "?")) )
    {
      list($url, $discard_as_already_in_get) = explode("?", $url);
    }

    if( empty($args) || is_array($args[0]) )
    {
      $parameters = array_merge(is_array($_GET) ? $_GET : array(), @is_array($args[0]) ? $args[0] : array());
      if( !empty($parameters) )
      {
        $url = $url . make_query_string($parameters);
      }
    }

    return $url;
  }


  function make_query_string( $parameters, $marker = "?" )
  {
    $pairs = array();
    foreach( $parameters as $name => $value )
    {
      if( is_array($value) )
      {
        foreach( $value as $v )
        {
          $pairs[] = $name . "[]=" . rawurlencode($v);
        }
      }
      elseif( is_object($value) )
      {
        trigger_error("BUG: received an object in make_query_string()", E_USER_ERROR);
      }
      else
      {
        $pairs[] = "$name=" . rawurlencode($value);
      }
    }

    return empty($pairs) ? "" : "$marker" . implode("&", $pairs);
  }
