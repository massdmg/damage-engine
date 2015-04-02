<?php if (defined($inc = "APP_TOOL_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

  $admin = $game->get_player() and $admin->can_or_fail("use_admin_tools", $admin->is_admin()) or Features::security_disabled() or deny_access();

  if( Features::enabled("development_mode") and !Script::has_parameter("log_inside") )
  {
    Features::disabled("debug_logging") and Features::reset("debug_logging");
    register_debug_logging_controls("MySQLConnection");
  }  
