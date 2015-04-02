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

  class StatisticsGenerator
  {
    function __construct( $collector )
    {
      $this->statistics_collector = is_string($collector) ? new ClassObject($collector) : $collector;
    }

    protected function accumulate( $statistic, $amount = 0, $local = null )
    {
      if( $this->statistics_collector )
      {
        $this->statistics_collector->accumulate($statistic, $amount);
      }

      if( $local )
      {
        if( $local === true )
        {
          $this->$statistic += $amount;
        }
        else
        {
          $this->$local += $amount;
        }
      }
    }

    protected function increment( $statistic, $local = null )
    {
      $this->accumulate($statistic, 1, $local);
    }

    protected function decrement( $statistic, $local = null )
    {
      $this->accumulate($statistic, -1, $local);
    }

    protected function record( $statistic, $value )
    {
      $this->statistics_collector->record($statistic, $value);
    }
  }
