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

  require_once "determine_accepted_languages.php";
  require_once "describe_time_interval_en.php";

  Conf::define_term("GAME_STRING_KEY_CHARACTERS", "the number of key characters used to break the GameStrings map into chunks", 3);


  class GameStringManager extends GameSubsystem
  {
    // magic_loaded $supported_languages;
    // magic_loaded $preferred_language;
    // magic_loaded $game_strings;              // named_object GameStringDict 
    
    public $client_language_preferences;
    protected $namespaces;


    function __construct( $engine )
    {
      parent::__construct($engine, __FILE__, "Strings", "GameStringDict");
      $this->set_language_preferences(determine_accepted_languages());
      $this->namespaces = array();
    }
    
    
    function set_language_preferences( $languages )     // game method
    {
      $this->client_language_preferences = $languages or $this->client_language_preferences = array('en');
      $this->preferred_language          = $this->client_language_preferences[0];
      $this->ordering_name               = implode(",", $this->client_language_preferences);
      $this->ordering_clause             = "ORDER BY FIELD(language, " . implode(array_map(Callback::for_method($this->get_ds(), "format_string_parameter")->get_php_callback(), $this->client_language_preferences), ", ") . ") desc";
      $this->loaded_strings              = array();
      $this->loaded_slices               = array();
    }


    function get_preferred_language( $player )
    {
      return $this->preferred_language;
    }


    function get_client_language_preferences_in_string()
    {
      return implode(", ", $this->client_language_preferences);
    }
    
    
    function add_namespace( $namespace, $manage = false )
    {
      $this->namespaces[$namespace] = array_fetch_value($this->namespaces, $namespace, 0) + 1;
      return $manage ? new StringNamespace($namespace) : $namespace;
    }

    
    function remove_namespace( $namespace, $manage = false )
    {
      if( $value = array_fetch_value($this->namespaces, $namespace) )
      {
        $this->namespaces[$namespace] -= 1;
        if( $this->namespaces[$namespace] <= 0 )
        {
          unset($this->namespaces[$namespace]);
        }
      }
      
      return $manage ? new StringNamespace($namespace, "add") : $namespace;
    }
    
    
    function enter_namespace( $namespace )
    {
      return $this->add_namespace($namespace, $manage = true);
    }
    
    function exit_namespace( $namespace )
    {
      return $this->remove_namespace($namespace, $manage = true);
    }
    
    
    function has_translation( $name, $trust_name = true )
    {
      if( trim($name) == "" )
      {
        return true;
      }
      elseif( $trust_name and is_numeric(strpos($name, " ")) || (strtolower($name) != $name) )
      {
        return true;
      }
      
      return (boolean)$this->get_string($name);
    }
    
    
    function get_active_namespaces()
    {
      $active_namespaces = array();
      foreach( $this->namespaces as $name => $count )
      {
        if( $count > 0 )
        {
          $active_namespaces[$name] = $name;
        }
      }
      
      return $active_namespaces;
    }
    
    
    function translate_string_if_exists( $name, $parameters, $trust_name = true )
    {
      if( $this->has_translation($name, $trust_name) )
      {
        return $this->translate_string($name, $parameters);
      }
      
      return null;
    }


    function translate_string( $__name, $__parameters, $__alert_for_missing_translation = true )
    {
      if( trim($__name) == "" or is_numeric(strpos($__name, " ")) or (strtolower($__name) != $__name) )
      {
        return $__name;
      }
      
      $game     = $this->engine;
      $empty    = false;                          // A translation that is intentionally empty should set this to avoid a warning. 
      $__string = $this->get_string($__name);
      if( !is_null($__string) )
      {
        //
        // Load the parameters into the local environment.

        foreach( $__parameters as $__key => $__value )
        {
          if( preg_match("/^\w+$/", $__key) )
          {
            $$__key = $__value;
          }
        }

        //
        // Execute the string as PHP text and capture the output to return.

        ob_start();
        Features::enabled("development_mode") ? eval("?>$__string") : @eval("?>$__string");
        $__translation = trim(ob_get_clean());
        if( $__translation || $empty )
        {
          return $__translation;
        }
        else
        {
          if( $__translation = Script::filter("untranslated_string", "", $__name, array_keys($this->namespaces)) )
          {
            return $__translation;
          }
          elseif( $__alert_for_missing_translation )
          {
            warn("Unable to build translation for [$__name] in best language of (" . implode(", ", $this->client_language_preferences) . ")");
          }

          return $__name;
        }
      }
      elseif( $__translation = Script::filter("untranslated_string", "", $__name, array_keys($this->namespaces)) )
      {
        return $__translation;
      }
      else
      {
        if( $__alert_for_missing_translation && !is_numeric(mb_strpos($__name, " ")) )
        {
          warn("Unable to find translation for [$__name] in any acceptable language (" . implode(", ", $this->client_language_preferences) . ") in namespaces (" . implode(", ", $this->get_active_namespaces()) . ")");
        }

        return $__name;
      }
    }


    function get_string( $name )    // game method
    {
      if( trim($name) == "" )
      {
        return null;
      }
      
      $ds = $this->get_ds();
      $key_characters = $this->get_parameter("GAME_STRING_KEY_CHARACTERS", 3);
      
      //
      // String names generally proceed from least specific ("item", "mission", etc.) to most specific
      // (mission name, item name, etc.) As a result, if we want to optimize for locality of 
      // reference (find all the translations for mission x), the front of the string is pretty much
      // useless. So, we reverse the strings for lookup. It's more expensive—particularly in the 
      // database—but should greatly improve cache performance, which will be the average case.
      //
      // For the same reason, namespaces are appended to the name.
      
      $full_names = array($name);
      foreach( $this->namespaces as $namespace => $count )
      {
        if( $count > 0 )
        {
          $full_names[] = "$name:$namespace";
        }
      }
      
      foreach( array_reverse($full_names) as $name )
      {
        $subset = strtolower(substr(strrev($name), 0, $key_characters));
        if( !array_key_exists($subset, $this->loaded_strings) )
        {
          $escaped = $ds->escape($subset, $for_like = true);
          $this->loaded_strings[$subset] = $ds->query_static_map("GameStrings:$subset", array("name", "language"), "text", "SELECT name, language, text FROM GameStringDict WHERE reverse(name) LIKE '$escaped%'");
        }
      
        if( !empty($this->loaded_strings[$subset]) )
        {
          $map =& $this->loaded_strings[$subset];
          count($map) > 100 and warn("GameStrings:$subset has more than 100 elements; time to increase GAME_STRING_KEY_CHARACTERS");
          if( array_key_exists($name, $map) )
          {
            $translations =& $map[$name];
            foreach( $this->client_language_preferences as $language )
            {
              if( array_key_exists($language, $translations) )
              {
                return $translations[$language];
              }
            }
          }
        }
      }

      return null;
    }
    
    
    function &get_slice( $prefix )
    {
      $name = sprintf("%s[%s]", $prefix, $this->ordering_name);
      if( !array_key_exists($name, $this->loaded_slices) )
      {
        $this->loaded_slices[$name] = $this->ds->query_static_map("GameStrings::$name", "name", "text", "SELECT name, text FROM GameStringDict WHERE name LIKE '$prefix%' " . $this->ordering_clause);
      }
      
      return $this->loaded_slices[$name];
    }




  //===============================================================================================
  // SECTION: Event handlers.




  //===============================================================================================
  // SECTION: Loaders.

    function load_supported_languages()
    {
      return $this->load_map_of_table("GameLanguageDict", "code", "description");
    }


    function load_preferred_language()
    {
      $preferred = "en";
      foreach( $this->client_language_preferences as $language )
      {
        if( strlen($language) == 2 && array_key_exists($language, $this->supported_languages) )
        {
          $preferred = $language;
          break;
        }
      }

      return $preferred;
    }
    
    
    function load_game_strings()
    {
      return $this->load_map("GameStringDict", array("language", "name"), "text", "SELECT * FROM GameStringDict");
    }
  }
