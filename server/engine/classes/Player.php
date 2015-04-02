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
  // The primary class in the system, the key to everything.

  class Player extends GameObject
  {
    static function make_cache_key( $user_id, $ignored = null )
    {
      return GameObject::make_cache_key($user_id, "Player", array_slice(func_get_args(), 1));
    }

    static function get_class_name()
    {
      return __CLASS__;
    }






  //=================================================================================================
  // SECTION: Construction and data manipulation

    public $user_id;


    //
    // Sets up the object. Loads all the data unless you clear $load_all (in which case you should
    // call load() for each element you need). Generally speaking, you should clear $load_all in
    // new scripts.

    public function __construct( $user_id, $ds )
    {
      parent::__construct("user_id", $user_id, $loaded_test = "username", $ds);
    }







  //=================================================================================================
  // SECTION: Change management

    //
    // Extends GameObject::save() to write unrelayed changes to the underlying source (cache only).

    function save()
    {
      if( $result = parent::save() )
      {
        $this->save_unrelayed_changes();
      }

      return $result;
    }


    //
    // Clears and returns any unrelayed changes. Deletes the changes from the underlying source.

    function clear_unrelayed_changes()
    {
      $this->load_unrelayed_changes();
      $unrelayed_changes = $this->_properties->unrelayed_changes;
      $this->_properties->unrelayed_changes = array();
      $this->get_source()->delete($this->get_unrelayed_changes_key());

      return $unrelayed_changes;
    }


    function get_unrelayed_changes_key()
    {
      return $this->get_object_id() . ":unrelayed_changes";
    }


    //
    // Extends announce_change to record changes to this Player for eventual transmission to the
    // client. Changes are saved to the source (cache only) on save(), and cleared on
    // clear_unrelayed_changes().

    function announce_change( $op, $element, &$new_value, $old_value = null, $specialize = true )
    {
      parent::announce_change($op, $element, $new_value, $old_value, $specialize);

      $this->load_unrelayed_changes();
      $this->_properties->unrelayed_changes[] = (object)array("op" => $op, "subject" => $element, "data" => $new_value);
    }


    protected function load_unrelayed_changes()
    {
      if( !isset($this->_properties->unrelayed_changes) )
      {
        $this->_properties->unrelayed_changes = $this->get_source()->get($this->get_unrelayed_changes_key());
        is_array($this->_properties->unrelayed_changes) or $this->_properties->unrelayed_changes = array();
      }
    }


    protected function save_unrelayed_changes()
    {
      if( !empty($this->_properties->unrelayed_changes) )
      {
        $this->get_source()->set($this->get_unrelayed_changes_key(), $this->_properties->unrelayed_changes);
      }
    }
  }
