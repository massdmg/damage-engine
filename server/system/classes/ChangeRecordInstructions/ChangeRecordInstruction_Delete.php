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

  class ChangeRecordInstruction_Delete
  {
    function __construct( $table, $criteria )
    {
      $this->table    = $table;
      $this->criteria = $criteria;
    }

    function validate_against( $db )
    {
      return true;
    }

    function apply_to( $db, $skip_checks = false )
    {
      return $this->table->delete($db, $this->criteria);
    }

    function to_sql_statement( $db )
    {
      return $this->table->get_delete_statement($db, $this->criteria);
    }
  }
