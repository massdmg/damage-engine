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
  // Provides easy access to Tables and SQLQueries for a database schema. As a convenience, you
  // can get a SQLQuery or Table for any "TableName" directly, as:
  //   $schema->get_table_name_table();
  //   $schema->get_table_name_query();

  class Schema
  {
    function __construct( $db_or_name = null, $name = null )
    {
      if( is_object($db_or_name) )
      {
        $this->db   = $db_or_name;
        $this->name = $name ? $name : $this->db->name;
      }
      else
      {
        $this->db   = null;
        $this->name = $db_or_name;
      }

      $this->tables        = array();
      $this->base_queries  = array();
      $this->schema_epoch  = 0;
      $this->query_epoch   = 0;
      $this->table_library = null;
      $this->query_library = null;
    }


    function get_table( $name )
    {
      if( array_key_exists($name, $this->tables) )
      {
        return $this->tables[$name];
      }
      else
      {
        $this->initialize_libraries();
        if( $table = $this->table_library->get($name) )
        {
          $this->tables[$name] = $table;
          return $table;
        }
        elseif( $this->db )
        {
          if( $table = $this->db->build_table($name) )
          {
            $this->table_library->set($name, $table);
            $this->tables[$name] = $table;
            return $table;
          }
        }
      }

      return null;
    }


    function get_table_pk_fields( $table_name )
    {
      if( $table = $this->get_table($table_name) )
      {
        if( !empty($table->keys) )
        {
          return $table->keys[0];
        }
      }

      return null;
    }


    function get_base_query( $name )
    {
      if( array_key_exists($name, $this->base_queries) )
      {
        return $this->base_queries[$name];
      }
      else
      {
        $this->initialize_libraries();
        if( $query = $this->query_library->get($name) )
        {
          $this->base_queries[$name] = $query;
          return $query;
        }
        elseif( $table = $this->get_table($name) )
        {
          if( $query = $table->to_query() )
          {
            $this->query_library->set($name, $query);
            $this->base_queries[$name] = $query;
            return $query;
          }
        }
      }

      trigger_error("unrecognized table [$name] in [" . $this->name . "]", E_USER_NOTICE);
      return null;
    }




    function __call( $name, $args )
    {
      if( substr($name, 0, 4) == "get_" )
      {
        $components = explode("_", substr($name, 4));
        $method     = "get_" . array_pop($components);
        $name       = implode("", array_map("ucwords", $components));

        return $this->$method($name);
      }

      trigger_error(sprintf("unknown method %s::%s", get_class($this), $name), E_USER_ERROR);
      return null;
    }


    function build_data_handler_query( $table_name, $criteria, $type, $namespace, $name )
    {
      $parameters = array();
      $scope      = is_array($criteria) ? implode(",", array_keys($criteria)) : "primary key";
      $cache_key  = implode(":", array($type, $namespace, $name, "on($table_name.$scope)"));

      //
      // Try to get the built query from the library. If not present, generate it to match the
      // supplied $criteria (a map of field => value or a single value for the key column).

      $this->initialize_libraries();
      $query = $this->query_library->get($cache_key);
      if( !$query && ($query = $this->get_base_query($table_name)) )
      {
        $comparisons = array();
        if( is_array($criteria) )
        {
          foreach( $criteria as $name => $value )
          {
            $comparisons[] = "$name = ?";
            $parameters[]  = $value;
          }
        }
        elseif( $table = $this->get_table($table_name) )
        {
          $pk = $table->get_pk_field_names();
          if( $pk && count($pk) == 1 )
          {
            $name = $pk[0];
            $comparisons[] = "$name = ?";
            $parameters[]  = $criteria;
          }
          else
          {
            abort("what happens if there is no single-field primary key?");
          }
        }

        //
        // Stash the finished query for reuse (we store it without values for the parameters).

        $query = $query->where(implode(" and ", $comparisons))->simplify();
        $this->query_library->set($cache_key, $query);
      }

      //
      // Fill in the parameters and return the finished query.

      $query and $query->parameters = $parameters;
      return $query;
    }
    
    
    protected function initialize_libraries()
    {
      if( is_null($this->table_library) )
      {
        $this->schema_epoch = Script::dispatch("get_schema_epoch", $this->db) or $this->age_limit = $this->db->get_schema_epoch();
        $this->table_library = new CodeLibrary($this->schema_epoch, $prefix = "schema.table." . $this->name);
        
        $this->query_epoch = Script::dispatch("get_query_epoch", $this->db) or $this->query_epoch = $this->schema_epoch;
        $this->query_library = new CodeLibrary($this->query_epoch, $prefix = "schema.query." . $this->name);
      }
    }

  }
