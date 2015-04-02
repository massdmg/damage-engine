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
//=============================================================================
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


  //
  // Captures the definition of a table and provides services thereon.

  class Table
  {
    function __construct( $name, $id_field = "id", $id_is_autoincrement = true )
    {
      $this->name       = $name;
      $this->id_field   = $id_field;
      $this->types      = array();      // $field => string|boolean|real|integer
      $this->defaults   = array();      // $field => default value
      $this->nulls      = array();      // $field => flag
      $this->filters    = array();      // $field => list of filters
      $this->checks     = array();      // list of checks, first element of each is a field or list of fields, second is check name
      $this->keys       = array();
      $this->current    = null;
      $this->custom_filters = array();
      $this->maps       = array();
      $this->query      = null;
      $this->defined    = false;
      $this->snake_name = convert_pascal_to_snake_case($this->name);

      $this->id_is_autoincrement = $id_field ? $id_is_autoincrement : false;
      if( !empty($id_field) )
      {
        $this->define_field($id_field, 0);
        $this->keys[] = array($id_field);
      }
    }

    function get_field_names( $include_autoincrement_id = true )
    {
      $names = array_keys($this->types);
      if( !$include_autoincrement_id && $this->id_field && $this->id_is_autoincrement )
      {
        $names = array_diff($names, array($this->id_field));
      }

      return $names;
    }

    function get_pk_field_names()
    {
      return empty($this->keys) ? $this->get_field_names() : $this->keys[0];
    }

    function defined()
    {
      return $this->defined;
    }

    function define_field( $field, $default /* type override ("as_<type>"), "nullable", filters, checks */ )
    {
      $type = null;
      $this->defaults[$field] = $default;
      $this->filters[$field]  = array();
      $this->nulls[$field]    = false;

          if( is_string($default)  ) { $type = "string" ; }
      elseif( is_bool($default)    ) { $type = "boolean"; }
      elseif( is_real($default)    ) { $type = "real"   ; }
      elseif( is_integer($default) ) { $type = "integer"; }

      if( func_num_args() > 2 )
      {
        $args  = func_get_args();
        $extra = array_slice($args, 2);
        
        if( !empty($extra) && strpos($extra[0], "as_") === 0 )
        {
          $type = substr(array_shift($extra), 3);
        }

        foreach( $extra as $item )
        {
          is_array($item) or $item = array($item);
          array_unshift($item, $field);
          call_user_func_array(array($this, strpos($item[1], "_to_") ? "add_filter" : "add_check"), $item);
        }
      }

      $this->types[$field] = $type;
      array_unshift($this->filters[$field], array("to_$type"));
    }
    
    function set_nullable( $field )
    {
      if( array_key_exists($field, $this->types) )
      {
        $this->nulls[$field] = true;
      }
    }

    function add_filter( /* $field, $filter, ... */ )
    {
      $args   = func_get_args();
      $field  = array_shift($args);
      $filter = $args[0];

      if( method_exists($this, "filter_$filter") )
      {
        $this->filters[$field][] = $args;
      }
      else
      {
        trigger_error("Table filter $filter not defined", E_USER_ERROR);
      }
    }

    function add_check( /* $field, $check, ... */ )
    {
      $args = func_get_args();
      $check = $args[1];
      if( method_exists($this, "check_$check") )
      {
        $this->checks[] = $args;

        if( $args[1] == "unique" )
        {
          $fields = $args[0];
          $this->keys[] = is_array($fields) ? $fields : array($fields);
        }
      }
      else
      {
        trigger_error("Table check $check not defined", E_USER_ERROR);
      }
    }

    function add_custom_filter( $callback )
    {
      $this->custom_filters[] = $callback;
    }

    function has_field( $name )
    {
      return array_key_exists($name, $this->defaults);
    }
    
    function is_nullable( $name )
    {
      return $this->has_field($name) && $this->nulls[$name];
    }

    function pick_fields_from( $map )
    {
      $selected = array();
      foreach( $map as $field => $value )
      {
        if( array_key_exists($field, $this->defaults) )
        {
          $selected[$field] = $map[$field];
        }
      }

      return $selected;
    }

    function do_fields_cover_key( $fields )
    {
      if( empty($this->keys) )
      {
        $key = $this->get_field_names();
        return count(array_intersect($key, $fields)) == count($key) ? $key : false;
      }
      else
      {
        foreach( $this->keys as $key )
        {
          if( count(array_intersect($key, $fields)) == count($key) )
          {
            return $key;
          }
        }

        if( $this->id_is_autoincrement )
        {
          return array($this->id_field);
        }
      }

      return false;
    }


  //===============================================================================================
  // SECTION: Query generation.

    function to_query()
    {
      if( !$this->query )
      {
        $this->query = new SQLQuery($this);
      }

      return $this->query;
    }

    function get_insert_statement( $db, $fields, $replace = false )
    {
      return SQLInsert::build_sql($this->name($db), $fields, $db, $replace);
    }

    function get_replace_statement( $db, $fields )
    {
      return $this->get_insert_statement($db, $fields, $replace = true);
    }

    function get_delete_statement( $db, $criteria )
    {
      return SQLDelete::build_sql($this->name($db), $criteria, $db);
    }

    function get_update_statement( $db, $fields, $criteria, $no_empty_criteria = false )
    {
      return SQLUpdate::build_sql($this->name($db), $fields, $criteria, $db, $no_empty_criteria);
    }

    function get_set_statement( $db, $fields, $key_names )
    {
      return SQLSet::build_sql($this->name($db), $fields, $key_names, $db);
    }




  //===============================================================================================
  // SECTION: Save routines.

    //
    // Saves data to the table. Automatically calls delete(), update(), or insert() appropriately
    // for your parameter list. $sets and $criteria should both be associative arrays.

    function save( $db, $sets, $criteria, $skip_checks = false )
    {
      if( empty($sets) )
      {
        return empty($criteria) ? 0 : $this->delete($db, $criteria, $skip_checks);
      }
      elseif( !empty($criteria) )
      {
        return $this->update($db, $sets, $criteria, $skip_checks);
      }
      else
      {
        return $this->insert($db, $sets, $skip_checks);
      }
    }


    //
    // Deletes all records matching the criteria. Returns the count.

    function delete( $db, $criteria, $skip_checks = false )
    {
      $statement = $this->get_delete_statement($db, $criteria);

      if( $count = $db->execute($statement) )
      {
        $this->signal_change($criteria, $count);
      }
        
      return $count;
    }


    //
    // Inserts a record into the table. Runs checks unless you ask them not to be. Returns the new
    // ID, if appropriate; true or false otherwise.

    function insert( $db, $fields, $skip_checks = false, $replace = false )
    {
      $table    = $this->name($db);
      $id_field = $this->id_field;
      $locked   = false;

      //
      // First, filter and check the data.

      $fields = $this->filter($db, $fields, $canonicalize = true);
      $skip_checks or $this->check($db, $fields, false);

      //
      // Ensure any ID field is filled in properly.

      if( !empty($id_field) )
      {
        if( array_key_exists($id_field, $fields) )
        {
          if( $fields[$id_field] < 1 )
          {
            unset($fields[$id_field]);
          }
        }

        if( !array_key_exists($id_field, $fields) && !$this->id_is_autoincrement )
        {
          $locked = true;
          $db->execute("LOCK TABLES $table");
          $fields[$id_field] = $db->query_value("id", 0, "SELECT ifnull(MAX(s.$id_field), 0) + 1 as id FROM $table s");
        }
      }

      //
      // Assemble and run the query.

      $statement = $this->get_insert_statement($db, $fields, $replace);
      if( $changed = $db->execute($statement) )
      {
        $this->signal_change($fields, $changed);
      }

      //
      // Unlock the table, if necessary.

      if( $locked )
      {
        $db->execute("UNLOCK TABLES $table");
      }

      //
      // Return appropriately.

      if( $changed == 1 )
      {
        if( !empty($id_field) )
        {
          return $this->id_is_autoincrement ? $db->last_insert_id() : 0 + $fields[$id_field];
        }
        else
        {
          return true;
        }
      }
      else
      {
        return false;
      }
    }



    //
    // Updates all records matching the criteria. Returns the count (actually changed, not matching).
    // Please note: checks will be skipped if your update would affect more than one row, as it's
    // just too complicated for this code to deal with.

    function update( $db, $fields, $criteria, $skip_checks = false )
    {
      $table = $this->name($db);

      //
      // Disable checks unless the criteria cover at least one key.

      if( !$skip_checks )
      {
        $unique  = false;
        $covered = array_keys($criteria);
        foreach( $this->keys as $key )
        {
          if( array_intersect($key, $covered) )
          {
            $unique      = true;
            $skip_checks = false;
            break;
          }
        }
      }

      //
      // First, filter and check the data. We'll need any data missing from the fields,
      // in order to properly run checks. As a result, things get a little messy.

      if( true || $skip_checks )
      {
        $fields = $this->filter($db, $fields);
      }
      else
      {
        $updates  = $this->filter($db, $fields);
        $existing = $this->load($db, $criteria);
        foreach( $this->types as $field => $type )
        {
          if( !isset($fields[$field]) )
          {
            $fields[$field] = $existing->$field;
          }
        }

        $fields = $this->filter($db, $fields);
                  $this->check($db, $fields, false);
        $fields = $updates;  // With checks done, restore the original set
      }


      //
      // Generate the set clauses for the query.

      $sets = array();
      foreach( $fields as $field => $value )
      {
        if( isset($this->types[$field]) )
        {
          if( $field != $this->id_field )
          {
            $sets[] = $db->format("`$field` = ?", $value);
          }
        }
      }

      //
      // Assemble and run the query.

      $statement = $this->get_update_statement($db, $fields, $criteria, $no_empty_criteria = false);
      
      if( $changed = $db->execute($statement) )
      {
        $this->signal_change($criteria, $changed);
      }

      return $changed;
    }



    function set( $db, $fields, $key_names, $skip_checks = false, $replace = false )
    {
      $table    = $this->name($db);
      $id_field = $this->id_field;
      $locked   = false;

      //
      // First, filter and check the data.

      $fields = $this->filter($db, $fields, $canonicalize = false);
      $skip_checks or $this->check($db, $fields, false);

      //
      // Assemble and run the query.

      $statement = $this->get_set_statement($db, $fields, $key_names);
      if( $changed = $db->execute($statement) )
      {
        $this->signal_change($fields, $changed);
      }

      //
      // Return appropriately.

      if( $changed == 1 )
      {
        if( !empty($id_field) )
        {
          return $this->id_is_autoincrement ? $db->last_insert_id() : 0 + $fields[$id_field];
        }
        else
        {
          return true;
        }
      }
      else
      {
        return false;
      }
    }



  //===============================================================================================
  // SECTION: Filters.

    function filter_to_string ( $value, $parameters ) { return "$value";        }
    function filter_to_boolean( $value, $parameters ) { return (bool)$value;    }
    function filter_to_real   ( $value, $parameters ) { return is_numeric($value) ? $value : (float)$value; }
    function filter_to_integer( $value, $parameters ) { return (integer)$value; }
    function filter_to_time   ( $value, $parameters ) { return $value;          }

    function filter_to_nullable_boolean( $value, $parameters ) { return is_null($value) ? $value : $this->filter_to_boolean($value, null); }
    function filter_to_nullable_integer( $value, $parameters ) { return is_null($value) ? $value : $this->filter_to_integer($value, null); }

    function filter_to_date( $value, $parameters )
    {
      if( preg_match('/\d{4}-\d{2}-\d{2}/', $value) )
      {
        return $value;
      }
      else
      {
        $time = is_numeric($value) ? $value : strtotime($value);
        return date("Y-m-d", $time);
      }
    }

    function filter_to_datetime( $value, $parameters )
    {
      if( preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) )
      {
        return $value;
      }
      else
      {
        $time = is_numeric($value) ? $value : strtotime($value);
        return date("Y-m-d H:i:s", $time);
      }
    }

    function filter_empty_to_null( $value, $parameters )
    {
      return empty($value) ? null : $value;
    }

    function filter_epoch_to_null( $value, $parameters )
    {
      return ($value == 0 || (is_string($value) && @strtotime($value) == 0)) ? null : $value;
    }

    function filter_epoch_to_now( $value, $parameters )
    {
      return ($value == 0 || (is_string($value) && @strtotime($value) == 0)) ? date("Y-m-d H:i:s") : $value;
    }

    function filter_null_to_epoch( $value, $parameters )
    {
      return is_null($value) ? date("Y-m-d H:i:s", 0) : $value;
    }
    

    function filter_to_lowercase( $value, $parameters ) { return strtolower($value); }
    function filter_to_uppercase( $value, $parameters ) { return strtoupper($value); }
    function filter_to_ucwords  ( $value, $parameters ) { return ucwords($value);    }

    function filter_one_of( $value, $parameters )
    {
      $options = $parameters[0];
      return in_array($value, $options) ? $value : $options[0];
    }

    function filter_real_to_decimal( $value, $parameters )
    {
      $right = $parameters[0];
      return sprintf("%." . $right . "F", $value);
    }



  //===============================================================================================
  // SECTION: Checks.

    function check_read_only( $record, $subject, $db, $parameters )
    {
      return false;
    }

    function check_not_null( $record, $subject, $db, $parameters )
    {
      return array_key_exists($subject, $record) && !is_null($record[$subject]);
    }

    function check_not_empty( $record, $subject, $db, $parameters )
    {
      return !empty($record[$subject]);
    }

    function check_not_epoch( $record, $subject, $db, $parameters )
    {
      return !($record[$subject] == 0 || $record[$subject] == "1969-12-31" || $record[$subject] == "1969-12-31 19:00:00");
    }

    function check_unique( $record, $subject, $db, $parameters )
    {
      return true;

      // Removed by Chris Poirier on Apr 25, 2012: due to changes in the overall Table code,
      // (we no longer require every table have an ID field), this code will no longer work.
      //
      // $criteria = array();
      // if( is_array($subject) )
      // {
      //   $fields = $subject;
      //   foreach( $fields as $field )
      //   {
      //     $criteria[] = $db->format("`$field` = ?", $record[$field]);
      //   }
      // }
      // else
      // {
      //   $field = $subject;
      //   $criteria[] = $db->format("`$field` = ?", $record[$field]);
      // }
      //
      // $table = $this->name($db);
      // $found = $db->query_first("SELECT * FROM $table WHERE " . implode(" and ", $criteria));
      //
      // if( $found )
      // {
      //   if( $this->id_field && array_key_exists($this->id_field, $record) )
      //   {
      //     return $record[$this->id_field] = $found[$this->id_field];
      //   }
      //   else
      //   {
      //     trigger_error("NYI: how do we check unique without an id field?", E_USER_ERROR);
      //   }
      // }
      // else
      // {
      //   return true;
      // }
    }

    function check_min_date( $record, $subject, $db, $parameters )
    {
      $date = array_shift($parameters);
      return strtotime($record[$subject]) > strtotime($date);
    }

    function check_max_length( $record, $subject, $db, $parameters )
    {
      return strlen($record[$subject]) <= $parameters[0];
    }

    function check_min_length( $record, $subject, $db, $parameters )
    {
      return strlen($record[$subject]) >= $parameters[0];
    }

    function check_max( $record, $subject, $db, $parameters )
    {
      return is_null($record[$subject]) ? true : $record[$subject] <= $parameters[0];
    }

    function check_min( $record, $subject, $db, $parameters )
    {
      return is_null($record[$subject]) ? true : $record[$subject] >= $parameters[0];
    }

    function check_between( $record, $subject, $db, $parameters )
    {
      return is_null($record[$subject]) ? true : $record[$subject] >= $parameters[0] && $record[$subject] <= $parameters[1];
    }

    function check_one_of( $record, $subject, $db, $parameters )
    {
      return in_array($record[$subject], $parameters);
    }

    function check_member_of( $record, $subject, $db, $parameters )
    {
      list($referenced_table, $referenced_field) = $parameters;

      $criteria = array();
      if( is_array($subject) )
      {
        foreach( $subject as $index => $field )
        {
          $referenced_name = $referenced_field[$index];
          if( is_null($record[$field]) )
          {
            return true;
          }
          $criteria[] = $db->format("`$referenced_name` = ?", $record[$field]);
        }
      }
      else
      {
        $field = $subject;
        if( !array_key_exists($field, $record) || is_null($record[$field]) )
        {
          return true;
        }
        $criteria[] = $db->format("`$referenced_field` = ?", $record[$field]);
      }

      $table = isset($db->$referenced_table) ? $db->$referenced_table : $referenced_table;
      return $db->query_exists("SELECT * FROM $table WHERE " . implode(" and ", $criteria));
    }



  //===============================================================================================
  // SECTION: Internals.

    function name( $db )
    {
      $name = $this->name;
      return isset($db->$name) ? $db->$name : $name;
    }

    
    function signal_change( $data, $count )
    {
      Script::signal("table_changed", $this->name, $this, $data, $count);
      Script::signal($this->snake_name . "_table_changed", $this, $data, $count);
    }


    function filter( $db, $fields, $canonicalize = false )
    {
      if( $canonicalize )
      {
        foreach( $this->types as $field => $type )
        {
          if( !isset($fields[$field]) || is_null($fields[$field]) )
          {
            $fields[$field] = $this->defaults[$field];
          }
        }
      }

      foreach( $fields as $field => $value )
      {
        if( isset($this->filters[$field]) )
        {
          foreach( $this->filters[$field] as $parameters )
          {
            $filter = array_shift($parameters);
            $method = "filter_$filter";
            $fields[$field] = $this->$method($fields[$field], $parameters);
          }
        }
      }

      foreach( $this->custom_filters as $custom_filter )
      {
        $fields = call_user_func($custom_filter, $fields);
      }

      $fields = Script::filter("table_fields", $fields, $this);

      return $fields;
    }


    function check( $db, $fields, $filter = true, $canonicalize = false, $throw = true )
    {
      $filter and $fields = $this->filter($db, $fields, $canonicalize);

      $field_names = array_keys($fields);
      foreach( $this->checks as $parameters )
      {
        $subject = array_shift($parameters);
        $applies = (is_string($subject) && array_key_exists($subject, $fields)) || (is_array($subject) && count($subject) == count(array_intersect($field_names, $subject)));
        if( $applies )
        {
          $check   = array_shift($parameters);
          $method  = "check_$check";
          $result  = $this->$method($fields, $subject, $db, $parameters);

          if( $result !== true )
          {
            if( $throw )
            {
              throw new TableValidationCheckFailed($this->name, $check, $subject, $result);
            }
            else
            {
              return array("failed_check", $check, $subject, $result);
            }
          }
        }
      }

      return null;
    }


  }



  class TableValidationCheckFailed extends Exception
  {
    public $name;
    public $subject;
    public $result;

    function __construct( $table, $name, $subject, $result )
    {
      parent::__construct( "Table validation check $table.$subject $name failed");

      $this->name    = $name;
      $this->subject = $subject;
      $this->result  = $result;
    }
  }
