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

  require_once "nvp_decode.php";
  require_once "csv_decode.php";
  require_once "strings_decode.php";

  $GLOBALS["unpack_main_database_fields_and_store_keys"] = false;


  function unpack_main_database_fields( $row, $database_name, $query, $is_first_row )
  {
    static $expected_name = null;
    static $mapped_query  = null;
    static $row_map       = null;
    static $store_keys    = false;

    //
    // Determine the expected database name the first time we are called.

    if( is_null($expected_name) )
    {
      $expected_name = Script::fetch("ds")->db->name;
    }

    //
    // If we have a row, and the database name matches, proceed.

    if( $row && $database_name == $expected_name )
    {
      //
      // If this is the first row of the results set, map out the work.

      if( $is_first_row or $mapped_query != $query )
      {
        $store_keys = $GLOBALS["unpack_main_database_fields_and_store_keys"];
        $GLOBALS["unpack_main_database_fields_and_store_keys"] = false;

        $row_map = array();
        foreach( $row as $name => $value )
        {
          if( $mapping = determine_how_to_unpack_main_database_field($name) )
          {
            $row_map[$name] = $mapping;
          }
        }
        
        $mapped_query = $query;
      }
      

      //
      // Unpack the fields.

      $work = $row_map;
      $key_field = null;

    unpack:
      $additional_work = array();
      foreach( $work as $name => $ops )
      {
        foreach( (array)$ops as $op )
        {
          if( substr($op, 0, 14) == "set key_field " )
          {
            $key_field = substr($op, 14);
          }
          else
          {
            switch( $op )
            {
              case "clear key_field":
                $key_field = null;
                break;

              case "annotate":
                $key_field = $store_keys ? $name . "_fields" : null;
                $added     = array();
                
                if( isset($row->$name) )
                {
                  $method = is_scalar($row->$name) ? "annotate_object_from_name_value_string" : "annotate_object";
                  $added  = $method($row, $row->$name);
                  unset($row->$name);
                }

                $key_field and $row->$key_field = array_merge((array)@$row->$key_field, $added);

                foreach( $added as $field_name )
                {
                  if( $mapping = determine_how_to_unpack_main_database_field($field_name) )
                  {
                    $additional_work[$field_name] = (array)$mapping;
                    if( $key_field )
                    {
                      array_unshift($additional_work[$field_name], sprintf("set key_field %s", $key_field));
                      array_push(   $additional_work[$field_name], "clear key_field");
                    }
                  }
                }

                $key_field = null;
                break;

              case "php_decode":
              case "json_decode":
              case "nvp_decode":
              case "csv_decode":
                $base_name = substr($name, 0, -(strlen($op) - 7 + 1));
                $method    = ($op == "php_decode" ? "unserialize" : $op);
                
                try
                {
                  $row->$base_name = (empty($row) ? null : $method($row->$name));
                }
                catch( Exception $e )
                {
                  throw_alert("unserialization_failed", "op", $op, "field", $name, "row", $row, "database_name", $database_name, "query", $query, "row_map", $row_map, $e);
                }

                $key_field && isset($row->$key_field) and replace_unpacked_key_field($row, $key_field, $name, $base_name);
                unset($row->$name);
                $name = $base_name;
                break;
              
              case "strings_decode":
                $property = "strings";
                $row->$property = strings_decode($row->$name);
                $key_field && isset($row->$key_field) and replace_unpacked_key_field($row, $key_field, $name, $property);
                unset($row->$name);
                break;
                
              case "hours_to_seconds":
                $property = substr($name, 0, -2) . "_s";
                $row->$property = @($row->$name * 3600);
                $key_field and array_push($row->$key_field, $property);
                break;

              case "km_to_m":
                $property = substr($name, 0, -3) . "_m";
                $row->$property = @($row->$name * 1000);
                $key_field and array_push($row->$key_field, $property);
                break;

              case "date_range":
                $row->$name = max(min($row->$name, "2038-01-01 00:00:00"), "1910-01-01 00:00:00");
                break;

              default:
                abort("NYI: $op");
            }
          }
        }
      }

      if( $additional_work )
      {
        $work = $additional_work;
        $additional_work = array();
        goto unpack;    // <<<<<<<<<< FLOW CONTROL <<<<<<<<<<<
      }
    }
    

    return $row;
  }

  function determine_how_to_unpack_main_database_field( $name )
  {
    if( $name == "extra" || substr($name, -6) == "_extra" )
    {
      return "annotate";
    }
    elseif( $name == "extra_json" || substr($name, -11) == "_extra_json" )
    {
      return array("json_decode", "annotate");
    }
    elseif( strpos($name, "_") )
    {
      $pieces = explode("_", $name);
      $suffix = array_pop($pieces);
      switch( $suffix )
      {
        case "json"   : return "json_decode"     ;
        case "php"    : return "php_decode"      ;
        case "csv"    : return "csv_decode"      ;
        case "nvp"    : return "nvp_decode"      ;
        case "h"      : return "hours_to_seconds";
        case "km"     : return "km_to_m"         ;
        case "date"   : return "date_range"      ;
        case "strings": return "strings_decode"  ;
      }
    }
    
    return null;
  }
  
  
  function replace_unpacked_key_field( $row, $key_field, $old_name, $new_name )
  {
    if( $key_field && isset($row->$key_field) )
    {                  
      array_push($row->$key_field, $new_name);
      
      $index = array_search($old_name, $row->$key_field);
      if( is_numeric($index) )
      {
        $array = $row->$key_field;
        unset($array[$index]);
        $row->$key_field = $array;
      }
    }
    
    return $row;
  }
