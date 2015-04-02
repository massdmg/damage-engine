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
  // Holds a set of fields (name/value pairs) for use with a Table.

  class TableRow
  {
    public $table;
    public $fields;

    function __construct( $table, $fields = array() )
    {
      $this->table    = $table;
      $this->fields   = $fields;
      $this->filtered = false;
      $this->checked  = false;
    }

    function set( $field, $value )
    {
      $this->fields[$field] = $value;
      $this->checked        = false;
    }


    //
    // Checks the fields for the specified database using the table. Throws a
    // TableValidationCheckFailed exception if there is a problem.

    function validate_against( $db, $canonicalize = false )
    {
      if( $this->table )
      {
        $this->table->check($db, $this->fields, true, $canonicalize);
        $this->checked = true;
      }
    }


    //
    // Sends the row to the table as an update.

    function update_table( $db, $criteria, $skip_checks = false )
    {
      if( $this->table )
      {
        return $this->table->update($db, $this->fields, $criteria, $skip_checks); // $this->checked is irrelevant for update, as we didn't have the criteria
      }

      return false;
    }


    //
    // Sends the row to the table as an insert.

    function insert_into_table( $db, $skip_checks = false )
    {
      if( $this->table )
      {
        return $this->table->insert($db, $this->fields, $skip_checks || $this->checked);
      }

      return false;
    }


    //
    // Identical to insert_into_table(), except replaces any existing record.

    function replace_into_table( $db, $skip_checks = false )
    {
      if( $this->table )
      {
        return $this->table->insert($db, $this->fields, $skip_checks || $this->checked, $replace = true);
      }

      return false;
    }


    //
    // Sends the row to the table as criteria for a delete. Be very careful with this.

    function delete_from_table( $db )
    {
      if( $this->table )
      {
        return $this->table->delete($db, $this->fields);
      }

      return false;
    }

    function delete_table( $db )
    {
      return $this->delete_from_table($db);
    }






    function get_insert_statement( $db, $replace = false )
    {
      return $this->table ? $this->table->get_insert_statement($db, $this->fields, $replace) : null;
    }

    function get_replace_statement( $db )
    {
      return $this->table ? $this->table->get_replace_statement($db, $this->fields) : null;
    }

    function get_delete_statement( $db )
    {
      return $this->table ? $this->table->get_delete_statement($db, $this->fields) : null;
    }

    function get_update_statement( $db, $criteria, $no_empty_criteria = false )
    {
      return $this->table ? $this->table->get_update_statement($db, $this->fields, $criteria, $no_empty_criteria) : null;
    }
  }
