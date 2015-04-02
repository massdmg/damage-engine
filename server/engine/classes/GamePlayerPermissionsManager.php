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


  class GamePlayerPermissionsManager extends GameSubsystem
  {
    public $last_decision = null;

    function __construct( $engine )
    {
      parent::__construct($engine, __FILE__, "Permissions");
      $this->last_decision = null;
    }
    
    
    function get_last_permission_decision()    // game method
    {
      return $this->last_decision;
    }
    
    
    function get_last_permission_decision_reasons()   // game method
    {
      return $this->last_decision ? $this->last_decision->reasons : array();
    }
    
    



  //===============================================================================================
  // SECTION: Player.permissions DataHandler definitions

    function handle_player_build_permissions_handler( $name, $player, $ds )
    {
      $details = array
      (
          "key"            => "permission"
        , "post_processor" => Callback::for_method($this, "fix_permission_type")
        , "load_filter"    => Callback::for_method($this, "filter_out_stale_permissions")
      );
    
      return $player->build_handler_from_table($name, "UserPermissionData", $details, $this->get_ds($ds));
    }
    
    
    function fix_permission_type( $record )
    {
      $record->value = coerce_type($record->value, false);
      return $record;
    }
    
    function filter_out_stale_permissions( $unfiltered )    // not a filter
    {
      $now      = now();
      $filtered = array();
      foreach( $unfiltered as $permission => $record )
      {
        if( empty($record->until) || $now <= $record->until )
        {
          $filtered[$permission] = $record;
        }
      }
      
      return $filtered;
    }
    
    
    
    function get_permissions_starting( $player, $string )
    {
      $matching = array();
      foreach( $player->permissions as $permission => $row )
      {
        if( substr($permission, 0, strlen($string)) == $string )
        {
          $matching[$permission] = $row->value;
        }
      }
      
      return $matching;
    }
    
    function get_permission_termination( $player, $permission )
    {
      return structure_fetch_value($player->permissions, array($permission, "until"), null);
    }


    
  //===============================================================================================
  // SECTION: changes

    function grant_permission( $player, $permission, $ds = null, $until = null, $override = false )
    {
      $ds     = $this->get_ds($ds);
      $game   = $this->engine;
      $player = $game->get_player_or_fail($player);
      $before = $this->get_permission_data($player, $permission);
      
      $player->claim_handler_or_fail("permissions");
      
      if( !$override && $before->value == true && !is_null($until) )
      {
        if( is_null($before->until) or $before->until > $until )
        {
          throw_exception("permission_existing_grant_lasts_longer");
        }
      }
      
      $player->replace("permission", "permission", $permission, "value", true, "until", $until);
      $player->save();
      
      $this->log_permission_change($player, $op = "grant", $permission, $before, $ds);

      Script::signal("permission_granted", $player, $permission, $until, $ds);
    }
    
    
    function reset_permission( $player, $permission, $ds = null )
    {
      $ds     = $this->get_ds($ds);
      $game   = $this->engine;
      $player = $game->get_player_or_fail($player);
      $before = $this->get_permission_data($player, $permission);
      
      $player->claim_handler_or_fail("permissions");
      
      $player->delete("permission", "permission", $permission);
      $player->save();

      $this->log_permission_change($player, $op = "reset", $permission, $before, $ds);

      Script::signal("permission_reset", $player, $permission, $ds);
    }
    
    
    
    function revoke_permission( $player, $permission, $ds = null, $until = null, $override = false )
    {
      $ds     = $this->get_ds($ds);
      $game   = $this->engine;
      $player = $game->get_player_or_fail($player);
      $before = $this->get_permission_data($player, $permission);

      $player->claim_handler_or_fail("permissions");
      
      if( !$override && $before->value === false && !is_null($until) )
      {
        if( is_null($before->until) or $before->until > $until )
        {
          throw_exception("permission_existing_revokation_lasts_longer");
        }
      }
      
      $player->replace("permission", "permission", $permission, "value", false, "until", $until);
      $player->save();

      $this->log_permission_change($player, $op = "revoke", $permission, $before, $ds);

      Script::signal("permission_revoked", $player, $permission, $until, $ds);
    }
    
    
    function revoke_permission_until( $player, $permission, $until, $ds = null, $override = false )
    {
      return $this->revoke_permission($player, $permission, $ds, $until, $override);
    }
    
    
    

  //===============================================================================================
  // SECTION: services
  
    function can( $player, $what, $default, $extra = null, $decision = null, $specialize = true )
    {
      $decision or $decision = new PermissionDecision();
      $game = $this->engine;

      $specific_checks = array($what);
      if( $specialize === true and is_object($extra) and $game->can_route("get_cache_key", $extra) )
      {
        array_unshift($specific_checks, sprintf("%s:%s", $what, $extra->get_cache_key()));
      }
      elseif( is_string($specialize) )
      {
        array_unshift($specific_checks, $specialize);
      }

      foreach( $specific_checks as $specifically_what )
      {
        if( $record = structure_fetch_value($player->permissions, $specifically_what) )
        {
          $decision->record($record->value, "permission_explicitly_set", "what", $specifically_what, "until", $record->until);
        }
      }
      
      $filters  = array("can_player_$what", "can_player");
      $decision = Script::filter($filters, $decision, $player, $what, $extra, $default);
      is_object($decision) and is_a($decision, "PermissionDecision") or abort("can_player filter did not return decision");
      
      $this->last_decision = $decision;
      return $decision->is_made() ? $decision->is_yes() : $default;
    }
    
    
    function cannot( $player, $what, $default = false, $extra = null, $decision = null, $specialize = true )
    {
      return !$this->can($player, $what, is_null($default) ? null : !$default, $extra, $decision, $specialize);
    }
    
    
    function specifically_can( $player, $what )
    {
      return array_key_exists($what, $player->permissions) ? ($player->permissions[$what]->value === true) : false;
    }
    
    
    function specifically_cannot( $player, $what )
    {
      return array_key_exists($what, $player->permissions) ? ($player->permissions[$what]->value === false) : false;
    }




  //===============================================================================================
  // SECTION: Assertions

    function throw_permission_denied( $player, $what, $can = true )    // game method
    {
      if( $this->last_decision )
      {
        $this->last_decision->throw_first_reason_as_exception($player, $what, $can);
      }
      
      throw_exception("permission_denied", "player", $player, "what", $what, "required", $can ? "cannot" : "can", "actual", $can ? "can" : "cannot", "reasons", $this->last_decision);
    }
  
    function can_or_fail( $player, $what, $default, $extra = null, $decision = null, $specialize = true )
    {
      $result = $this->can($player, $what, $default, $extra, $decision, $specialize) or $this->throw_permission_denied($player, $what, $required = true);
      return $result;
    }

    
    
    function specifically_can_or_fail( $player, $what )
    {
      $result = $this->specifically_can($player, $what) or $this->throw_permission_denied($player, $what, $can = false);
      return $result;
    }
    
    function specifically_cannot_and_fail( $player, $what )
    {
      $result = $this->specifically_cannot($player, $what) and $this->throw_permission_denied($player, $what, $can = true);
      return $result;
    }




  //===============================================================================================
  // SECTION: Special services

    function is_not_abusing_service( $player, $script_name, $limit, $limit_period, $permission, $revocation_period, $ds = null )
    {
      if( $player->can($permission, true) )
      {
        $ds    = $this->get_ds($ds);
        $query = "SELECT count(*) as count FROM ServerExecutionLog WHERE user_id = ? and service = ? and index_s > ?";
        if( $ds->query_value($cached = false, "count", 0, $query, $player->user_id, $script_name, time() - $limit_period) > $limit )
        {
          $player->revoke_permission($permission, $ds, $until = now(+$revocation_period));
          return false;
        }
        
        return true;
      }
      
      return false;
    }


  

  //===============================================================================================
  // SECTION: Internals
  
  
    function get_permission_data( $player, $permission, $objectify = true )
    {
      if( $value = array_fetch_value($player->permissions, $permission) )
      {
        return clone $value;
      }
      elseif( $objectify )
      {
        return (object)array("permission" => $permission, "value" => null, "until" => null);
      }
      
      return null;
    }


    function log_permission_change( $player, $op, $permission, $before, $ds )
    {
      $after      = $this->get_permission_data($player, $permission);
      $changed_by = $this->engine->get_user_id();
      $changed_by == $player->user_id and $changed_by = null;
      
      $this->engine->log_user_permission_change($ds, "user_id", $player->user_id, "op", $op, "permission", $permission, "before", $this->describe_permission($before), "after", $this->describe_permission($after), "changed_by", $changed_by);
    }
    
    
    function describe_permission( $details, $permission = null )
    {
      if( is_null($details->value) )
      {
        $string = ($permission ? "$permission " : "") . "uses default behaviour";
      }
      else
      {
        $string = $details->value ? "can" : "cannot";
        $permission and $string .= " $permission";
        
        if( $details->until )
        {
          $string .= " until " . $details->until;
        }
      }
      
      return $string;
    }




  //===============================================================================================
  // SECTION: Loaders.


  }
