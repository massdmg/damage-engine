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
  // Compiles a standard crontab pattern, plus handles a few extensions:
  //
  //   * * * * *  command to execute
  //   ┬ ┬ ┬ ┬ ┬
  //   │ │ │ │ │
  //   │ │ │ │ │
  //   │ │ │ │ └───── day of week (0 - 6) (0 to 6 are Sunday to Saturday, or use names; 7 is Sunday, the same as 0)
  //   │ │ │ └────────── month (1 - 12)
  //   │ │ └─────────────── day of month (1 - 31)
  //   │ └──────────────────── hour (0 - 23)
  //   └───────────────────────── min (0 - 59)
  
  class CrontabPattern
  {
    static $instances;
    static function fetch( $pattern )
    {
      is_array(static::$instances) or static::$instances = array();
      if( !array_has_member(static::$instances, $pattern) )
      {
        static::$instances[$pattern] = static::compile($pattern);
      }
      
      return static::$instances[$pattern];
    }
    
    

    static function compile( $pattern )
    {
      @list($minutes_pattern, $hours_pattern, $days_of_month_pattern, $months_pattern, $days_of_week_pattern) = explode(" ", $pattern, 5);

      $relative_month = array();
      $relative_week  = array();
      $minutes        = static::compile_simple_pattern($minutes_pattern, 0, 59);
      $hours          = static::compile_simple_pattern(  $hours_pattern, 0, 23);
      $months         = static::compile_simple_pattern( $months_pattern, 1, 12);
      $days_of_month  = static::compile_days_of_month_pattern($days_of_month_pattern, $relative_month);
      $days_of_week   = static::compile_days_of_week_pattern($days_of_week_pattern, $relative_week);
    
      return new static($pattern, $minutes, $hours, $days_of_month, $months, $days_of_week, $relative_month, $relative_week);
    }
    


    function __construct( $pattern, $minutes, $hours, $days_of_month, $months, $days_of_week, $relative_month, $relative_week )
    {
      $this->pattern        = $pattern;
      $this->minutes        = $minutes;
      $this->hours          = $hours;
      $this->days_of_month  = $days_of_month;
      $this->relative_month = $relative_month;
      $this->months         = $months;
      $this->days_of_week   = $days_of_week;
      $this->relative_week  = $relative_week;
    }
    
    
    function get_next( $relative_to, $older = false )
    {
      is_numeric($relative_to) or $relative_to = strtotime($relative_to);

      $base_year     = date("Y", $relative_to);
      $base_month    = date("m", $relative_to);
      $base_day      = date("d", $relative_to);
      $base_hour     = date("H", $relative_to);
      $base_minute   = date("i", $relative_to);
      
      $minutes       = $this->minutes       ?: range(0, 59);
      $hours         = $this->hours         ?: range(0, 23);
      $days_of_month = $this->days_of_month ?: range(1, 31);
      $months        = $this->months        ?: range(1, 12);
      $years         = range($base_year, $base_year + 4);       // Ensure we can handle Feb 29
      
      if( $older )
      {
        $minutes = array_reverse($minutes);
        $hours   = array_reverse($hours  );
        $months  = array_reverse($months);
        $years   = array_reverse($years );
      }
      
      for( $year = $base_year; $year < $base_year + 5; $year++ )
      {
        foreach( $months as $month )
        {
          $month_start       = mktime(0, 0, 0, $month, 1, $year);
          $month_name        = date("F", $month_start);
          $last_day_of_month = date("d", strtotime("last day of $month_name, $year"));
          
          $active_days_of_month = $days_of_month;
          if( $this->relative_month or $this->relative_week )
          {
            empty($this->days_of_month) and $active_days_of_month = array();
            foreach( array_merge($this->relative_month, $this->relative_week) as $relative )
            {
              if( $specific = strtotime("$relative $month_name, $year") )
              {
                $active_days_of_month[] = date("d", $specific);
              }
            }
          }
          
          $active_days_of_month = array_unique($active_days_of_month);
          sort($active_days_of_month);
          
          $older and $active_days_of_month = array_reverse($active_days_of_month);
          
          foreach( $active_days_of_month as $day_of_month )
          {
            foreach( $hours as $hour )
            {
              foreach( $minutes as $minute )
              {
                $time = mktime($hour, $minute, $second = 0, $month, $day_of_month, $year);
                if( $older ? $time < $relative_to : $time > $relative_to )
                {
                  note("$year-$month-$day_of_month $hour:$minute");
                  if( $this->matches($time) )
                  {
                    return now($time);
                  }
                }
              }
            }
          }
        }
      }
      
      return null;
    }
    
    
    function get_previous( $relative_to )
    {
      return $this->get_next($relative_to, $older = true);
    }
    
    
    function matches( $timestamp )
    {
      $time    = is_integer($timestamp) ? $timestamp : strtotime($timestamp);
      $matches = true;
      
      if( $matches and $this->minutes )
      {
        $minute  = (int)date('i', $time);
        $matches = in_array($minute, $this->minutes);
      }
      
      if( $matches and $this->hours )
      {
        $hour    = (int)date('H', $time);
        $matches = in_array($hour, $this->hours);
      }
      
      if( $matches )
      {
        if( $this->days_of_month )
        {
          $day_of_month = (int)date('d', $time);
          $matches      = in_array($day_of_month, $this->days_of_month);
        }
        if( !$matches and $this->relative_month )
        {
          $last_of_month = strtotime(sprintf("%s %s midnight", $this->relative_month[0], date("F, Y", $time)));
          $midnight_time = strtotime("midnight", $time);
          $matches = ($last_of_month == $midnight_time);
        }
      }
      
      if( $matches and $this->months )
      {
        $month   = (int)date('m', $time);
        $matches = in_array($month, $this->months);
      }

      if( $matches )
      {
        if( $this->days_of_week )
        {
          $day_of_week = strtolower(date('l', $time));
          $matches     = in_array($day_of_week, $this->days_of_week);
        }
        if( !$matches and $this->relative_week )
        {
          $month_name = date('F', $time);
          $year       = date('Y', $time);
          $date       = strtotime(date('Y-m-d', $time));
        
          foreach( $this->relative_week as $relative )
          {
            if( $date == strtotime(sprintf("%s %s %s", $relative, $month_name, $year)) )
            {
              $matches = true;
              break;
            }
          }
        }
      }
      
      return $matches;
    }
    
    
    static function compile_simple_pattern( $pattern, $min, $max )
    {
      trim($pattern) == "" and $pattern = "*";
      
      $hits = array();
      foreach( explode(",", $pattern) as $clause )
      {
        @list($body, $step) = explode("/", $clause, 2);
        $step or $step = 1; 
        $step = (int)$step;
        
        if( $body == "*" )             // it's a glob -- return nothing
        {
          if( $step <= 1 )
          {
            $hits = array();
          }
          else
          {
            $hits = range($min, $max, $step);
          }
        }
        elseif( strpos($body, '-') )   // it's a range
        {
          @list($range_min, $range_max) = explode("-", $body, 2);
          $hits = array_merge($hits, range((int)$range_min, (int)$range_max, $step));
        }
        else                             // it's a specific number
        {
          $hits[] = (int)$body;
        }
      }
      
      sort($hits);
      return array_intersect($hits, range($min, $max));
    }
    
    
    static function compile_days_of_month_pattern( $pattern, &$relative )
    {
      trim($pattern) == "" and $pattern = "*";
        
      $hits = array();
      $min  = 1;
      $max  = 31;
      $last = false;
      
      foreach( explode(",", $pattern) as $clause )
      {
        @list($body, $step) = explode("/", $clause, 2);
        $step or $step = 1; 
        $step = (int)$step;
        
        if( $body == "L" )             // last day of the month
        {
          $relative[] = "last day of";
        }
        elseif( $body == "*" )         // it's a glob -- return nothing
        {
          if( $step <= 1 )
          {
            $hits = array();
          }
          else
          {
            $hits = range($min, $max, $step);
          }
        }
        elseif( strpos($body, '-') )   // it's a range
        {
          @list($range_min, $range_max) = explode("-", $body, 2);
          $hits = array_merge($hits, range((int)$range_min, (int)$range_max, $step));
        }
        else                             // it's a specific number
        {
          $hits[] = (int)$body;
        }
      }
      
      sort($hits);
      return array_intersect($hits, range($min, $max));
    }


    static function compile_days_of_week_pattern( $pattern, &$relative )
    {
      trim($pattern) == "" and $pattern = "*";
      
      $hits    = array();
      $whiches = array(1 => "first", "second", "third", "fourth", "fifth");
      $days    = array("sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday");
      $abbr    = array_flip(array("Su", "M", "Tu", "W", "Th", "F", "Sa"));
      $min     = 0;
      $max     = 6;
      $modulus = 7;
      $last    = false;
      
      foreach( explode(",", $pattern) as $clause )
      {
        @list($body, $step) = explode("/", $clause, 2);
        $step or $step = 1; 
        $step = (int)$step;
        
        if( $body == "*" )             // it's a glob
        {
          if( $step <= 1 )
          {
            $hits = array();
          }
          else
          {
            $hits = range($min, $max, $step);
          }
        }
        elseif( strpos($body, '-') )   // it's a range
        {
          @list($range_min, $range_max) = explode("-", $clause, 2);
          $range_min = is_integer($range_min) ? $range_min % $modulus : @$abbr[$range_min];
          $range_max = is_integer($range_max) ? $range_max % $modulus : @$abbr[$range_max];

          foreach( range($range_min, $range_max, $step) as $index )
          {
            $hits[] = @$days[$index];
          }
        }
        else                             // it's a specific number
        {
          @list($body, $which) = explode("#", $body, 2);
          $last  = substr($body, -1, 1) == "L";
          $last and $body = substr($body, 0, -1); 
          $index = is_numeric($body) ? ((int)$body) % $modulus : @$abbr[$body];
          
          if( (int)$which )
          {
            $relative[] = sprintf("%s %s of", @$whiches[$which], @$days[$index]);
          }
          elseif( $last or $which == "L" )
          {
            $relative[] = sprintf("last %s of", @$days[$index]);
          }
          else
          {
            $hits[] = @$days[$index];
          }
        }
      }

      return array_filter(array_unique($hits));
    }
    
    
    static function pick_next( $set, $current )
    {
      foreach( $set as $number )
      {
        if( $current > $number )
        {
          return $current;
        }
      }
      
      return array_shift($set);
    }
  }

  $ct = CrontabPattern::compile("3 12 * * 7");