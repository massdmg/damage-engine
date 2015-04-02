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


  require_once "convert_snake_to_pascal_case.php";
  require_once "convert_pascal_to_snake_case.php";
  require_once "tclose.php";

  Conf::define_term("SESSION_MAX_AGE", "The maximum time (in seconds) an unused session token should be valid", 72000);
  Conf::define_term("CACHE_CONTROL_MAX_AGE", "The maximum age (in seconds) to cache the CacheControl data"    , Features::enabled("development_mode") ? 0 : 900);


  class GameEngine
  {
    function format_time( $time = null )
    {
      $time or $time = time();
      return date("Y-m-d H:i:s", $time);
    }
    
    function get_time_index( $fresh = false )
    {
      if( $fresh )
      {
        list($fraction, $seconds) = explode(" ", microtime());
        $time   = (int)$seconds;
        $micros = substr($fraction, 2);

        return array($time, $micros);
      }
      else
      {
        return array(Script::$start_time, Script::$start_micros);
      }
    }

    function can_route( $method_name, $subject, $treat_as = null, $parameter_count = null )
    {
      $this->load_routable_methods_map();

      $treat_as or $treat_as = get_class($subject);

      if( method_exists($subject, $method_name) )
      {
        return is_null($parameter_count);
      }
      
      foreach( ClassLoader::get_pedigree($treat_as) as $class_name )
      {
        if( array_key_exists($class_name, $this->routable_methods_map) )
        {
          if( array_key_exists($method_name, $this->routable_methods_map[$class_name]) )
          {
            if( is_numeric($parameter_count) )
            {
              $search   = "|$class_name.$method_name:";
              $position = strpos($this->routable_methods_map[0], $search);
              $required = is_numeric($position) ? (int)substr($this->routable_methods_map[0], $position + strlen($search), 2) : 0;

              return $parameter_count >= $required;  
            }
            else
            {
              return true;
            }
          }
        }
      }

      return false;
    }
    
    function can_route_or_fail( $method_name, $subject, $treat_as = null )
    {
      $this->can_route($method_name, $subject, $treat_as) or throw_alert("unrecognized_reward_type", "method_name", $method_name, "subject", get_class($subject), "treat_as", $treat_as);
    }

    function route( $method_name, $subject, $parameters = array(), $treat_as = null)
    {
      $this->load_routable_methods_map();
      
      $treat_as or $treat_as = get_class($subject);

      if( method_exists($subject, $method_name) )
      {
        return call_user_func_array(array($subject, $method_name), $parameters);
      }
      
      foreach( ClassLoader::get_pedigree($treat_as) as $class_name )
      {
        if( array_key_exists($class_name, $this->routable_methods_map) )
        {
          if( array_key_exists($method_name, $this->routable_methods_map[$class_name]) )
          {
            $target_name   = $method_name;
            $property_name = $this->routable_methods_map[$class_name][$method_name];
          
            if( is_array($property_name) )
            {
              list($property_name, $target_name) = $property_name;
            }
          
            if( $class_name == "GameEngine" )
            {
              return call_user_func_array(array($this->$property_name, $target_name), $parameters);
            }
            else
            {
              return call_user_func_array(array($this->$property_name, $target_name), array_merge(array($subject), $parameters));
            }
          }
        }
      }

      abort("Method $method_name on $class_name is not routable to a relevant subsystem");
    }


    static function build_game_parameters_query()
    {
      return "SELECT param_id, value FROM GameParameterDict";
    }
    
    
    static function build_cache_control_query( $ds )
    {
      $query =
       "SELECT 'schema' as aspect, least(ifnull(MAX(UNIX_TIMESTAMP(cutoff)), 0), unix_timestamp(now())) as cutoff FROM ServerCacheControlData 
        UNION
        SELECT aspect, UNIX_TIMESTAMP(cutoff) as cutoff FROM ServerCacheControlData
        UNION
        SELECT 'overall' as aspect, least(ifnull(MAX(UNIX_TIMESTAMP(cutoff)), 0), unix_timestamp(now())) as cutoff FROM ServerCacheControlData
       "
      ;
      
      if( Features::enabled("development_mode") )
      {
        $query .=
         "UNION
          SELECT 'schema' as aspect, greatest(iis.schema_epoch, ifnull((SELECT unix_timestamp(cutoff) FROM ServerCacheControlData WHERE aspect = 'schema'), 0)) as cutoff
          FROM 
          (
            " . $ds->db->get_schema_epoch_query() . "
          ) iis
         "
        ;
      }
      
      return $query;
    }



  //===============================================================================================
  // SECTION: Lifecycle.

    public    $code_epoch;
    public    $system_epoch;
    public    $ds;
    public    $parameters;
    public    $cache_control;
    protected $schema_ds;
    protected $named_object_map;
    protected $named_object_source_map;
    protected $named_object_target_map;
    protected $routable_methods_map;
    private   $session_player_data;
    private   $session_player_ready;
    private   $session_player_claimed;

    function __construct( $ds )
    {
      $this->cache_control           = $ds->query_static_map("CacheControl", Conf::get("CACHE_CONTROL_MAX_AGE"), "aspect", "cutoff", Callback::for_method($this, "build_cache_control_query", $ds));
      $this->code_epoch              = ClassLoader::get_epoch();
      $this->system_epoch            = limit_to_range(max($this->code_epoch, array_fetch_value($this->cache_control, "overall", 0)), 1, time());
      
      $this->library = new CodeLibrary($age_limit = $this->system_epoch, $prefix = "game");
      Features::enabled("local_static_cache") and $ds->on_route(Callback::for_method($this, "route_cache_key"));
      
      $this->ds                      = $this->limit_data_source_age_by($ds, "everything", "game");
      $this->schema_ds               = $this->limit_data_source_age_by("schema", "Queries");
      $this->parameters              = $this->limit_data_source_age_by("GameParameterDict")->query_static_map("GameParameterDict", "param_id", "value", static::build_game_parameters_query());
      $this->subsystems              = array();
      $this->named_object_map        = null;
      $this->named_object_source_map = null;
      $this->named_object_target_map = null;
      $this->session_player_data     = null;
      $this->session_player_ready    = false;
      $this->session_player_claimed  = false;
      
      //
      // Configure any database-specified debug logging. Format:
      //   Player/StartSession=GameSomethingManager::item_load,GameSomethingManaer::blah,!GameSomethingManager::x; Player/Whatever ....
      
      if( $string = array_fetch_value($this->parameters, "DEBUG_LOGGING_INSIDE") )
      {
        if( is_numeric(strpos($string, Script::$script_name)) && preg_match('/(?:^|;\s*)' . preg_quote(Script::$script_name, $delimiter = '/') . ':([^;]*)/', $string, $m) )
        {
          if( !Features::debugging_enabled() )
          {
            warn("SECURITY: GameEngine is enabling debugging due to DEBUG_LOGGING_INSIDE game parameter!");
            Features::enable_debugging();
          }

          register_debug_logging_controls(trim($m[1]));
        }
      }
    }


    //
    // Returns a DataSourceAgeFilter on the game's DataSource (or the one you pass as first parameter), 
    // using the CacheControl aspects you pass as arguments. The parameter list will be flattened before 
    // use.

    function limit_data_source_age_by()
    {
      $args = func_get_args();
      $args = array_flatten($args);
      $base = count($args) > 1 && is_object($args[0]) ? array_shift($args) : $this->ds;

      $limits = array();
      foreach( $args as $aspect )
      {
        if( is_numeric($aspect) )                                    //<<<<<<< A direct timestamp
        {
          $limits[] = (int)$aspect;
        }
        elseif( strpos($aspect, "/") !== false )                     //<<<<<<< A file path
        {
          $limits[] = filectime(substr($aspect, 0, 1) == "/" ? $aspect : path($aspect));   
        }
        elseif( array_has_member($this->cache_control, $aspect) )    //<<<<<<< An aspect name
        {
          $limits[] = $this->cache_control[$aspect];
        }
        elseif( ($timestamp = @strtotime($aspect)) !== false )       //<<<<<<< A convertible time string ("now", "10:00", etc.)
        {
          $limits[] = (int)$timestamp;
        }
      }

      return $base->filter_by("age", $limits);
    }


    //
    // Note: do to the transaction isolation level required by MySQL, this routine won't leave the
    // running script in a good state. Be careful.
    
    function invalidate_aspect( $aspect, $create = true, $ds_to_use = null )
    {
      $ds2 = $ds_to_use or $ds2 = $this->ds->reconnect_for_writing() or throw_internal_exception("unable_to_reconnect");
      
      if( $create )
      {
        $ds2->execute("INSERT INTO ServerCacheControlData SET aspect = ?, cutoff = now() ON DUPLICATE KEY UPDATE cutoff = greatest(cutoff, VALUES(cutoff))", $aspect);
      }
      else
      {
        $ds2->execute("UPDATE ServerCacheControlData SET cutoff = greatest(cutoff, now()) WHERE aspect = ?", $aspect);
      }
      
      $ds_to_use or $ds2->commit();

      $this->cache_control = $ds2->query_static_map("CacheControl", $max_age = 0, "aspect", "cutoff", static::build_cache_control_query($ds2));
      $this->ds            = $this->limit_data_source_age_by("everything", "game");
      $this->parameters    = $this->limit_data_source_age_by($ds2, "GameParameterDict")->query_static_map("GameParameterDict", "param_id", "value", static::build_game_parameters_query());

      Script::signal("aspect_invalidated", $aspect);
    }
    

    function get_game_object_claim_key( $cache_key, $include_class_name = false )
    {
      $m = null;
      if( preg_match('/^(([a-z_]+)(?:\d+|:\w+))(?::\w+)?$/', $cache_key, $m) )
      {
        $class_name = convert_snake_to_pascal_case($m[2]);
        if( ClassLoader::is_loadable($class_name) && is_subclass_of($class_name, "GameObject") )
        {
          return $include_class_name ? array($m[1], $class_name) : $m[1];
        }
      }
      
      return null;
    }
    
        
    function commit_and_respond()
    {
      call_user_func_array(array("Script", "render_response"), func_get_args());
      $method = Features::disabled("effect") ? "discard" : "commit";
      $this->ds->$method();
      Script::note_time("ds_committed");
      
      Script::respond_success();
    }


    function discard_and_respond()
    {
      call_user_func_array(array("Script", "render_response"), func_get_args());
      // implied: $this->ds->discard();
      
      Script::respond_success();
    }
    
    



  //===============================================================================================
  // SECTION: Projection services.
  
  
    function project( $object, $as /* ... */ )
    {
      return $this->project_with_parameter_array($object, $as, array_slice(func_get_args(), 2));
    }
    
    function project_with_parameter_array( $object, $as, $parameters = array() )
    {
      if( $as and !is_null($object) )
      {
        if( is_object($as) )
        {
          return Callback::do_call_with_array($as, array_merge(array($object, $parameters)));
        }
        else
        {
          $base_name = convert_snake_to_pascal_case($as);
          if( $class_name = ClassLoader::pick_class($base_name, $base_name . "Projector") )
          {
            $projector = array(new $class_name, "project");
            return call_user_func_array($projector, array_merge(array($object), $parameters));
          }
        }
      }

      return $object;
    }
  
    function project_into( $into, $object, $as /* ... */ )
    {
      if( $projection = call_user_func_array(array($this, "project"), array_slice(func_get_args(), 1)) )
      {
        foreach( $projection as $name => $value )
        {
          $into->$name = $value;
        }
      }
    
      return $into;
    }
  
    function project_all( $array, $as /* ... */ )
    {
      return $this->project_all_with_parameter_array($array, $as, array_slice(func_get_args(), 2));
    }
    
    function project_all_with_parameter_array( $array, $as, $parameters = array() )
    {
      $base_name = convert_snake_to_pascal_case($as);
      if( $class_name = ClassLoader::pick_class($base_name, $base_name . "Projector") )
      {
        $projector = array(new $class_name, "project");
        $projected = array();
        foreach( $array as $key => $value )
        {
          $projected[$key] = call_user_func_array($projector, array_merge(array($value), $parameters));
        }
      
        return $projected;
      }

      return $array;
    }
    
    
    function project_each_field( $object, $patterns, $fields = null )
    {
      $projected = (object)null;
      
      $patterns = (array)$patterns;
      $pairs = $fields ? project($object, $fields) : get_object_vars($object);
      foreach( $pairs as $name => $value )
      {
        $projectors = array();
        foreach( $patterns as $pattern )
        {
          $projectors[] = sprintf($pattern, convert_snake_to_pascal_case($name)); 
        }
        
        if( $class_name = ClassLoader::pick_class($projectors) )
        {
          $projected->$name = $class_name::project_once($value, $object);
        }
        else
        {
          $projected->$name = $value;
        }
      }
      
      return $projected;
    }


    function get_cache_key()
    {
      return "game";
    }
    
    
    function get_class_key()
    {
      return "game";
    }




  //===============================================================================================
  // SECTION: Translation services.

    function add_translation_namespace( $namespace )
    {
      $this->string_manager->add_namespace($namespace);
    }
    
    
    function remove_translation_namespace( $namespace )
    {
      $this->string_manager->remove_namespace($namespace);
    }
    
    
    function enter_translation_namespace( $namespace )
    {
      return $this->string_manager->enter_namespace($namespace);
    }
    
    function exit_translation_namespace( $namespace )
    {
      return $this->string_manager->exit_namespace($namespace);
    }
    
    
    function translate_in_namespace( $namespace, $name, $parameters = array() )
    {
      $scope = $this->enter_translation_namespace($namespace);
      return $this->translate($name, $parameters);
    }


    function translate( $name, $parameters = array() )
    {
      $parameters = array_pair_slice(func_get_args(), 1);
    
      $names = (array)$name;
      $last  = array_pop($names);
      foreach( $names as $name )
      {
        if( $translation = $this->string_manager->translate_string_if_exists($name, $parameters) )
        {
          return $translation;
        }
      }
    
      return $this->string_manager->translate_string($last, $parameters);
    }

    function translate_all( $list, $prefix = "", $suffix = "" )
    {
      $translated = array();
      foreach( $list as $name_and_parameters )
      {
        if( is_string($name_and_parameters) )
        {
          $translated[] = $this->translate($prefix . $name . $suffix);
        }
        elseif( is_array($name_and_parameters) && count($name_and_parameters) == 2 )
        {
          list($name, $parameters) = $name_and_parameters;
          $translated[] = $this->translate($prefix . $name . $suffix, $parameters);
        }
        else
        {
          abort();
        }
      }

      return $translated;
    }
    
    
    function translate_names( $array, $prefix = "", $suffix = "" )
    {
      $translated = array();
      foreach( $array as $id )
      {
        $translated[$id] = $this->translate($prefix . $id . $suffix);
      }

      return $translated;
    }
    
    
    function has_translation( $name, $trust_name = true )
    {
      return $this->string_manager->has_translation($name);
    }


  

  //===============================================================================================
  // SECTION: SQLQuery services.

    function load_query( $name, $ds = null )
    {
      $ds = $ds ? $this->schema_ds->limit($ds) : $this->schema_ds;
      return $ds->get_static($name);
    }

    function store_query( $name, $query, $ds = null )
    {
      $ds = $ds ? $this->schema_ds->limit($ds) : $this->schema_ds;
      $ds->set_static($name, $query);
      return $query;
    }

    function simplify_and_store_query( $name, $query, $ds = null )
    {
      return $this->store_query($name, $query->simplify(), $ds);
    }

    
    
    
  //===============================================================================================
  // SECTION: Miscellaneous services.

    function get_parameter( $name, $default = null )
    {
      if( is_array($name) )
      {
        $names = $name;
        foreach( $names as $name )
        {
          $name = strtoupper($name);
          if( array_key_exists($name, $this->parameters) )
          {
            return coerce_type($this->parameters[$name], $default);
          }
        }
      }
      else
      {
        $name = strtoupper($name);
        if( array_key_exists($name, $this->parameters) )
        {
          return coerce_type($this->parameters[$name], $default);
        }
      }
      
      return Conf::get($name, $default);
    }
    
    
    function get_matching_parameters( $pattern, $match_index_for_key = 0 )
    {
      $matching = array();
      $m        = null;
      foreach( $this->parameters as $name => $value )
      {
        if( preg_metch($pattern, $name, $m) )
        {
          $matching[$m[$match_index_for_key]] = $value;
        }
      }
      
      return $matching;
    }


    function is_manager( $name )
    {
      $class_name = "Game" . convert_snake_to_pascal_case($name);
      return ClassLoader::is_loadable($class_name) ? $class_name : null;
    }




  //===============================================================================================
  // SECTION: Player management

    //
    // Returns the user_id for the given session token. Automatically retrieves the session token
    // from $_REQUEST if you pass null. If $update_token, updates the session token on success, to
    // extend its validity.
    //
    // Finally, if running in $debug mode, a numeric token will be treated as a user_id anyway,
    // in order to simplify testing (ie. you can bypass session handling).

    function get_user_id( $user_id = null, $fail = false )
    {
      if( is_null($user_id) )
      {
        $user_id = $this->identify_session_player($user_id, $fail);
      }
      elseif( !is_numeric($user_id) )
      {
        throw_exception("invalid_user_id", "user_id", $user_id);
      }

      if( !$user_id && $fail )
      {
        throw_exception("required_session_token_was_missing");
      }

      return $user_id;
    }


    //
    // Returns the player's name or null (if there is no player).
    
    function get_player_name()
    {
      if( $player = $this->get_player() )
      {
        return $player->username;
      }

      return null;
    }


    //
    // Identifies the session player (if necessary) and returns their credentials.
    
    function identify_session_player( $user_id = null, $fail = true )
    {
      if( !$this->session_player_data )
      {
        if( $user_id )
        {
          $this->session_player_data = (object)null;
          $this->session_player_data->user_id = $user_id;
        }
        else
        {
          if( $token = Script::get_parameter("token", "") )
          {
            $user_data = (object)null;

            if( preg_match("/^[a-fA-F0-9]{40}$/", $token) )
            {
              if( $fail )
              {
                $user_data = $this->resume_session_or_fail($token, $this->ds);
              }
              else
              {
                $user_data = $this->resume_session($token, $this->ds);
                if( !$user_data )
                {
                  return 0;   // <<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<
                }
              }
            }
            elseif( is_numeric($token) && (Features::security_disabled() || Features::uid_for_token_enabled()) )
            {
              $user_data->user_id = (int)$token;
            }
            elseif( Features::security_disabled() and $user_id = $this->get_user_id_by_name($token) )
            {
              $user_data->user_id = $user_id;
            }
            elseif( $fail )
            {
              throw_exception("invalid_session_token", "supplied", $token);
            }
            else
            {
              return 0;   // <<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<
            }

            $this->session_player_data = $user_data;
          }
          elseif( $user_id = Script::get("user_id", 0) )
          {
            $user_data = (object)null;
            $user_data->user_id = $user_id;
            
            $this->session_player_data = $user_data;
          }
          else
          {
            return 0;   // <<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<<
          }
        }

        Script::set("user_id", $this->session_player_data->user_id);
        Script::signal("player_session_resuming", $this->session_player_data->user_id);
      }

      return $this->session_player_data->user_id;
    }
    
    
    //
    // Returns the session data for the current player.
    
    function get_session_player_data( $user_id = null )
    {
      if( $this->identify_session_player($user_id) )
      {
        return $this->session_player_data;
      }
      
      return null;
    }
    

    //
    // An alias for get_user_id($token, $fail = true)

    function get_user_id_or_fail( $token = null )
    {
      return $this->get_user_id($token, $fail = true);
    }



    //
    // Gets the Player for the given $user_id. Pass null to get one determined for you from the
    // token parameter. If you set $claim, the claim will be made before proceeding.

    function get_player_or_fail( $user_id = null, $ds = null )
    {
      return $this->get_object_or_fail("Player", is_object($user_id) ? $user_id : $this->get_user_id_or_fail($user_id), $ds);
    }

    function get_player( $user_id = null, $ds = null )
    {
      return $this->get_object("Player", is_object($user_id) ? $user_id : $this->get_user_id($user_id), $ds);
    }
    


    //
    // Alias for get_player_or_fail($user_id, $claim = true);

    function claim_player_or_fail( $user_id = null, $ds = null, $user_id_is_player = false )
    {
      if( $player = $this->claim_object_or_fail("Player", is_object($user_id) ? $user_id : $this->get_user_id_or_fail($user_id), $ds) )
      {
        if( !$this->session_player_data && $user_id_is_player )
        {
          //
          // NOTE: This is here to help start session. Nobody else should be using it. session_player_ready
          // will not be fired. This may need addressing.

          $this->identify_session_player($player->user_id);
        }

        if( !$this->session_player_claimed && $this->session_player_data && $player->user_id == $this->session_player_data->user_id )
        {
          $this->session_player_claimed = true;
          Script::signal("session_player_claimed", $player);
        }
      }

      return $player;
    }
    
    
    function claim_player( $user_id = null, $ds = null, $user_id_is_player = false, $method = "claim_object" )
    {
      $player = null;
      
      if( $user_id = (is_object($user_id) ? $user_id : $this->get_user_id($user_id)) )
      {
        if( $player = $this->claim_object("Player", $user_id, $ds) )
        {
          if( !$this->session_player_claimed && $this->session_player_data && $player->user_id == $this->session_player_data->user_id )
          {
            $this->session_player_claimed = true;
            Script::signal("session_player_claimed", $player);
          }
        }
      }
      
      return $player;
    }


    //
    // Claims several players or fails entirely. Pass null to include the current player in the
    // list (if you don't have an object to pass in). Returns a list of player objects in the
    // same order as you requested them.

    function claim_players_or_fail()
    {
      $args    = func_get_args(); 
      $players = array_flatten($args);
      
      $ds = $this->ds;
      if( count($players) and is_object($players[0]) and method_exists($players[0], "reconnect_for_writing") )
      {
        $ds = array_shift($players);
      }

      $players_by_cache_key = array();
      $player_order         = array();
      foreach( $players as $player )
      {
        $player = $this->get_player_or_fail($player, $ds);
        if( $player->is_claimable() )
        {
          $cache_key = $player->get_cache_key();
        
          $players_by_cache_key[$cache_key] = $player;
          $player_order[] = $cache_key;
        }
        else
        {
          $player_order[] = $player;
        }
      }

      $claim_counts = $ds->claim_several(array_keys($players_by_cache_key)) or throw_notice("unable_to_claim_players");

      $players = array();
      foreach( $player_order as $reference )
      {
        if( is_object($reference) )
        {
          $players[] = $reference;
        }
        else
        {
          $cache_key = $reference;
          $player = $players_by_cache_key[$cache_key];
          if( $claim_counts[$cache_key] == 1 )
          {
            $player = $this->get_player_or_fail($player, $ds);
          }

          $players[] = $player;
        }
      }

      return $players;
    }




  //===============================================================================================
  // SECTION: GameObject management.
  
    function get_object_id( $object )
    {
      return empty($object) ? null : (is_numeric($object) ? $object : $object->get_id());
    }
    
  
    function get_object( $class, $object, $ds = null )
    {
      assert('is_null($ds) or substr(get_class($ds), 0, 10) == "DataSource"');
      
      if( !empty($object) && (!is_object($object) || !is_a($object, $class) || (!is_null($ds) && $object->get_source()->get_id() != $ds->get_id())) )
      {
        $ds or $ds = Script::fetch("ds");
        
        $object_id = is_object($object) ? $object->get_id() : $object;
        $handlers  = ClassLoader::sprintf_with_pedigree("load_%s", $class, $snake_case = true, $be_tolerant = true);
        $signals   = ClassLoader::sprintf_with_pedigree("%s_loaded", $class, $snake_case = true, $be_tolerant = true);

        $object = Script::dispatch($handlers, $object_id, $class, $ds);
        is_null($object)  and $object = $class::load($object_id, $ds);
        is_object($object) or $object = null;
        
        $object and Script::signal($signals, $object, $class, $ds);
      }

      return $object;
    }


    //
    // Loads a DataHandler-backed object for the given $object_id. Pass null to have the $object_id
    // pulled from the parameters, based on the $class name. Pass an object of $class and it will
    // be returned to you.

    function get_object_or_fail( $class, $object_id = null, $ds = null )
    {
      $object = null;
      if( is_object($object_id) )
      {
        $object = $object_id;
      }
      elseif( is_numeric($object_id) && $object_id || (is_string($object_id) && strlen($object_id) > 0) )
      {
        $object = $this->get_object($class, $object_id, $ds) or throw_exception($reportable = false, "unable_to_load_object", "class", $class, "object_id", $object_id);
      }
      else
      {
        throw_exception("unable_to_load_object", "class", $class, "object_id", $object_id);
      }

      return $object;
    }


    //
    // Claims and loads the specified object or throws an exception. If you pass in an object of
    // $class, its ID will be used, and the object will be reloaded (to ensure you get current
    // data).

    function claim_object_or_fail( $class, $object, $ds = null, $block = true, $timeout = 0 )
    {
      return $this->claim_object($class, $object, $ds, $block, $timeout, $fail = true);
    }


    function claim_object( $class, $object, $ds = null, $block = true, $timeout = 0, $fail = false )
    {
      $sc_name = convert_pascal_to_snake_case($class);
      $id_name = sprintf("%s_id", $sc_name);

      $object_id = null;
      if( is_object($object) )
      {
        assert('is_a($object, $class)');
        $object_id = method_exists($object, "get_id") ? $object->get_id() : $object->$id_name;
      }
      else
      {
        $object_id = $object;
      }

      $handlers = ClassLoader::sprintf_with_pedigree("claim_%s"  , $class);
      $signals  = ClassLoader::sprintf_with_pedigree("%s_claimed", $class);
      
      if( $object = Script::dispatch($handlers, $object_id, $class, $ds, $block, $timeout) )
      {
        return $object;
      }
      else
      {
        $claim_key = $class::make_cache_key($object_id);
        if( $claim_count = $this->ds->claim($claim_key, $block, $timeout) )
        {
          if( $claim_count == 1 )
          {
            $class::flush($object_id, $ds);
          }
        
          if( $claim_count == 1 || !is_object($object) || (($source = $object->get_source()) && $ds && $source->get_id() != $ds->get_id()) )
          {
            $object = $this->get_object_or_fail($class, $object_id, $ds);   // At this point we're committed, $fail or not
          }
        
          if( $claim_count == 1 )
          {
            Script::signal("{$sc_name}_claimed", $object, $this->ds);
          }

          return $object;
        }
        elseif( $fail )
        {
          throw_notice("unable_to_claim_object", $class, $object_id);
        }
        else
        {
          return null;
        }
      }
    }


    //
    // Location:
    //   function get_location()           -- see get_object()
    //   function get_location_or_fail()   -- see get_object_or_fail()
    //   function claim_location_or_fail() -- see claim_object_or_fail()
    //   function release_location()       -- see release_location()
    //
    // Player:
    //   function get_player()             -- see get_object()
    //   function get_player_or_fail()     -- see get_object_or_fail()
    //   function claim_player_or_fail()   -- see claim_object_or_fail()
    //   function release_player()         -- see release_location()
    //
    // Logging:
    //   function log_<log_name>( "key1", $value1, "key2", $value2 ... ) -- calls make_log() for you

    function __call( $name, $args )
    {
      if( $this->can_route($name, $this) )
      {
        return $this->route($name, $this, $args);
      }

      if( preg_match('/^(?:get|claim)_(.*?)(?:_or_fail)?$/', $name, $m) )
      {
        $name  = str_replace($m[1], "object", $name);
        $class = convert_snake_to_pascal_case($m[1]);
        return call_user_func_array(array($this, $name), array_merge(array($class), $args));
      }
      elseif( substr($name, 0, 4) == "log_" )
      {
        return $this->make_log($name, $args);
      }

      abort(sprintf("unknown method %s::%s", get_class($this), $name), Features::enabled("development_mode") ? $this->routable_methods_map : null);
    }




  //===============================================================================================
  // SECTION: Logging


    //
    // Inserts a record in some log table. In addition to the log name, pass data as a map of
    // field name => value. Missing fields may be filled in for you by the missing_log_field 
    // filter.

    function log( $name, $pairs, $ds = null, $replace = false)
    {
      $ds = $ds ? $this->ds->limit($ds) : $this->ds;
      if( $table = $ds->schema->get_table($name) )
      {
        is_array($pairs) or $pairs = get_object_vars($pairs);

        $data = array();
        $field_names = $table->get_field_names($include_autoincrement_id = false);
        foreach( $field_names as $name )
        {
          $field_filter = sprintf("missing_%s_field_%s", $table->snake_name, $name);
          $table_filter = sprintf("missing_%s_field"   , $table->snake_name);
          
          if( array_key_exists($name, $pairs) )
          {
            $data[$name] = $pairs[$name];
          }
          elseif( $value = Script::filter(array($field_filter, $table_filter, "missing_log_field"), null, $name, $pairs, $table, $ds) )
          {
            $data[$name] = $value;
          }
          
          if( array_has_member($data, $name) and is_object($data[$name]) and is_a($data[$name], "GameObject") )
          {
            $data[$name] = $this->get_object_id($data[$name]);
          }
        }

        return $table->insert($ds->db, $data, $skip_checks = false, $replace);
      }
      else
      {
        throw_internal_exception("unknown_log_table", "name", $name);
      }
    }


    //
    // Given a log table name (or a function-name analogue with "log_" at the beginning instead of the end)
    // and a flat list of alternating keys and values, inserts a record into the log table. Use the 
    // log_<log_name>( "key1", $value1, "key2", $value2 ... ) magic for 

    function make_log( $name, $args )
    {
      substr($name, 0, 4) == "log_" and $name = convert_snake_to_pascal_case(substr($name, 4));
      $name = Script::filter("log_name", $name);
      
      $ds = null;
      if( count($args) and is_object($args[0]) and method_exists($args[0], "reconnect_for_writing") )
      {
        $ds = array_shift($args);
      }

      return $this->log($name, array_pair($args), $ds);
    }




  //===============================================================================================
  // SECTION: Cached objects

    //
    // Tracks down the subsystem responsible for the named object and retrieves it. If you can,
    // it would be preferable to go directly to the source. This routine is intended primarily
    // for use of database-driven routines (Gameplay/CheckTableVersion, for instance).

    function get_named_object( $name, $max_age = null )
    {
      if( $name == "GameParameterDict" )
      {
        return $this->parameters;
      }
      elseif( $name == "CacheControl" || $name == "ServerCacheControlData" )
      {
        return $this->cache_control;
      }
      else
      {
        $this->load_named_object_map();

        if( !empty($this->named_object_map) && array_key_exists($name, $this->named_object_map) )
        {
          list($property_name, $subproperty_name) = $this->named_object_map[$name];
          return $this->$property_name->$subproperty_name;
        }
        
        return Script::dispatch("missing_named_object", $name);
      }
    }
    
    
    function get_named_object_epoch( $name )
    {
      $epoch = 0;
      
      $this->load_named_object_source_map();
      if( $sources = array_fetch_value($this->named_object_source_map, $name, array()) )
      {
        foreach( $sources as $source )
        {
          $epoch = max($epoch, array_fetch_value($this->cache_control, $source, 0));
        }
      }
      else
      {
        $epoch = array_fetch_value($this->cache_control, $name, 0);
      }
      
      return $epoch;
    }
    
    
    function get_named_objects_built_using( $name )
    {
      $this->load_named_object_map();
      $this->load_named_object_target_map();

      return array_intersect(array_keys($this->named_object_map), array_fetch_value($this->named_object_target_map, $name, array()));
    }
    
    
    function unload_subsystems()
    {
      $args  = func_get_args();
      $names = array_flatten($args) or $names = array_keys($this->subsystems);
      
      foreach( $names as $name )
      {
        if( $subsystem = array_fetch_value($this->subsystems, $name) )
        {
          $subsystem->unload_collections();
          unset($this->subsystems[$name]);
          $subsystem = null;
        }
      }
    }


    //
    // Loads subsystems on demand.

    function __get( $name )
    {
      if( array_key_exists($name, $this->subsystems) )
      {
        return $this->subsystems[$name];
      }
      elseif( $class_name = $this->is_manager($name) )
      {
        $this->subsystems[$name] = $subsystem = new $class_name($this);
        return $subsystem;
      }
      elseif( array_key_exists(strtoupper($name), $this->parameters) )
      {
        return $this->parameters[strtoupper($name)];
      }

      abort("Property doesn't exist: $name");
    }

    function __isset( $name )
    {
      return array_key_exists($name, $this->subsystems) || $this->is_manager($name) || array_key_exists(strtoupper($name), $this->parameters);
    }




  //===============================================================================================
  // SECTION: Event handlers and filters.


    function handle_player_loaded( $player )
    {
      if( !$this->session_player_ready && $this->session_player_data && $player->user_id == $this->session_player_data->user_id )
      {
        $this->session_player_ready = true;
        Script::signal("session_player_ready", $player);
      }
    }

    function handle_get_schema_epoch( $db )
    {
      return array_fetch_value($this->cache_control, "schema", $this->system_epoch);
    }

    function handle_get_query_epoch( $db )
    {
      return $this->handle_get_schema_epoch($db);
    }
    
    
    function filter_unclaimed_db_backed_cache_miss( $data, $ds, $cache_key, $max_age, $query_method, $parameters, $offset )
    {
      if( !$data )
      {
        if( $claim_key = $this->get_game_object_claim_key($cache_key) )
        {
          debug("CACHE FILL attempt on $cache_key via second transaction");
          if( $ds2 = $ds->reconnect_for_reading($clone = true) )
          {
            if( $ds2->claim($claim_key, $block = false) )
            {
              debug("CACHE FILL on $cache_key proceeding with claim on $claim_key");
              $data = $ds2->get_from_cache_or_db($cache_key, $max_age, $query_method, $parameters, $offset);
              if( !is_null($data) )
              {
                $ds2->commit();
                debug("CACHE FILLED for $cache_key!");
              }
            }
            
            $ds2->close();
          }
        }
      }
      
      return $data;
    }
    
    
    function filter_is_cache_key_writable( $flag, $cache_key, $ds )
    {
      if( !$flag )
      {
        if( list($claim_key, $class_name) = $this->get_game_object_claim_key($cache_key, $include_class_name = true) )
        {
          $flag = !$class_name::requires_claim_for_write_back() || $ds->is_claimed($claim_key);

          //
          // If the object isn't writable, see if the key is for a property of the object that doesn't
          // require a claim.

          if( !$flag )
          {
            if( strpos($cache_key, ":") and $key_pieces = explode(":", $cache_key) )
            {
              $collection_name = $key_pieces[1];
              $flag = Script::filter("is_{$class_name}_{$collection_name}_cache_key_writable", $flag, $cache_key, $claim_key);
            }
          }
        }
        else
        {
          $flag = true;
        }
      }
      
      return $flag;
    }

    
    


  //===============================================================================================
  // SECTION: Introspection and such.
  
    function register_subsystem_event_handlers_and_filters()
    {
      //
      // Scan our subsystems for event handler and filter functions labelled public. We read
      // into comments, too, so it's easy to declare inherited methods for registration.

      $handlers = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_subsystem_event_handlers", $this->code_epoch);
      $filters  = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_subsystem_filters"       , $this->code_epoch);

      if( !is_array($handlers) || !is_array($filters) )
      {
        $handlers = array();
        $filters  = array();
        
        foreach( $this->enumerate_subsystems() as $property_name => $path )
        {
          if( $contents = file_get_contents($path) )
          {
            if( preg_match_all('/^\s*(?:public\s+)?function (handle_|filter_)(\w+)\((.*)/m', $contents, $matches, PREG_SET_ORDER) )
            {
              foreach( $matches as $match )
              {
                @list($whole, $type, $name, $rest) = $match;
                $priority = 15;
                $method   = "{$type}{$name}";

                if( $comment = preg_match('/\/\/ (.*)/', $rest, $m) ? $m[1] : "" )
                {
                  if( preg_match('/(do not (auto-)?register)|(not a (filter|event|handler))/', $comment) )
                  {
                    continue;   //<<<<<<<<<< FLOW CONTROL <<<<<<<<<<<
                  }
                
                  preg_match('/\bpriority (\d+)/'         , $comment, $m) and $priority = (int)$m[1];
                  preg_match("/\banswers (?:$type)?(\w+)/", $comment, $m) and $name     = $m[1];
                }
                
                if( $type == "handle_" )
                {
                  $handlers[$property_name][$method] = array($name, $priority);
                }
                elseif( $type == "filter_" )
                {
                  $filters[$property_name][$method]  = array($name, $priority);
                }
              }
            }
          }
        }
        
        $this->ds->set_static("game_subsystem_event_handlers", $handlers);
        $this->ds->set_static("game_subsystem_filters"       , $filters );
      }
      

      //
      // Register everything.

      Script::register_event_handlers_and_filters_from($this, $priority = 1);

      foreach( $handlers as $target => $methods )
      {
        foreach( $methods as $method => $data )
        {
          list($name, $priority) = $data;
          Script::register_event_handler($name, Callback::for_method_of_property($this, $target, $method), $priority);
        }
      }

      foreach( $filters as $target => $methods )
      {
        foreach( $methods as $method => $data )
        {
          list($name, $priority) = $data;
          Script::register_filter($name, Callback::for_method_of_property($this, $target, $method), $priority);
        }
      }
    }


    protected function load_routable_methods_map()
    {
      if( is_null($this->routable_methods_map) )
      {
        $this->routable_methods_map = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_routable_methods_map", $this->code_epoch);
        if( !is_array($this->routable_methods_map) )
        {
          $this->routable_methods_map = array();
          $parameter_count_data       = array("");         // For now, this data won't be used much, so we'll store it as a string to minimize memory footprint
          
          foreach( $this->enumerate_subsystems() as $property_name => $path )
          {
            if( $contents = file_get_contents($path) )
            {
              if( preg_match_all('/^\s*(?:public\s+)?function [&]?(\w+)\(\s*(?:\)|\$(\w+))(.*)/m', $contents, $matches, PREG_SET_ORDER) )
              {
                foreach( $matches as $match )
                {
                  list($whole, $method_name, $first_parameter, $rest) = $match;
                  if( strpos($method_name, "__") !== 0 && strpos($method_name, "handle_") !== 0 && strpos($method_name, "filter_") !== 0 )
                  {
                    if( is_numeric(strpos($rest, " // not routable")) )
                    {
                      // no op
                    }
                    else
                    {
                      //
                      // Take a reasonable guess at parameter counts. We don't actually lex and parse the string, so this really
                      // is just a guess. In particular, we currently ignore the possibility of commas and closing paren inside
                      // constant strings used as default parameters. Lots of other subtleties could throw off the count, too.
                      // BUG: If any come up, we'll deal with it then.
                    
                      $required_parameter_count = 0;
                      $total_parameter_count    = 0;
                      
                      $n = null;
                      if( preg_match('/^(.*?)(\)\s*(\/\/|$))/', $first_parameter . $rest, $n) )
                      {
                        if( $raw = trim($n[1]) and $parameters = explode(',', $raw) )
                        {
                          foreach( $parameters as $parameter )
                          {
                            if( $total_parameter_count == $required_parameter_count and !is_numeric(strpos($parameter, "=")) )
                            {
                              $required_parameter_count += 1;
                            }
                            
                            $total_parameter_count += 1;
                          }
                        }
                      }
                      
                      //
                      // Store the routable method.
                      
                      if( is_numeric(strpos($rest, " // game method")) )
                      {
                        $class_name = "GameEngine";
                        array_key_exists($class_name, $this->routable_methods_map) or $this->routable_methods_map[$class_name] = array();
                        $this->routable_methods_map[$class_name][$method_name] = $property_name;
                      }

                      $class_name = convert_snake_to_pascal_case($first_parameter);
                      if( ClassLoader::is_loadable($class_name) && (is_subclass_of($class_name, "Metaclass") || is_subclass_of($class_name, "Changeling")) )
                      {
                        $parameter_count_data[] = sprintf("%s.%s:%02d-%02d", $class_name, $method_name, $required_parameter_count, $total_parameter_count);
                      
                        array_key_exists($class_name, $this->routable_methods_map) or $this->routable_methods_map[$class_name] = array();
                        $this->routable_methods_map[$class_name][$method_name] = $property_name;

                        //
                        // For disambiguation at the manager (PHP doesn't support two functions with the same name 
                        // in the same class, even with different parameter lists), we also handle the situation 
                        // where the Metaclass class name is appended to the method name after a double underscore.
                        // Originally, the plan was to allow the class name to appear anywhere in the method name,
                        // but it would make searching for a simplified name difficult. In any event, in this case,
                        // we also register the simplified version with the relevant class routes. It's a low-priority
                        // operation: real methods with the simplified name always win.
                        //
                        // In other words: $manager->get_x__location($location) will be available as $location->get_x().

                        $disambiguation = "__$first_parameter";
                        $length         = strlen($disambiguation);
                        if( substr($method_name, -$length) == $disambiguation )
                        {
                          $simpler_name = substr($method_name, 0, -$length);
                          if( !array_has_member($this->routable_methods_map[$class_name], $simpler_name) )
                          {
                            $this->routable_methods_map[$class_name][$simpler_name] = array($property_name, $method_name);
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
          
          $this->routable_methods_map[0] = implode("|", $parameter_count_data);
          $this->ds->set_static("game_routable_methods_map", $this->routable_methods_map);
        }
      }
    }


    protected function load_named_object_map()
    {
      if( is_null($this->named_object_map) )
      {
        $this->named_object_map = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_named_object_map");
        if( !is_array($this->named_object_map) )
        {
          $this->named_object_map = array("GameParameterDict" => null);
          
          $source_map = array("GameParameterDict" => Script::filter("game_parameter_dict_sources", array("GameParameterDict")));
          foreach( $this->enumerate_subsystems() as $property_name => $path )
          {
            if( $contents = file_get_contents($path) )
            {
              if( preg_match_all('/\$(\w+).*? \/\/ named[_ ]object ([^\s]*)(?:\s+built[_ ]using\s+(.*))?/', $contents, $matches, PREG_SET_ORDER) )
              {
                foreach( $matches as $match )
                {
                  @list($whole, $subsystem_property_name, $object_name, $source_string) = $match;
                  $this->named_object_map[$object_name] = array($property_name, $subsystem_property_name);
                  
                  $source_map[$object_name] = array($object_name);
                  if( $source_string && ($sources = preg_split('/,\s*|\s+/', trim($source_string))) )
                  {
                    $source_map[$object_name] = array_merge($source_map[$object_name], $sources);
                  }
                }
              }
            }
          }
          
          $this->named_object_source_map = tclose($source_map);
          
          //
          // Invert the transitively-closed source map into a target map.
          
          $this->named_object_target_map = array();
          foreach( $this->named_object_source_map as $target => $sources )
          {
            foreach( $sources as $source )
            {
              if( $target != $source )
              {
                $this->named_object_target_map[$source][$target] = $target;
              }
            }
          }
          
          $this->ds->set_static("game_named_object_map"       , $this->named_object_map       );
          $this->ds->set_static("game_named_object_source_map", $this->named_object_source_map);
          $this->ds->set_static("game_named_object_target_map", $this->named_object_target_map);
        }
      }

      return count($this->named_object_map);
    }
    
    
    protected function load_named_object_source_map()
    {
      if( is_null($this->named_object_source_map) )
      {
        $this->named_object_source_map = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_named_object_source_map");
        if( !is_array($this->named_object_source_map) )
        {
          $this->load_named_object_map();
        }
      }
      
      return count($this->named_object_source_map);
    }
    
    
    protected function load_named_object_target_map()
    {
      if( is_null($this->named_object_target_map) )
      {
        $this->named_object_target_map = $this->ds->filter_by("age", $this->code_epoch)->get_static("game_named_object_target_map");
        if( !is_array($this->named_object_target_map) )
        {
          $this->load_named_object_map();
        }
      }
      
      return count($this->named_object_target_map);
    }


    protected function enumerate_subsystems()
    {
      $subsystems = array();
      foreach( ClassLoader::enumerate_classes_matching("/^Game[A-Z]/") as $name => $path )
      {
        if( get_parent_class($name) == "GameSubsystem" )
        {
          $property_name = convert_pascal_to_snake_case(substr($name, 4));
          $subsystems[$property_name] = $path;
        }
      }

      return $subsystems;
    }



    function route_cache_key( $cache, $cache_key, $static, $ds )
    {
      if( $static && (string)$cache_key != "CacheControl" )
      {
        debug("ROUTING static $cache_key to \$game->library");
        $cache = $this->library;
      }
      else
      {
        debug("ROUTING $cache_key to default cache");
      }
      
      return $cache;
    }


  }
