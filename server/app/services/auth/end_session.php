<?php

  Features::disable("game_actions_on_commit");

  $token = Script::get_parameter("token");
  $game->end_session($token, $ds);
  $game->commit_and_respond();
