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


  class DataSourceAgeFilter extends DataSourceFilter
  {
    static function determine_age_limit()
    {
      $args    = func_get_args();
      $cleaned = array_map(array("CacheConnection", "canonicalize_time"), array_filter(array_flatten($args), "is_numeric"));
      return empty($cleaned) ? null : max($cleaned);
    }

    static function build( $source )
    {
      $age_limit = static::determine_age_limit(array_slice(func_get_args(), 1));
      if( is_a($source, __CLASS__) )
      {
        if( $age_limit <= $source->limit )
        {
          return $source;
        }
        else
        {
          return new static($source->source, $age_limit);
        }
      }
      else
      {
        return new static($source, $age_limit);
      }
    }


    protected $limit;

    function __construct( $source /* limit . . . */ )
    {
      parent::__construct($source);
      $this->limit = static::determine_age_limit(array_slice(func_get_args(), 1));
    }
    
    function reconnect_for_writing( $on_abort = null )
    {
      return new static($this->source->reconnect_for_writing($on_abort), $this->limit);
    }
    
    function reconnect_for_reading()
    {
      return new static($this->source->reconnect_for_reading(), $this->limit);
    }


    function limit( $ds )
    {
      return $ds ? static::build($ds, $this->limit) : $this;
    }

    function get_limit()
    {
      return $this->limit;
    }


    function __call( $name, $parameters )
    {
      if( $name == "query_map_of_table" || $name == "query_map_of_static_table" )
      {
        return call_user_func_array(array($this->source, $name), $this->correct_max_age($parameters, $offset = 3));
      }
      elseif( $name == "get" || $name == "get_static" || substr($name, 0, 6) == "query_" )
      {
        return call_user_func_array(array($this->source, $name), $this->correct_max_age($parameters, $offset = 1));
      }
      else
      {
        return call_user_func_array(array($this->source, $name), $parameters);
      }
    }



    protected function correct_max_age( $parameters, $offset )
    {
      if( is_numeric(@$parameters[$offset]) || (count($parameters) > $offset && is_null(@$parameters[$offset])) )
      {
        $parameters[$offset] = static::determine_age_limit($parameters[$offset], $this->limit);
      }
      else
      {
        array_splice($parameters, $offset, 0, array($this->limit));
      }

      return $parameters;
    }

  }
