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

  class SimpleTest
  {
    function __construct()
    {
      $this->stage  = null;
      $this->failed = false;
      $this->first  = true;

      register_teardown_function(array($this, "cleanup"));
    }


    function cleanup()
    {
    }

    function enter( $name )
    {
      $this->stage and $this->pass();
      $this->stage  = $name;
      $this->failed = false;

      if( $this->first )
      {
        $this->first = false;
      }
      else
      {
        print "\n\n\n";
      }

      print "NOW $this->stage\n";
    }


    function done( $everything = true )
    {
      $this->pass();
      if( $everything )
      {
        print "\n\n\n";
      }
    }

    function pass()
    {
      if( !$this->failed )
      {
        print "PASSED\n";
      }
    }


    function fail( $reason )
    {
      print "FAILED: $reason\n";
      $this->failed = true;
    }


    function assert( $condition, $message )
    {
      if( !$condition )
      {
        $this->fail($message);
        return false;
      }

      return true;
    }


    function assert_equal( $actual, $expected, $message = null )
    {
      if( $actual != $expected )
      {
        $this->fail($message ? $message : "not equal");

        ob_start();
        print "expected:\n";
        var_dump($expected);
        print "\nactual:\n";
        var_dump($actual);
        $dump = ob_get_clean();

        print preg_replace("/^/m", "   ", $dump);

        return false;
      }

      return true;
    }


    function assert_exists( $value, $message = null )
    {
      if( is_null($value) )
      {
        $this->fail($message ? $message : "is null");
        return false;
      }

      return true;
    }

  }
