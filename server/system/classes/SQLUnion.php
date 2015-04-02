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

  class SQLUnion extends SQLQuery
  {
    function __construct( $lhs, $rhs )
    {
      // NOTE: We are intentionally not calling the parent constructor.

      $this->order_by_fields = null;
      $this->offset          = 0;
      $this->limit           = 0;
      $this->lhs             = $lhs;
      $this->rhs             = $rhs;
      $this->parameters      = array_merge($lhs->parameters, $rhs->parameters);

      $lhs_field_names = array_keys($lhs->fields);
      $rhs_field_names = array_keys($lhs->fields);
      if( $lhs_field_names != $rhs_field_names )
      {
        trigger_error("SQL UNION sources do not match", E_USER_ERROR);
        return;
      }

      $this->fields = array_fill_keys($lhs_field_names, true);
      $this->source_mappings = null;
    }


    function to_query()
    {
      return $this;
    }

    function to_string( $indent = "" )
    {
      ob_start();
      $this->generate_sql($indent);
      return ob_get_clean();
    }

    function to_sql( $db, $indent = "" )
    {
      return $db->format($this->to_string($indent), $this->parameters);
    }





    function generate_sql( $indent = "" )
    {
      print "$indent(\n";
      $this->lhs->generate_sql("$indent   ");
      print "\n$indent)\n";
      print "${indent}UNION DISTINCT\n";
      print "$indent(\n";
      $this->rhs->generate_sql("$indent   ");
      print "\n$indent)\n";

      if( !empty($this->order_by_fields) )
      {
        print "\n${indent}ORDER BY ";
        $first = true;
        foreach( $this->order_by_fields as $name )
        {
          if( is_numeric($name) )
          {
            if( $name == -1 )
            {
              print " desc";
            }
          }
          else
          {
            if( $first ) { $first = false; } else { print ", "; }
            print "$name";
          }
        }
      }

      if( !empty($this->limit) )
      {
        if( !empty($this->offset) )
        {
          printf("\n${indent}LIMIT %d, %d", $this->offset, $this->limit);
        }
        else
        {
          printf("\n${indent}LIMIT %d", $this->limit);
        }
      }

    }

  }
