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

  require_once "pack_main_database_fields.php";
  
  function pack_sql_statement_fields( $fields, $table_name, $db )
  {
    if( $db )
    {
      $table  = $db->schema->get_base_query($table_name);
      $fields = pack_main_database_fields($fields, $table, $db);    // BUG: pack_main_database_fields() actually wants a $ds, not a $db; okay for now, but long-term?
    }
  
    return $fields;
  }
