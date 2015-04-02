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

  require_once "nvp_encode.php";
  require_once "csv_encode.php";
  require_once "pack_main_database_field.php";
  

  function pack_main_database_fields( $fields, $source_query, $ds )
  {
    is_array($fields) or $fields = get_object_vars($fields);  // In case somebody passed us an object
    
    //
    // Deal with the catch-all "extra" and "extra_json" fields first.
    
    $json = false;
    if( array_key_exists("extra", $source_query->source_mappings) or $json = array_key_exists("extra_json", $source_query->source_mappings) )
    {
      //
      // The extra field is used as a catchall for fields not otherwise part of the table. In order
      // to identify which of the inputs belong in the extra field, we need to know which fields
      // are real. We'll get that from the table underlying the source mapping.
      
      $pieces     = explode(".", $source_query->source_mappings[$json ? "extra_json" : "extra"][0]);
      $table_name = array_shift($pieces);
      if( $table = $ds->schema->get_table($table_name) )
      {
        $extras = array();
        $extra_field_names = array_diff(array_keys((array)$fields), $table->get_field_names());
        foreach( $extra_field_names as $name )
        {
          if( substr($name, 0, 1) != "_" )
          {
            $extras[$name] = $fields[$name];
          }
          
          unset($fields[$name]);
        }
        
        //
        // If using "extra_json", leave the array for further encoding. We only do the packing work
        // for the bare extra field.
        
        $fields["extra"] = $json ? $extras : nvp_encode($extras);
      }
    }
    
    
    //
    // Now deal with the suffix-encoded fields.
    
    foreach( array_keys($source_query->source_mappings) as $output_name )
    {
      if( $data = pack_main_database_field($output_name, $fields) )
      {
        list($input_name, $packed) = $data;
        
        $fields[$output_name] = $packed;
        unset($fields[$input_name]);
      }
    }
    
    return $fields;
  }
