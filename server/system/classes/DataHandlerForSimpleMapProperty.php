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
  // A map with database-generated keys.

  class DataHandlerForSimpleMapProperty extends DataHandlerForMapProperty
  {
    function __construct( $owner, $name, $source, $source_query, $key_field, $value_field, $namespace = null )
    {
      parent::__construct($owner, $name, $source, $source_query, $key_field, $value_field, $namespace);
      assert('is_scalar($key_field)');
    }


    //
    // Adds a new entry to the map. In our case, the key is auto-generated. The only acceptable
    // parameter is a map of data pairs.

    function add()
    {
      $args  = $this->extract(func_get_args());
      $keys  = $this->owner->get_key_pairs();
      $pairs = array_merge($args[0], $keys);

      //
      // Generate the change record and add it to our owner.

      $this->changes[] = ChangeRecord::capture("add", $pairs, $this->source_query, $this->source->schema);

      //
      // Write the data into the map.

      $value = (is_array($this->value_field) || $this->value_field == "*") ? (object)$pairs : $pairs[$this->value_field];
      $this->set_to_map($keys, $value);
    }


  }
