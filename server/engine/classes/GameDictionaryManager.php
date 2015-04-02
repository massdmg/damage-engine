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


  class GameDictionaryManager extends GameSubsystem
  {
    function __construct( $engine )
    {
      parent::__construct($engine, __FILE__);
    }
    
    
    //
    // Forces a load of all dicts. Very dangerous. Use with caution.
    
    function load_all_dicts_from_csvs( $paths, $ds = null, $delimiter = ',', $enclosure = '"', $escape = '\\' )   // game method
    {
      $ds      = $this->get_ds($ds);
      $game    = $this->engine;
      $changed = array();
      
      foreach( $paths as $path )
      {
        $table_name = basename($path, ".csv");
        $this->load_table_from_csv($table_name, $path, $ds, $delimiter, $enclosure, $escape);
        $changed[$table_name] = true;
      }

      return $changed;
    }
    
    
    //
    // Given a directory of table-named CSV files, loads those that have changed since last marked
    // in ServerCacheControlData.
    
    function load_changed_dicts_from_csvs( $paths, $ds = null, $delimiter = ',', $enclosure = '"', $escape = '\\' )   // game method
    {
      $ds      = $this->get_ds($ds);
      $game    = $this->engine;
      $changed = array();
      
      foreach( $paths as $path )
      {
        $mtime      = filemtime($path);
        $table_name = basename($path, ".csv");

        if( $mtime > array_fetch_value($game->cache_control, $table_name, 0) )
        {
          $this->load_table_from_csv($table_name, $path, $ds, $delimiter, $enclosure, $escape);
          $changed[$table_name] = true;
        }
        else
        {
          $changed[$table_name] = false;
        }
      }

      return $changed;
    }
    
    
    //
    // Reloads a single table from a CSV file (pass a path or a file handle). First row of the file
    // must contain field names for the columns.
    
    function load_table_from_csv( $table_name, $path, $ds = null, $delimiter = ',', $enclosure = '"', $escape = '\\' )
    {
      $ds2      = $this->get_ds($ds)->reconnect_for_writing();
      $game     = $this->engine;
      $handle   = (is_resource($path) ? $path : null) or $handle = fopen($path, "r") or throw_exception("dict_loader_unable_to_open_configuration_csv", "path", $path);
      $headings = fgetcsv($handle, $length = 0, $delimiter, $enclosure, $escape)     or throw_exception("dict_loader_unable_to_load_configuration_csv_headings");
      
      //
      // Get the Table object and figure out which fields are nullable. 
      
      $table = $ds2->schema->get_table($table_name) or throw_exception("dict_loader_unable_to_find_table", "name", $table_name);
      $nullable_fields = array();
      foreach( $headings as $field )
      {
        $table->has_field($field) or throw_exception("dict_loader_unable_to_find_field", "name", $field, "table", $table_name);
        if( $table->is_nullable($field) )
        {
          $nullable_fields[$field] = $field;
        }
      }
      
      // 
      // We are doing a complete re-import, so we start by wiping the table (in the confines of this 
      // transaction).
      
      $ds2->execute("DELETE FROM $table_name");
      
      //
      // Next, import the data.
      
      $line = 2;
      while( $data = fgetcsv($handle, $length = 0, $delimiter, $enclosure, $escape) )
      {
        if( count($data) != count($headings) )
        {
          if( count($data) == 1 && count($headings) != 1 && trim($data[0]) == "" )  // ignore blank-ish lines
          {
            continue;   //<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<
          }
          
          throw_exception("configuration_csv_field_count_mismatch", "line", $line, "headings", $headings, "data", $data);
        }
        
        $record = array();
        foreach( $headings as $index => $field )
        {
          $value = $data[$index];

          //
          // WORKAROUNDS:
          // 1) By default, Sequel Pro exports nulls as the word NULL. We'll have to strip it out.
          // 2) Any empty nullable fields will be treated as null.
          // 3) BUG: In this version of PHP, the $escape character is not stripped, so we'll have to do it ourselves.
          
          if( ($value == "NULL" || empty($value)) && array_has_member($nullable_fields, $field) )
          {
            $value = null;
          }
          
          if( !is_null($value) )
          {
            $record[$field] = str_replace($escape.$escape, $escape, str_replace($escape.$enclosure, $enclosure, $data[$index]));
          }
        }
        
        $ds2->insert_into($table_name, $record) or throw_exception("unable_to_import_csv_line", "line", $line, "headings", $headings, "data", $data);
        $line = $line + 1;
      }
      
      //
      // Finally, update ServerCacheControlData and commit.

      $now = now();
      $game->invalidate_aspect($table_name, $create = true, $ds2);
      
      Script::signal("dictionary_table_updated", $table_name, $now, $ds2);
      
      $ds2->commit();
      $ds2->close();
    }
  }