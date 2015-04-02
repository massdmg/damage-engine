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

  require "eliminate_proper_subsets.php";

  class ChangeRecord
  {

    function validate_against( $db )
    {
      foreach( $this->instructions as $instruction )
      {
        $instruction->validate_against($db);
      }
    }

    function apply_to( $db, $skip_checks = false )
    {
      $results = array();
      foreach( $this->instructions as $instruction )
      {
        $results[$instruction->table->name] = $instruction->apply_to($db, $skip_checks);
      }

      return $results;
    }

    function to_sql_statements( $db )
    {
      $statements = array();
      foreach( $this->instructions as $instruction )
      {
        if( $statement = $instruction->to_sql_statement($db) )
        {
          $statements[] = $statement;
        }
      }

      return $statements;
    }



    //
    // Captures a ChangeRecord for "insert", "replace", "update", or "delete" operations using the
    // supplied data $pairs and the mapping information in $via (a SQLQuery or analogue is your
    // best bet, although you can also directly supply mapping information in the same format).

    static function capture( $operation, $pairs, $source_mappings, $schema, $original = null )
    {
      $map = static::map($pairs, $source_mappings, $schema);

      //
      // Convert the mappings from pair-name => field-name to field-name => value.

      $targets = array();
      foreach( $map as $table_name => $mappings )
      {
        $data = array();
        foreach( $mappings as $pair_name => $field_name )
        {
          $data[$field_name] = $pairs[$pair_name];
        }

        $table = $schema->get_table($table_name);
        $targets[$table_name] = array($table, $data);
      }

      //
      // Anything left is an appropriate target for change. Build the statement appropriately.

      $instructions = array();
      if( $targets )
      {
        switch( $operation )
        {
          case "insert":
          case "replace":
            foreach( $targets as $table_name => &$array )
            {
              list($table, $data) = $array;
              $instructions[] = new ChangeRecordInstruction_Insert($table, $data, $operation == "replace");
            }
            break;

          case "set":
            foreach( $targets as $table_name => &$array )
            {
              list($table, $data) = $array;

              $pk_names       = $table->get_pk_field_names();
              $pk_lookup      = array_flip($pk_names);
              $value_names    = array_diff(array_keys($data), $pk_names);
              $key_data       = array_intersect_key($data, $pk_lookup);
              $safe_for_set   = !empty($pk_names) && (count($value_names) + count($pk_names) == count($data));

              //
              // If we don't fully cover a key, we have a problem. Either the user is asking us to
              // insert on a table with an auto-increment key, or they are asking us to delete
              // something that used to be there. Figure out which (if any).

              $delete_instead = false;
              $criteria       = null;
              if( count(array_filter($key_data)) != count($pk_names) )
              {
                $criteria = array_intersect_key((array)$original, $pk_lookup);

                if( count($criteria) == count($pk_names) )
                {
                  if( count(array_filter($criteria)) == count($pk_names) )
                  {
                    $delete_instead = true;
                  }
                  else
                  {
                    $safe_for_set = false;
                  }
                }
                else
                {
                  abort("cannot determine set command for data that does not cover its key without covering \$original data");
                }
              }

              //
              // Information in hand, generate the appropriate instruction.

              if( $delete_instead )
              {
                $instructions[] = new ChangeRecordInstruction_Delete($table, $criteria);
              }
              elseif( $safe_for_set )
              {
                $instructions[] = new ChangeRecordInstruction_Set($table, $data, $pk_names);
              }
              else
              {
                $instructions[] = new ChangeRecordInstruction_Insert($table, $data, $operation == "insert");
              }
            }
            break;

          case "update":
            foreach( $targets as $table_name => &$array )
            {
              list($table, $data) = $array;

              $key_fields = $table->do_fields_cover_key(array_keys($data));
              $criteria   = array();
              foreach( $key_fields as $key_field )
              {
                $criteria[$key_field] = $data[$key_field];
                unset($data[$key_field]);
              }

              if( !empty($data) )
              {
                $instructions[] = new ChangeRecordInstruction_Update($table, $data, $criteria);
              }
            }
            break;

          case "delete":
            foreach( $targets as $table_name => &$array )
            {
              list($table, $data) = $array;

              $key_fields = $table->do_fields_cover_key(array_keys($data));
              $criteria   = array();
              foreach( $key_fields as $key_field )
              {
                $criteria[$key_field] = $data[$key_field];
              }

              $instructions[] = new ChangeRecordInstruction_Delete($table, $criteria);
            }
            break;

          default:
            abort("unsupported capture operation [$operation]");
        }
      }

      return new static($instructions);
    }


    //
    // Maps the supplied pairs back to source tables, using the supplied mappings.

    protected static function map( $pairs, $source_mappings, $schema )
    {
      is_object($source_mappings) and $source_mappings = $source_mappings->get_write_mappings();
      $source_mappings or abort("mappings are not available for this query");

      //
      // Organize our pairs by table $via the mappings.

      $potential_targets_by_table = array();
      foreach( $pairs as $name => $value )
      {
        if( array_key_exists($name, $source_mappings) )
        {
          foreach( $source_mappings[$name] as $source )
          {
            @list($table, $field) = explode(".", $source);
            @$potential_targets_by_table[$table][$name] = $field;
          }
        }
      }

      if( empty($potential_targets_by_table) )
      {
        throw new ChangeRecordMappingError("unable to find any viable mappings", $pairs, $source_mappings);
      }

      //
      // Eliminate any option that is a proper subset of another option, as the proper subset
      // comes from a joined table that isn't the appropriate target.

      $options = $potential_targets_by_table;
      eliminate_proper_subsets($potential_targets_by_table, $assoc = true);

      //
      // Eliminate any option that doesn't fully cover a key, as we only consider single row
      // changes per table.

      foreach( $potential_targets_by_table as $table_name => $mappings )
      {
        if( $table = $schema->get_table($table_name) )
        {
          if( !$table->do_fields_cover_key(array_values($mappings)) )
          {
            unset($potential_targets_by_table[$table_name]);
          }
        }
        else
        {
          abort("$table_name is not a table in the supplied database");
        }
      }

      if( empty($potential_targets_by_table) )
      {
        throw new ChangeRecordMappingError("unable to find any mappings that fully cover target table keys", $pairs, $source_mappings, $options);
      }


      return $potential_targets_by_table;
    }



    protected function __construct( $instructions )
    {
      $this->instructions = $instructions;
    }
  }
