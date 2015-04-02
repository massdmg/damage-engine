<?php class_exists("Script") or require_once __DIR__ . "/../environment.php";

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

  class SQLSet
  {
    static function build_sql( $table, $data, $key_names, $db = null )
    {
      $instance = new static($table, $data, $key_names);
      return $instance->to_sql($db);
    }


    function __construct( $table, $data, $key_names )
    {
      $this->table     = $table;
      $this->data      = $data;
      $this->key_names = $key_names;
    }


    //
    // Returns a SQL INSERT ON DUPLICATE UPDATE statement for your criteria. If you pass a $db,
    // the parameters will be expanded for you and you'll receive a string; otherwise, you'll
    // receive an array of string and parameters.

    function to_sql( $db = null )
    {
      $sets        = array();
      $updates     = array();
      $parameters  = array();
      $comparisons = array();

      foreach( $this->data as $name => $value )
      {
        $sets[]       = sprintf("`%s` = ?", $name);
        $parameters[] = $value;
      }

      $non_key_field_names = array_diff(array_keys($this->data), $this->key_names);

      foreach( $non_key_field_names as $name )
      {
        $updates[] = sprintf("`%s` = VALUES(`%s`)", $name, $name);
      }

      $statement = sprintf("INSERT INTO %s\nSET %s\nON DUPLICATE KEY UPDATE %s", $this->table, implode(", ", $sets), implode(", ", $updates));

      return $db ? $db->format($statement, $parameters) : array($statement, $parameters);
    }
  }




//=================================================================================================

  if( is_called_from_terminal(__FILE__) )
  {
    enter_text_mode();
    $test = new SimpleTest();

    $test->enter("Verifying SQL UPDATE with data and criteria");
  //=============================================================================================
    $actual   = SQLUpdate::build_sql("ATable", array("a" => 10, "b" => 20, "c" => null, "d" => "some text with spaces"), array("x" => "value", "y" => 1));
    $expected = array("UPDATE ATable SET `a` = ?, `b` = ?, `c` = ?, `d` = ? WHERE `x` = ? AND `y` = ?", array(10, 20, null, "some text with spaces", "value", 1));
  //=============================================================================================
    $test->assert_equal($actual, $expected);


    $test->enter("Verifying SQL UPDATE with intentionally empty criteria");
  //=============================================================================================
    $actual   = SQLUpdate::build_sql("ATable", array("a" => 10), array(), null, false);
    $expected = array("UPDATE ATable SET `a` = ?", array(10));
  //=============================================================================================
    $test->assert_equal($actual, $expected);


    $test->enter("Verifying SQL UPDATE with accidentally empty criteria");
  //=============================================================================================
    $actual   = SQLUpdate::build_sql("ATable", array("a" => 10), array());
    $expected = array("UPDATE ATable SET `a` = ? WHERE 1 = 0", array(10));
  //=============================================================================================
    $test->assert_equal($actual, $expected);

    $test->done();
  }
