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
  // Parses the running script to describe the parameters.
  //
  // BUG: At some point, this should be rewritten in terms of filters.

  function get_script_parameter_descriptors( $path = null )
  {
    $path or $path = $_SERVER["SCRIPT_FILENAME"];
    $script_parameters = array();

    //
    // First, parse out any actual parameter accesses from the source.

    if( $source = file_get_contents($path) )
    {
      $source = preg_replace('/\/\/.*?\$.*$/m', "", $source);
      $pattern = '/(?:\$_(?P<method>POST|GET|REQUEST)\s*\[\s*(?P<quote>[\'"])(?P<name>.*?)(?P=quote))(?:.*?\/\/(?P<comment>.*$))?/m';
      if( preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) )
      {
        foreach( $matches as $match )
        {
          if( strpos(@$match["comment"], "not a script parameter") !== false )
          {
            continue;
          }

          $descriptor = new ParameterDescriptor($match["name"], @$match["comment"], $match["method"]);
          $script_parameters[$descriptor->name] = $descriptor;
        }
      }

      $pattern = '/(?:\$game->|\$service->|Script::)(?P<method>(?:get|filter|claim|parse|has|(?:\w+from_parameters))\w*)\((?P<parameters>.*?)\)(?:.*?\/\/(?P<comment>.*$))?/m';
      if( preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) )
      {
        foreach( $matches as $match )
        {
          $descriptor = null;
          $method     = $match["method"];
          $parameters = array_map("trim", preg_split('/\s*,\s*/', $match["parameters"]));

          if( strpos(@$match["comment"], "not a script parameter") !== false )
          {
            continue;
          }

          switch( $method )
          {
            case 'get':
            case 'get_or_fail':
            case 'get_parameter':
            case 'get_parameter_or_fail':
            case 'has_parameter':
            case 'has_parameter_or_fail':
            case 'getParam':
              $descriptor = new ParameterDescriptor($parameters[0], @$match["comment"]);
              $descriptor->is_numeric = count($parameters) > 1 && is_numeric($parameters[1]);
              $descriptor->make_required(strpos($method, "or_fail") || (count($parameters) > 2 && strtolower($parameters[2]) != "false"));
              break;

            case 'get_user_id':
            case 'get_user_id_or_fail':
            case 'get_player':
            case 'getPlayer':
              $descriptor = new ParameterDescriptor("token", @$match["comment"]);
              $descriptor->add_description("an active Player token");
              $descriptor->make_required(strpos($method, "or_fail") || (count($parameters) > 3 && strtolower($parameters[3]) != "false"));
              break;

            case 'get_player_or_fail':
            case 'claim_player_or_fail':
              $descriptor = new ParameterDescriptor("token", @$match["comment"]);
              $descriptor->is_claimed  = ($method == 'claim_player_or_fail' || (count($parameters) > 1 && strtolower($parameters[1]) != "false"));
              $descriptor->add_description("an active Player token" . ($descriptor->is_claimed ? "; will be claimed" : ""));
              $descriptor->make_required();
              break;

            case 'get_location_or_fail':
            case 'claim_location_or_fail':
              $descriptor = new ParameterDescriptor("location_id", @$match["comment"]);
              $descriptor->is_claimed  = ($method == 'claim_location_or_fail' || (count($parameters) > 1 && strtolower($parameters[1]) != "false"));
              $descriptor->add_description("a Location" . ($descriptor->is_claimed ? "; will be claimed" : ""));
              $descriptor->make_required();
              break;

            case 'filter_parameter':
            case 'filter_parameter_or_fail':
              $descriptor = new ParameterDescriptor($parameters[0], @$match["comment"]);
              $descriptor->add_description("matches: " . $parameters[1]);
              $descriptor->make_required(strpos($method, "or_fail") || (count($parameters) > 3 && strtolower($parameters[3]) != "false"));
              break;

            case 'get_parsed_parameter':
            case 'parse_comma_delimited_pipes_parameter':
            case 'parse_comma_delimited_pipes_parameter_into_map':
              $descriptor = new ParameterDescriptor($parameters[0], @$match["comment"]);
              $descriptor->add_description("a comma-delimited set of pipe-delimited values");
              $descriptor->make_required();
              break;

            case 'parse_comma_delimited_parameter':
              $descriptor = new ParameterDescriptor($parameters[0], @$match["comment"]);
              $descriptor->add_description("a comma-delimited set of values");
              $descriptor->make_required();
              break;
              
            case 'update_player_coordinates_from_parameters_and_commit':
              $descriptor = new ParameterDescriptor(array_fetch_value($parameters, 2, "latitude"));
              $descriptor->add_description("player's most recent latitude");
              $script_parameters[$descriptor->name] = $descriptor;

              $descriptor = new ParameterDescriptor(array_fetch_value($parameters, 3, "longitude"));
              $descriptor->add_description("player's most recent longitude");
              break;
              
          }

          if( $descriptor )
          {
            $script_parameters[$descriptor->name] = $descriptor;
          }
        }
      }

      if( preg_match_all('/route_service_call\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $matches, PREG_SET_ORDER) )
      {
        foreach( $matches as $match )
        {
          if( $service = @$match[1] )
          {
            if( $path = route_service_call($service) )
            {
              $script_parameters = array_merge($script_parameters, get_script_parameter_descriptors($path));
            }
          }
        }
      }
    }
    
    return $script_parameters;
  }


  