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
// ================================================================================================
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

  require_once "convert_snake_object_to_camel_assoc.php";


  class MySQLConnection extends StatisticsGenerator
  {
    public $db;
    public $name;
    public $user;
    public $server;
    public $schema;
    public $connector;
    public $last_query;
    public static $query_in_error;
    

    //
    // Builds connection, given a mysql connection resource.

    function __construct( $connection, $connector, $server, $statistics_collector = null )
    {
      parent::__construct($statistics_collector);

      $this->db              = $this;
      $this->connection      = $connection;
      $this->connector       = $connector;
      $this->server          = $server;
      $this->name            = $connector->database;
      $this->user            = $connector->user;
      $this->schema          = new Schema($this);
      $this->print_queries   = false;
      $this->last_query      = "";
      $this->reads           = 0;
      $this->writes          = 0;
      $this->time            = 0;
      $this->next_unbuffered = false;
      $this->schema_epoch    = 0;
     
      $this->heavy_weight_mode         = false;
      $this->using_transactions        = false;
      $this->transaction_dirtied       = false;
      $this->before_commit_actions     = array();
      $this->after_commit_actions      = array();
      $this->before_rollback_actions   = array();
      $this->after_rollback_actions    = array();
      $this->after_transaction_actions = array();
      $this->on_next_query_results     = array();
      $this->on_next_query_structure_results = array();
      $this->on_next_query_results_filter_error = array();
    }

    function __sleep()
    {

    }


    function __call( $name, $args )
    {
      if( $this->schema && method_exists($this->schema, $name) )
      {
        return call_user_func_array(array($this->schema, $name), $args);
      }

      abort(sprintf("unknown method %s::%s", get_class($this), $name));
    }


    function get_schema_epoch()
    {
      if( !$this->schema_epoch )
      {
        $this->schema_epoch = time();

        if( $this->schema_epoch = $this->query_value("schema_epoch", 0, $this->get_schema_epoch_query()) )
        {
          debug($this->name . " schema epoch is " . $this->schema_epoch);
        }
      }

      return $this->schema_epoch;
    }
    
    
    function get_schema_epoch_query()
    {
      $query =
       "SELECT UNIX_TIMESTAMP(MAX(ifnull(UPDATE_TIME, CREATE_TIME))) as schema_epoch
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = ?
       ";
       
       return $this->format($query, $this->name);
    }


    function enable_heavy_weight_mode()
    {
      if( !$this->heavy_weight_mode )
      {
        debug("MySQL enabling heavy-weight mode");

        $last_query = $this->last_query;

        @$this->execute("SET SESSION sort_buffer_size=2097152");    # 2M
        @$this->execute("SET SESSION read_buffer_size=2097152");    # 2M

        $this->last_query = $last_query;
        $this->heavy_weight_mode = true;
      }
    }




  //=================================================================================================
  // SECTION: Queries
  

    //
    // Registers a filter to be applied to the results of the next query (only). You will receive
    // the $row and a flag indicating if it is the first row in the result set (so you can do 
    // setup work, if necessary). Return the $row.
  
    function on_next_query_results( $callback )
    {
      $this->on_next_query_results[] = $callback;
    }
    
    function clear_next_query_results_filters()
    {
      $this->on_next_query_results              = array();      
      $this->on_next_query_structure_results    = array();
      $this->on_next_query_results_filter_error = array();
    }
    
    function on_next_query_structure_results( $callback )
    {
      $this->on_next_query_structure_results[] = $callback;
    }
    
    function on_next_query_results_filter_error( $callback )
    {
      $this->on_next_query_results_filter_error[] = $callback;
    }
    

    //
    // Executes a query (with possible parameters) and returns the resource handle for direct
    // retrieval. Supports the same parameter arrangements as format().

    function query()
    {
      $args  = func_get_args();

      $function = "mysql_query";
      if( $this->next_unbuffered )
      {
        $function = "mysql_unbuffered_query";
        $this->next_unbuffered = false;
      }

      $callbacks = $this->on_next_query_results;
      $handlers  = $this->on_next_query_results_filter_error;
      $this->clear_next_query_results_filters();
      
      $query = $this->postprocess($this->format($args));
      $start = microtime(true);
      if( $handle = $function($query, $this->connection) )
      {
        $this->accumulate("db_time", microtime(true) - $start, "time");
        $this->increment("db_reads", "reads");

        return new MySQLQueryResults($handle, $this->name, $query, $callbacks, $handlers);
      }
      else
      {
        $this->report_error();
      }
      return null;
    }

    function query_unbuffered()
    {
      $this->next_unbuffered = true;
      return $this->query(func_get_args());
    }

    function query_raw()
    {
      if( $result = $this->query(func_get_args()) )
      {
        return $result->give_up_handle();
      }

      return null;
    }


    //
    // Executes a query (with possible parameters) and returns the whole set in an array of
    // objects. Supports the same parameter arrangements as format().

    function query_all()
    {
      $all  = array();
      $args = func_get_args();
      if( $results = $this->query($args) )
      {
        while( $row = $results->fetch() )
        {
          $all[] = $row;
        }

        $results->close();
      }

      return $all;
    }

    function query_all_in_legacy_format()
    {
      return convert_snake_object_to_camel_assoc($this->query_all(func_get_args()));
    }

    function query_all_as_array()
    {
      $all  = array();
      $args = func_get_args();
      if( $results = $this->query($args) )
      {
        while( $row = $results->fetch_array() )
        {
          $all[] = $row;
        }

        $results->close();
      }

      return $all;
    }


    //
    // Executes a query (with possible parameters) and returns the first row. Supports the same
    // parameter arrangements as format().

    function query_first()
    {
      $row  = null;
      $args = func_get_args();
      if( $results = $this->query_unbuffered($args) )
      {
        if($result = $results->fetch())
        {
          $row = $result;
        }
        $results->close();
      }

      return $row;
    }

    function query_first_in_legacy_format()
    {
      return convert_snake_object_to_camel_assoc($this->query_first(func_get_args()));
    }

    function query_first_as_array()
    {
      if( $object = $this->query_first(func_get_args()) )
      {
        return (array)$object;
      }

      return null;
    }



    //
    // Executes a query (with possible parameters) and returns true if any records are returned.
    // Supports the same parameter arrangements as format().

    function query_exists()
    {
      $args = func_get_args();
      $first = $this->query_first($args);
      return !empty($first);
    }


    //
    // Executes a query (with possible parameters) and returns a value from the first row. The
    // first parameter is the field name, and the second parameter is the default return value if
    // no row is found. After that, pass the same parameters you would to format().

    function query_value()
    {
      $args    = func_get_args();
      $args    = $this->extract($args);
      $field   = array_shift($args);
      $default = array_shift($args);
      if( $row = $this->query_first($args) )
      {
        return coerce_type($row->$field, $default);
      }

      return $default;
    }


    //
    // Executes a query and returns all values from a single column of the results. The first
    // parameter is the column name. After that, pass the same parameters you would to format().

    function query_column()
    {
      $args   = func_get_args();
      $args   = $this->extract($args);
      $field  = array_shift($args);
      $column = array();

      if( is_object($field) )
      {
        $field = $field->call();
      }

      if( $field )
      {
        if( $results = $this->query($args) )
        {
          while( $row = $results->fetch() )
          {
            $column[] = $row->$field;
          }

          $results->close();
        }
      }

      return $column;
    }


    //
    // Executes a query and returns a map of one column to another. The first parameter is the key
    // column, and the second is the value column. After that, pass the same parameters you would to
    // format(). Note: if you pass "*" as the value column, the whole row will be used as the value.

    function query_map()
    {
      $args        = func_get_args();
      $args        = $this->extract($args);
      $key_field   = array_shift($args);
      $value_field = array_shift($args);

      if( is_object($key_field) )
      {
        $key_field = $key_field->call();
      }

      if( is_array($value_field) && count($value_field) == 1 )
      {
        $value_field = $value_field[0];
      }

      $map = array();
      if( !empty($key_field) )
      {
        if( is_array($key_field) && count($key_field) == 1 )
        {
          $key_field = $key_field[0];
        }

        $key_count = is_array($key_field) ? count($key_field) : 1;

        if( $results = $this->query($args) )
        {
          while( $row = $results->fetch() )
          {
            //
            // Retrieve/construct the value to spec.

            $value = null;
            if( is_array($value_field) )
            {
              $value = new stdClass();
              foreach( $value_field as $name )
              {
                $value->$name = $row->$name;
              }
            }
            elseif( $value_field == "*" )
            {
              $value = $row;
            }
            else
            {
              $value = $row->$value_field;
            }

            //
            // Build the map along the key fields.

            switch( $key_count )
            {
              case 1:                                                $map[$row->$key_field]                                                     = $value; break;
              case 2: list($a, $b)                     = $key_field; $map[$row->$a][$row->$b]                                                   = $value; break;
              case 3: list($a, $b, $c)                 = $key_field; $map[$row->$a][$row->$b][$row->$c]                                         = $value; break;
              case 4: list($a, $b, $c, $d)             = $key_field; $map[$row->$a][$row->$b][$row->$c][$row->$d]                               = $value; break;
              case 5: list($a, $b, $c, $d, $e)         = $key_field; $map[$row->$a][$row->$b][$row->$c][$row->$d][$row->$e]                     = $value; break;
              case 6: list($a, $b, $c, $d, $e, $f)     = $key_field; $map[$row->$a][$row->$b][$row->$c][$row->$d][$row->$e][$row->$f]           = $value; break;
              case 7: list($a, $b, $c, $d, $e, $f, $g) = $key_field; $map[$row->$a][$row->$b][$row->$c][$row->$d][$row->$e][$row->$f][$row->$g] = $value; break;

              default: trigger_error("NYI: support for $key_count map keys");
            }
          }

          $results->close();
        }
      }

      return $map;
    }



    //
    // Builds a nested (tree) structure from a sorted, flat record set, based on a program you
    // supply. Useful for when you join across a sequence of one-to-many relationship, and want a
    // nested data structure back out.
    //
    // The program you supply is simply an associative array of levels, with each level listing a
    // key field and one or more value fields for that level. The level key (in the outermost
    // associative array) indicates the collection of child objects, once built.
    //
    // Example:
    //   $query = "
    //     SELECT a.id, a.name, b.id as room_id, b.room_name, b.size, c.id as item_id, c.item
    //     FROM a
    //     JOIN b on b.a_id = a.id
    //     JOIN c on c.b_id = b.id
    //     ORDER BY a.id, room_id, item_id
    //   ";
    //
    //   $program = array
    //   (
    //       0       => array("name", "id", "name", "age")      // The top-level map of name to record
    //     , "rooms" => array("room_name", "room_id", "size")   // An map of objects to appear in top->rooms
    //     , "items" => array(null, "item")                     // An array of strings to appear in top->room->items
    //   );
    //
    //   $structure = $db->query_structure($program, $query);
    //
    // NOTE: If you omit the ORDER BY clause, one will be added for you.

    function query_structure()
    {
      $args    = func_get_args();
      $args    = $this->extract($args);
      $program = array_shift($args);
      $filters = $this->on_next_query_structure_results;


      //
      // Flatten the program, if using the nested format.

      if( !is_array($program[0]) || (count($program) > 1 && isset($program[1])) )
      {
        $queue    = array($program);
        $program  = array();
        $this_key = 0;
        $next_key = null;

        while( !empty($queue) )
        {
          $level = array_shift($queue);
          $line  = array();
          foreach( $level as $key => $value )
          {
            if( is_array($value) )
            {
              $next_key = $key;
              array_unshift($queue, $value);
              break;
            }
            elseif( is_string($key) && is_string($value) )
            {
              $line[$key] = $value;
            }
            else
            {
              $line[] = $value;
            }
          }

          $program[$this_key] = $line;
          $this_key = $next_key;
          $next_key = null;
        }
      }

      //
      // Add any missing ORDER BY clause to the query.

      $args = $this->extract($args);
      if( is_string($args[0]) && preg_match("/^SELECT /i", $args[0]) )
      {
        $key_fields = array();
        foreach( $program as $level )
        {
          if( !empty($level[0]) )
          {
            $key_fields[] = $level[0];
          }
        }

        if( !preg_match("/ORDER BY/", $args[0]) )
        {
          $args[0] .= "\nORDER BY " . implode(", ", $key_fields);
        }
      }


      //
      // We are sort of knitting the flat record set back into a tree. $top is the real
      // result set to be returned, while the working edge of all (nested) levels is
      // maintained in $lasts.

      $top       = array();
      $lasts     = array();
      $previous  = null;
      $level_map = array_keys($program);
      foreach( $this->query_all($args) as $record )
      {
        //
        // First, pop off anything that is finished.

        is_null($previous) and $previous = $record;
        foreach( $level_map as $level_index => $level_name )
        {
          if( count($lasts) > $level_index )
          {
            foreach( $program[$level_name] as $property => $field )
            {
              if( $field && substr($field, 0, 1) != "+" && $previous->$field != $record->$field )
              {
                while( count($lasts) > $level_index )
                {
                  array_pop($lasts);
                }

                break 2;
              }
            }
          }
        }

        $previous = $record;


        //
        // Next, create objects along the current record.

        for( $level_index = count($lasts); $level_index < count($level_map); $level_index++ )
        {
          //
          // Get the level instructions. The first name is the key, the rest fields.

          $level     = $program[$level_map[$level_index]];
          $key       = $level[0];
          $fields    = array_slice($level, 1);
          $null_test = $key ? $key : @$fields[0];

          //
          // Create an object to hold this branches' data and fill it with data from this record.

          if( $null_test && is_null(@$record->$null_test) )
          {
            continue;
          }
          elseif( count($fields) == 0 )
          {
            $object = array();
          }
          elseif( count($fields) == 1 )
          {
            reset($fields);
            $field  = current($fields);
            $object = $record->$field;
          }
          else
          {
            $object = new stdClass;
            $key and $object->$key = $record->$key;

            if( $level_index + 1 < count($level_map) )
            {
              $child_container_name = $level_map[$level_index + 1];
              $object->$child_container_name = array();
            }

            foreach( $fields as $property => $field )
            {
              is_string($property) or $property = $field;

              if( substr($field, 0, 1) == "+" )
              {
                $name_field = substr($field, 1);
                if( isset($record->$name_field) && is_array($record->$name_field) )
                {
                  foreach( $record->$name_field as $field )
                  {
                    $object->$field = @$record->$field;
                  }
                }
              }
              else
              {
                $object->$property = @$record->$field;
              }
            }
          }

          //
          // Add the object to its container and make sure it can be found for the next level and
          // future records.

          $container =& $top;
          if( $level_index )
          {
            $parent_container_name = $level_map[$level_index];
            if( isset($lasts[$level_index - 1]->$parent_container_name) )
            {
              $container =& $lasts[$level_index - 1]->$parent_container_name;
            }
            else
            {
              $container =& $lasts[$level_index - 1];
            }
          }

          if( is_string($key) )
          {
            $container[$record->$key] = $object;
            $lasts[$level_index] =& $container[$record->$key];
          }
          elseif( $object )
          {
            $container[] = $object;
            $lasts[$level_index] =& $container[count($container) - 1];
          }
        }
      }

      //
      // Allow the world to adjust the finished products and return.
      
      $finished = array();
      foreach( $top as $key => $object )
      {
        foreach( $filters as $filter )
        {
          $object = Callback::do_call($filter, $object, $key);
        }
        
        $finished[$key] = $object;
      }

      return $finished;
    }


    function query_rows_found()    // For now, only works if your last query included SQL_CALC_FOUND_ROWS
    {
      return $this->query_value("count", 0, "SELECT FOUND_ROWS() as count");      
    }



  //=================================================================================================
  // SECTION: Transaction Management


    function enable_transactions()
    {
      if( !$this->using_transactions )
      {
        mysql_query("set session transaction isolation level repeatable read", $this->connection);   // Unfortunately, repeatable read is necessary for replication with InnoDB tables
        mysql_query("set completion_type = 1"                                , $this->connection);   // Chain transactions (one starts immediately when the previous one ends)
        mysql_query("set autocommit = 0"                                     , $this->connection);   // Disable autocommit
        mysql_query("start transaction"                                      , $this->connection);   // Start the first transaction

        $this->using_transactions        = true;
        $this->transaction_dirtied       = false;
        $this->before_commit_actions     = array();
        $this->after_commit_actions      = array();
        $this->before_rollback_actions   = array();
        $this->after_rollback_actions    = array();
        $this->after_transaction_actions = array();

        return true;
      }
      return false;
    }


    function in_transaction()
    {
      return $this->using_transactions && $this->transaction_dirtied;
    }

    function transaction_dirtied()
    {
      return $this->transaction_dirtied;
    }


    function begin_transaction( $or_join_existing = false )
    {
      $this->enable_transactions();

      if( !$this->transaction_dirtied || $or_join_existing )
      {
        return $this;
      }
      elseif( $scope = $this->connector->connect_for_writing($this->statistics_collector) )
      {
        $scope->enable_transactions();
        return $scope;
      }
      else
      {
        trigger_error("unable to start transaction on [$this->name]", E_USER_ERROR);
      }

      return null;
    }


    function commit_transaction( $scope = null )
    {
      $committed = false;
      if( !$scope || !is_object($scope) || !is_a($scope, __CLASS__) || ($scope === $this) )
      {
        do
        {
          foreach( $this->before_commit_actions as $action )
          {
            if( $action->always || $this->transaction_dirtied )
            {
              Callback::do_call_with_array($action->callback, array($this));
            }
          }

          Script::signal("before_transaction_commit", $this);

          mysql_query("commit", $this->connection) or abort();
          $this->transaction_dirtied = false;
          $committed                 = true;

          $actions = array_merge($this->after_commit_actions, $this->after_transaction_actions);
          $this->clear_transaction_actions();
          foreach( $actions as $action )
          {
            Callback::do_call_with_array($action, array($this));
          }

          Script::signal("after_transaction_commit", $this);

        } while(false);
      }

      return $committed;
    }


    function rollback_transaction( $scope = null )
    {
      $rolled_back = false;

      if( $this->connection and !$scope || !is_object($scope) || !is_a($scope, __CLASS__) || ($scope === $this) )
      {
        do
        {
          foreach( $this->before_rollback_actions as $action )
          {
            if( !Callback::do_call($action) )
            {
              break 2;
            }
          }

          Script::signal("before_transaction_rollback", $this);


          mysql_query("rollback", $this->connection) or $this->close();   // close() seems more appropriate than abort(), as rollback often happens just before discarding the connection
          $this->transaction_dirtied = false;
          $rolled_back               = true;

          $actions = array_merge($this->after_rollback_actions, $this->after_transaction_actions);
          $this->clear_transaction_actions();

          foreach( $actions as $action )
          {
            Callback::do_call($action);
          }

          Script::signal("after_transaction_rollback", $this);

        } while(false);
      }

      return $rolled_back;
    }


    //
    // Adds a callback to be executed before committing the current transaction. All before_commit
    // actions must return true or the commit will be aborted.

    function before_commit( $callback, $only_if_transaction_dirtied = false )
    {
      $this->enable_transactions();
      $this->before_commit_actions[] = (object)array("callback" => $callback, "always" => !$only_if_transaction_dirtied);
    }

    //
    // Adds a callback to be executed after committing the current transaction.

    function after_commit( $callback )
    {
      $this->enable_transactions();
      $this->after_commit_actions[] = $callback;
    }


    //
    // Adds a callback to be executed before rolling back the current transaction. All
    // before_rollback actions must return true or the rollback will be aborted.

    function before_rollback( $callback )
    {
      $this->enable_transactions();
      $this->before_rollback_actions[] = $callback;
    }


    //
    // Adds a callback to be executed after rolling back the current transaction.

    function after_rollback( $callback )
    {
      $this->enable_transactions();
      $this->after_rollback_actions[] = $callback;
    }


    //
    // Adds a callback to be executed after either committing or rolling back a transaction.

    function after_transaction( $callback )
    {
      $this->enable_transactions();
      $this->after_transaction_actions[] = $callback;
    }


    protected function clear_transaction_actions()
    {
      $this->before_commit_actions     = array();
      $this->after_commit_actions      = array();
      $this->before_rollback_actions   = array();
      $this->after_rollback_actions    = array();
      $this->after_transaction_actions = array();
    }




  //=================================================================================================
  // SECTION: Statement generation and execution


    //
    // Executes a statement (with possible parameters) and returns the number of affected rows (or
    // false if there was an error). Supports the same parameter arrangements as format().

    function execute()
    {
      $args  = func_get_args();
      $query = $this->postprocess($this->format($args));
      $start = microtime(true);
      if( mysql_query($query, $this->connection) )
      {
        $this->accumulate("db_time", microtime(true) - $start, "time");
        $this->increment("db_writes", "writes");

        return mysql_affected_rows($this->connection);
      }
      else
      {
        $this->report_error();
      }

      return false;
    }


    //
    // Executes an insert statement and returns the ID of the record inserted. Supports the same
    // parameter arrangements as format().

    function insert()
    {
      $args = func_get_args();
      if( $count = $this->execute($args) )
      {
        if( $id = $this->last_insert_id() )
        {
          return $id;
        }
        else
        {
          return true;
        }
      }
      
      return 0;
    }


    //
    // Returns the last auto_increment value created.

    function last_insert_id()
    {
      $id = $this->query_value("id", 0, "SELECT LAST_INSERT_ID() as id");
      $this->decrement("db_reads", "reads");
      return $id;
    }


    //
    // Builds an insert query using SQLInsert::build_sql().

    public function build_insert_sql( $table, $fields, $replace = false )
    {
      return SQLInsert::build_sql($table, $fields, $this, $replace);
    }

    //
    // Builds an update query using SQLUpdate::build_sql().

    public function build_update_sql( $table, $fields, $criteria )
    {
      if( !is_array($criteria) )
      {
        $criteria = array();

        is_object($table) or $table = $this->schema->get_table($table_name = $table) or Script::fail("unrecognized_database_table", "table", $table_name);
        $criteria_fields = @$table->get_pk_field_names() or Script::fail("mysql_table_no_pk", "table", $table->name);
        foreach( $criteria_fields as $field_name )
        {
          $value = array_fetch_value($fields, $field_name) or Script::fail("mysql_missing_criteria", "field_name", $field_name);
          $criteria[$field_name] = $value;
          unset($fields[$field_name]);
        }
      }

      return SQLUpdate::build_sql(is_string($table) ? $table : $table->name, $fields, $criteria, $this);
    }

    //
    // Builds a delete query using SQLDelete::build_sql().

    public function build_delete_sql( $table, $criteria )
    {
      return SQLDelete::build_sql($table, $criteria, $this);
    }

    //
    // Builds and executes an insert query using SQLInsert::build_sql(). Returns any
    // auto-generated id value.

    public function build_and_execute_insert( $table, $fields, $replace = false )
    {
      return $this->insert($this->build_insert_sql($table, $fields, $replace));
    }

    //
    // Builds and executes an update query using SQLUpdate::build_sql(). Returns the number of
    // records affected.

    public function build_and_execute_update( $table, $fields, $criteria )
    {
      return $this->execute($this->build_update_sql($table, $fields, $criteria));
    }

    //
    // Builds and executes a delete query using SQLUpdate::build_sql(). Returns the number of
    // records affected.

    public function build_and_execute_delete( $table, $criteria )
    {
      return $this->execute($this->build_delete_sql($table, $criteria));
    }


    public function insert_ignore_into( $table, $fields )
    {
      func_num_args() > 2 and $fields = array_pair_slice(func_get_args(), 1);
      return $this->build_and_execute_insert($table, $fields, $replace = null);
    }
    
    public function insert_into( $table, $fields )
    {
      func_num_args() > 2 and $fields = array_pair_slice(func_get_args(), 1);
      return $this->build_and_execute_insert($table, $fields, $replace = false);
    }
    
    public function replace_into( $table, $fields )
    {
      func_num_args() > 2 and $fields = array_pair_slice(func_get_args(), 1);
      return $this->build_and_execute_insert($table, $fields, $replace = true);
    }
    
    public function update_table( $table, $fields, $criteria )
    {
      if( func_num_args() > 3 )
      {
        $fields   = array_pair_slice(func_get_args(), 1);
        $criteria = false;
      }
      
      return $this->build_and_execute_update($table, $fields, $criteria);
    }
    
    public function delete_from( $table, $criteria )
    {
      func_num_args() > 2 and $criteria = array_pair_slice(func_get_args(), 1);
      return $this->build_and_execute_delete($table, $criteria);
    }




  //=================================================================================================
  // SECTION: Schema discovery

    //
    // Builds a Table definition for the named table.  Loads all fields and (unique) keys. Doesn't
    // add any foreign key references (at this time, as they are not currently represented in the
    // database).

    public function build_table( $table_name, $loader = null )
    {
      $loader or $loader = $this;

      $table  = null;
      $fields = $loader->query_map("Field", "*", "SHOW COLUMNS IN $table_name");
      if( !empty($fields) )
      {
        //
        // Figure out our keys first.

        $program = array("Key_name", array(null, "Column_name"));
        $keys    = $loader->query_structure($program, "SHOW INDEX IN $table_name WHERE Non_unique = 0");
        $pk      = array_key_exists("PRIMARY", $keys) ? $keys["PRIMARY"] : array();

        //
        // Pick our ID field. It has to be numeric and our primary key.

        $id_field = null;
        $id_is_autoincrement = false;

        if( !empty($pk) )
        {
          if( count($pk) == 1 && is_numeric(strpos($fields[$pk[0]]->Type, "int(")) )
          {
            $id_field = $pk[0];
            $id_is_autoincrement = ($fields[$id_field]->Extra == "auto_increment");
          }
        }

        //
        // Define the table.

        $table = new Table($table_name, $id_field, $id_is_autoincrement);

        //
        // Add the non-ID fields.

        foreach( $fields as $name => $data )
        {
          if( $name != $id_field )
          {
            $type     = preg_split('/[ (]/', $data->Type, 2);
            $type     = array_shift($type);
            $not_null = ($data->Null == "NO");
            $unsigned = is_numeric(strpos($data->Type, "unsigned"));
            $length   = preg_match('/\(([^)]*)\)/', $data->Type, $m) ? $m[1] : 0;
            $default  = $data->Default;
            $default_is_now = ($default == "CURRENT_TIMESTAMP");

            switch($type)
            {
              case "tinyint":
                if( $length == 1 && $unsigned )
                {
                  $table->define_field($name, $default == 1, $not_null ? "as_boolean" : "as_nullable_boolean");
                  break;
                }
                // fallthrough //

              case "int":
                $table->define_field($name, is_null($default) ? null : 0 + $default, $not_null ? "as_integer" : "as_nullable_integer");

                if( $length < 4 )
                {
                  $upper = pow(2, (8 * $length) - ($unsigned ? 0 : 1)) - 1;
                  $lower = $unsigned ? 0 : -($upper + 1);
                  $table->add_check($name, "between", $lower, $upper);
                }
                elseif( $unsigned )
                {
                  $table->add_check($name, "min", 0);
                }
                break;

              case "decimal":
                list($digits, $right) = explode(",", $length);
                $table->define_field($name, 0.0 + $default, "as_real");
                $table->add_filter($name, "real_to_decimal", $right);
                break;

              case "timestamp":
              case "datetime":
                $table->define_field($name, $default_is_now ? time() : 0, "as_datetime");
                $table->add_filter($name, $not_null ? "null_to_epoch" : "epoch_to_null");
                $default_is_now and $table->add_filter($name, "epoch_to_now" );
                break;

              case "date":
                $table->define_field($name, $default_is_now ? time() : 0, "as_date");
                $table->add_filter($name, $not_null ? "null_to_epoch" : "epoch_to_null");
                $default_is_now and $table->add_filter($name, "epoch_to_now" );
                break;

              case "char":
              case "varchar":
                $table->define_field($name, "");
                $table->add_check($name, "max_length", $length);
                break;

              default:
                $table->define_field($name, "");
                break;
            }
            
            if( !$not_null )
            {
              $table->set_nullable($name);
            }
          }
        }

        //
        // Add the keys.

        foreach( array_keys($keys) as $key_name )
        {
          $key_fields = $keys[$key_name];
          if( count($key_fields) == 1 )
          {
            $key_field = $key_fields[0];
            if( $key_field != $id_field )
            {
              $table->add_check($key_field, "unique");
            }
          }
          else
          {
            $table->add_check($key_fields, "unique");
          }
        }
      }

      return $table;
    }




  //=================================================================================================
  // SECTION: Miscellaneous


    function get_last_errno()
    {
      return mysql_errno($this->connection);
    }

    function get_last_error()
    {
      return mysql_error($this->connection);
    }


    function format_in_clause( $elements, $type = "string", $include_in = true )
    {
      $list = array();
      foreach( $elements as $element )
      {
        $list[] = $this->format_parameter($element, $type);
      }

      return sprintf("%s(%s)", $include_in ? "in " : "", empty($list) ? "null" : implode(", ", $list));
    }



    //
    // Formats a query, by replacing all ? markers with the properly escaped and quoted parameters.
    //
    // Note that you can pass parameters in just about any way that is convenient:
    //  - a parameter list
    //  - an array
    //  - a query and an array of parameters
    //  - etc.

    function format()
    {
      $args  = func_get_args();
      $args  = $this->extract($args);
      $query = array_shift($args);
      $args  = $this->extract($args);

      while( is_object($query) )
      {
        if( is_a($query, "Callback") )
        {
          $query = $query->call();
        }
        elseif( is_a($query, "SQLQuery") || is_a($query, "SQLQuerySimplified") )
        {
          $args  = $query->parameters + $args;
          $query = $query->to_string();
        }
        else
        {
          trigger_error("NYI: support for " . get_class($query) . " queries", E_USER_ERROR);
          return null;
        }
      }

      if( empty($args) )
      {
        return $query;
      }
      else
      {
        if( is_string(key($args)) )
        {
          $this->named_parameters = $args;
          $callback = array($this, "format_named_parameter");
          $query    = preg_replace_callback('/(?<!\\\\){(\w+)(?::(\w+))?}/', $callback, $query);
          unset($this->named_parameters);
        }
        else
        {
          $safe  = array_map(array($this, "format_parameter"), $args);
          $query = vsprintf(str_replace("?", "%s", str_replace("%", "%%", $query)), $safe);
        }

        $query = str_replace("<> null", "is not null", $query);
        $query = str_replace("!= null", "is not null", $query);

        $parts = preg_split('/\sWHERE\s/i', $query, 2);
        if( count($parts) == 2 )
        {
          $query = $parts[0] . " WHERE " . str_replace( "= null", "is null", $parts[1]);
        }

        return $query;
      }
    }

    function format_time( $time = null )
    {
      if( preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $time) )
      {
        return $time;
      }
      else
      {
        is_string($time) and $time = strtotime($time);
        is_null($time) and $time = time();
        return date("Y-m-d H:i:s", $time);
      }
    }

    function format_datetime( $time = null )
    {
      return $this->format_time($time);
    }

    function format_date( $time = null )
    {
      if( preg_match('/\d{4}-\d{2}-\d{2}/', $time) )
      {
        return $time;
      }
      else
      {
        is_string($time) and $time = strtotime($time);
        is_null($time) and $time = time();
        return date("Y-m-d", $time);
      }
    }

    function format_parameter( $arg, $type = null )
    {
      if( is_null($arg) )
      {
        return "null";
      }
      elseif( $type == "time" || $type == "datetime" )
      {
        return $this->format_string_parameter($this->format_time($arg));
      }
      elseif( $type == "date" )
      {
        return $this->format_string_parameter($this->format_date($arg));
      }
      elseif( is_bool($arg) )
      {
        return ($arg ? 1 : 0);
      }
      elseif( $type == "string" )
      {
        return $this->format_string_parameter($arg);
      }
      elseif( $type == "literal" )
      {
        return $arg;
      }
      elseif( $type == "float" or $type == "real" )
      {
        return (float)$arg;
      }
      elseif( $type == "integer" || (is_integer($arg) && preg_match('/^\d+$/', "$arg")) )
      {
        return (int)$arg;
      }
      elseif( is_numeric($arg) && preg_match('/^\d*\.\d*$/', $arg) )
      {
        return $arg;  // We don't reformat float/decimal data, as it it might lose precision
      }
      elseif( substr($type, 0, 8) == "list_of_" and $subtype = substr($type, 8) )
      {
        return $this->format_in_clause((array)$arg, $subtype, $include_in = false);
      }
      else
      {
        return $this->format_string_parameter($arg);
      }

      return $arg;
    }

    function format_string_parameter( $arg )
    {
      return sprintf("'%s'", $this->escape($arg));
    }

    function format_named_parameter( $matches )
    {
      $formatted = @$matches[0];
      if( is_array($this->named_parameters) )
      {
        if( $name = @$matches[1] )
        {
          if( array_key_exists($name, $this->named_parameters) )
          {
            $formatted = $this->format_parameter($this->named_parameters[$name], $type = @$matches[2]);
          }
          else
          {
            trigger_error("query string contains named parameter [$name] which wasn't supplied", E_USER_NOTICE);
          }
        }
      }

      return $formatted;
    }

    function print_queries( $format = "pre" )
    {
      $this->print_queries = $format;
    }

    function close()
    {
      if( $this->connection )
      {
        @mysql_close($this->connection);
        $this->connection = 0;
      }
    }

    function escape( $string, $for_like = false )
    {
      $escaped = mysql_real_escape_string($string, $this->connection);
      $for_like and $escaped = str_replace("%", "\\%", str_replace("_", "\\_", $escaped));
      return $escaped;
    }

    function extract( $array )
    {
      while( is_array($array) && count($array) == 1 && array_key_exists(0, $array) && is_array($array[0]) )
      {
        $array = $array[0];
      }

      return $array;
    }

    function postprocess( $query )
    {
      if( empty($query) )
      {
        trigger_error("did you forget to pass a query?", E_USER_ERROR);
      }

      $this->print_query($query);
      $this->last_query          = $query;
      $this->transaction_dirtied = true;

      debug(strlen($this->last_query) > 5000 ? (substr($this->last_query, 0, 5000) . " . . .") : $this->last_query);
      
      if( strpos($query, "ORDER BY") || strpos($query, "GROUP BY") )
      {
        $this->enable_heavy_weight_mode();
      }

      return $query;
    }

    function report_error()
    {
      if( $errno = mysql_errno($this->connection) )
      {
        if( error_reporting() & (E_USER_ERROR | E_ERROR) )
        {
          throw new MySQLConnectionException($message = mysql_error($this->connection), $errno, $this->last_query);
        }
        else
        {
          warn("MySQLConnection error $errno:", mysql_error($this->connection), $this->last_query);
        }
      }
    }

    function print_query( $query )
    {
      switch( $this->print_queries )
      {
        case "pre":
          require_once path("../html.php", __FILE__);
          print code_block($query, true, ".debug");
          break;
        case "comment":
          require_once path("../html.php", __FILE__);
          print "<!-- ";
          print text($query);
          print " -->\n";
          break;
        case "text":
          print $query;
          print "\n";
          break;
        case "run":
          $this->record("queries", $query);
          break;
      }

      return $query;
    }
  }


  class MySQLQueryResults
  {
    function __construct( $handle, $database_name, $query, $filters, $error_filters = array() )
    {
      $this->handle        = $handle;
      $this->database_name = $database_name;
      $this->query         = $query;
      $this->filters       = $filters;
      $this->is_first_row  = true;
      $this->error_filters = $error_filters;
    }
    
    function __destruct()
    {
      $this->close();
    }
    
    function give_up_handle()
    {
      $handle = $this->handle;
      $this->handle = 0;
      
      return $handle;
    }

    function count()
    {
      return mysql_num_rows($this->handle);
    }

    function fetch()
    {
    retry:
      if( $row = mysql_fetch_object($this->handle) )
      {
        try
        {
          $row = Script::filter("query_result", $row, $this->database_name, $this->query, $this->is_first_row, $this);
          foreach( $this->filters as $filter )
          {
            if( $row )
            {
              $row = Callback::do_call_with_array($filter, array($row, $this->is_first_row));
            }
          }
          
          if( !$row )
          {
            goto retry;
          }
        }
        catch( Exception $e )
        {
          $e = Script::filter("query_result_filter_error", $e, $row, $this->database_name, $this->query, $this->is_first_row);
          foreach( $this->error_filters as $filter )
          {
            if( is_object($e) && is_a($e, "Exception") )
            {
              $e = Callback::do_call_with_array($filter, array($e, $row, $this->database_name, $this->query, $this->is_first_row));
            }
          }

          if( is_object($e) && is_a($e, "Exception") )
          {
            throw $e;
          }
          else if( $e === "skip" )
          {
            goto retry;
          }
        }
      }
      
      $this->is_first_row = false;

      return $row;
    }

    function fetch_array()
    {
      if( $filters )
      {
        abort("NYI");
      }
      
      return mysql_fetch_array($this->handle);
    }
    
    function reject_current_row()
    {
      
    }

    function close()
    {
      if( $this->handle )
      {
        @mysql_free_result($this->handle);
        $this->handle = 0;
      }
    }
  }


  class MySQLConnectionException extends Exception
  {
    public $query;

    function __construct( $message, $code, $query, $previous = null )
    {
      parent::__construct($message, $code, $previous);
      $this->query = $query;
    }
  }
  
  
  class MySQLCommitCallback
  {
    function __construct( $callback, $always )
    {
      $this->callback = $callback;
      $this->always   = $only_if_transaction_dirtied;
    }
  }
