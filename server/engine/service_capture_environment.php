<?php if (defined($inc = "ENGINE_SERVICE_CAPTURE_ENVIRONMENT_INCLUDED")) { return; } else { define($inc, true); }

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
  // Support capturing the I/O for the call.

  if( $capture_tokens = trim((string)$ds->get("debug_capture_tokens")) )
  {
    if( $capture_tokens = (array)preg_split('/,\s*/', $capture_tokens) )
    {
      if( $captured_token = Script::get_parameter("token") )
      {
        $captured_token_content = $ds->get($captured_token);
        $captured_user_id = is_object($captured_token_content) ? (string)@$captured_token_content->user_id : "";
        if( in_array($captured_token, $capture_tokens) || ($captured_user_id && in_array($captured_token = $captured_user_id, $capture_tokens)) )
        {
          global $debug_capture;
          $debug_capture = (object)null;
          $debug_capture->service  = $_REQUEST["service"];
          $debug_capture->capture  = $captured_token;
          $debug_capture->started  = now_u();
          $debug_capture->finished = "";
          $debug_capture->input    = project_away($_REQUEST, "service");
          $debug_capture->output   = null;

          function store_debug_capture( $json_response )
          {
            global $debug_capture;
            $debug_capture->finished = now_u();
            $debug_capture->output   = $json_response->envelope;

            $cache  = Script::fetch("ds")->cache;
            $key    = "debug_capture_for_" . $debug_capture->capture;
            $json   = (string)@$cache->claim_and_get($key);
            $list   = (array)@json_decode($json);
            $list[] = $debug_capture;

            if( $json = @json_encode($list) )
            {
              $cache->set($key, $json, "expiry=900");      // To manage cache resources, you've got 15 minutes to collect after the last use
              @file_put_contents("/tmp/$key.json", $json);
            }
          }

          Script::register_event_handler("json_response_sent", "store_debug_capture");
        }
      }
    }
  }
  