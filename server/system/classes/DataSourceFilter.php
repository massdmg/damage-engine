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
  // Base class for DataSource filters.

  class DataSourceFilter
  {
    static function build( $tx )
    {
      abort("override, please");
    }


    protected $source;
    function __construct( $source )
    {
      is_object($source) or abort("expected_data_source");
      $this->source = $source;
    }
    
    
    function unfilter()
    {
      return $this->source->unfilter();
    }


    function filter_by( $by )
    {
      $class = sprintf("DataSource%sFilter", convert_snake_to_pascal_case($by));
      return $class::build($this, array_slice(func_get_args(), 1));
    }


    function &__get( $name )
    {
      $value =& $this->source->$name;
      return $value;
    }

  }
