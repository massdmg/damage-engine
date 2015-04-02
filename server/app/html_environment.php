<?php if (defined($inc = "APP_HTML_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }


  require_once __DIR__ . "/game_environment.php";
  require_once __DIR__ . "/../engine/html_environment.php";
  