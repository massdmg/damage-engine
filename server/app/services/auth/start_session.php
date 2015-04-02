<?php
  
  $signature = array();
  $signature["user_id"  ] = $user_id   = Script::get_parameter("user_id"  , "");    // signature field 1 of 3
  $signature["device_id"] = $device_id = Script::get_parameter("device_id", "");    // signature field 2 of 3
  $signature["address"  ] = $address   = Script::get_parameter("address"  , "");    // signature field 3 of 3

  $game->validate_signed_request($client_platform, $client_version, $signature);

  $player = $game->get_player_or_fail($user_id);                                    // not a script parameter
  $token  = $player->start_session_or_fail();
  if( $device_id or Features::enabled("security") )
  {
    $player->validate_device_association($device_id, $ds);
  }

  $game->commit_and_respond($token, $player);
