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

  class ValueAdjuster
  {
    public $base_value;
    public $adjustment_factors;
    public $adjustment_amounts;
    public $adjustments;
    public $value;
    
    
    static function build( $base_value, $adjustment_factors = array(), $adjustment_amounts = array(), $annotations = array() )
    {
      return new static($base_value, $adjustment_factors, $adjustment_amounts, $invert = false, $annotations);
    }
    
    
    static function build_inverter( $base_value, $adjustment_factors = array(), $adjustment_amounts = array(), $annotations = array() )
    {
      return new static($base_value, $adjustment_factors, $adjustment_amounts, $invert = true, $annotations);
    }


    function __construct( $base_value, $adjustment_factors = array(), $adjustment_amounts = array(), $invert = false, $annotations = array() )
    {
      $this->base_value         = $base_value;
      $this->adjustment_factors = $adjustment_factors;   // name => integer percentage (ie. 10)
      $this->adjustment_amounts = $adjustment_amounts;
      $this->adjustments        = array();
      $this->annotations        = array();
      $this->value              = is_object($this->base_value) ? $this->base_value->get_final_value() : $this->base_value;
      $this->multiplier         = $invert ? -1 : 1;

      $this->recalculate();
    }

    function set_base_value( $value )
    {
      $this->base_value = $value;
      return $this->recalculate();
    }
    
    function add_adjustment_factor( $name, $value )
    {
      $this->adjustment_factors[$name] = $this->multiplier * $value;
      return $this->recalculate();
    }
    
    function add_adjustment_amount( $name, $value )
    {
      $this->adjustment_amounts[$name] = $this->multiplier * $value;
      return $this->recalculate();
    }
    
    function add_annotation( $name, $value )
    {
      $this->annotations[$name] = $value;
    }
    
    
    function get_adjustment_factor( $name, $default = 0 )
    {
      return array_fetch_value($this->adjustment_factors, $name, $default);
    }
    
    function get_adjustment_amount( $name, $default = 0 )
    {
      return array_fetch_value($this->adjustment_amounts, $name, $default);
    }
    
    function get_adjustment( $name, $default = 0 )
    {
      return array_fetch_value($this->adjustments, $name, $default);
    }
    
    function get_annotation( $name, $default = null )
    {
      return array_fetch_value($this->annotations, $name, $default);
    }


    function recalculate()
    {
      $base_value = is_object($this->base_value) ? $this->base_value->get_final_value() : $this->base_value;
      
      //
      // Summarize the bonuses by name. These are approximate, primarily useful for display
      // purposes.

      $this->adjustments = array();

      foreach( $this->adjustment_factors as $name => $factor )
      {
        is_object($factor) and $factor = $factor->get_final_value();
        @$this->adjustments[$name] += ceil($base_value * $factor / 100);
      }
      foreach( $this->adjustment_amounts as $name => $points )
      {
        is_object($points) and $points = $points->get_final_value();
        @$this->adjustments[$name] += $points;
      }

      $this->adjustments = array_filter($this->adjustments);

      //
      // And calculate the overall total.

      $this->value = round($base_value + ($base_value * array_sum($this->adjustment_factors) / 100) + array_sum($this->adjustment_amounts));
      return $this;
    }


    function get_final_value()
    {
      $this->recalculate();
      return $this->value;
    }
    
    
    function get_final_value_for( $value )
    {
      $old_base = $this->base_value;
      $this->base_value = $value;
      
      $final = $this->get_final_value();
      
      $this->base_value = $old_base;
      $this->recalculate();
      
      return $final;
    }
    

    function with_updated_bonuses( $adjustment_factors, $adjustment_amounts, $annotations = array() )
    {
      $this->multiplier == 1 or abort("nyi");
      return $this->recreate($this->base_value, array_merge($this->adjustment_factors, $adjustment_factors), array_merge($this->adjustment_amounts, $adjustment_amounts), $this->multiplier == -1, array_merge($this->annotations, $annotations));
    }


    function with_additional_bonuses( $adjustment_factors, $adjustment_amounts, $additional_base = 0, $annotations = array() )
    {
      $this->multiplier == 1 or abort("nyi");
      
      $adjustment_factors = $this->adjustment_factors;
      foreach( $adjustment_factors as $name => $factor )
      {
        $adjustment_factors[$name] += $factor;
      }

      $adjustment_amounts = $this->adjustment_amounts;
      foreach( $adjustment_amounts as $name => $points )
      {
        $adjustment_amounts[$name] += $points;
      }

      is_object($this->base_value) || is_object($additional_base) and abort("nyi");
      return $this->recreate($this->base_value + $additional_base, $adjustment_factors, $adjustment_amounts, $this->multiplier == -1, $annotations);
    }
    
    
    function total_adjustment_factors()
    {
      $total = 0;
      foreach( $this->adjustment_factors as $name => $factor )
      {
        is_object($factor) and $factor = $factor->get_final_value();
        $total += $factor;
      }

      return $total / 100;
    }



    function invert()
    {
      $inverted_factors = array();
      foreach( $this->adjustment_factors as $name => $factor )
      {
        $inverted_factors[$name] = -$factor;
      }
      
      $inverted_amounts = array();
      foreach( $this->adjustment_amounts as $name => $amount )
      {
        $inverted_factors[$name] = -$amount;
      }
      
      return $this->recreate($this->base_value, $inverted_factors, $inverted_amounts, $invert = true, $annotations);
    }


    protected function recreate( $base_value, $adjustment_factors, $adjustment_amounts, $invert, $annotations )
    {
      return new static($base_value, $adjustment_factors, $adjustment_amounts, $invert, $annotations);
    }



  }
