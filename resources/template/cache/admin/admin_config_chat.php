<?php class Templateadmin_admin_config_chatf09c3bef4ae491e034edc93415a9471c{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->admin_forbidden1($args,$parent,$i);$buffer.='

';$buffer.=$this->admin_forbidden3($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function admin_forbidden1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="error-page">
  <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("forbidden_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>
</div>
';} return $buffer;}function cfg_guest_posting17($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_guest_posting",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_guest_captcha21($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_guest_captcha",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_login_to_read25($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_login_to_read",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_chat_enabled29($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_chat_enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_access_open_selected33($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_access_open_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_access_waiting_selected37($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_access_waiting_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_access_approval_selected41($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_access_approval_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_access_members_selected45($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_access_members_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_approval_fallback_waiting51($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_approval_fallback_waiting",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_waiting_room_mandatory71($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_waiting_room_mandatory",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_newest_first91($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_newest_first",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_bbcode_enabled95($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_bbcode_enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_link_conversion99($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_link_conversion",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_announce_join_leave103($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_announce_join_leave",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_announce_mod_actions107($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_announce_mod_actions",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_image_embed111($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_image_embed",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_names_link_to_profile159($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_names_link_to_profile",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_allow_user_color171($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_allow_user_color",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_allow_pm191($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_allow_pm",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_disable_guest_pm199($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_disable_guest_pm",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_censor_mode_replace_selected211($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_censor_mode_replace_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_censor_mode_block_selected215($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_censor_mode_block_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_show_timestamps_default257($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_show_timestamps_default",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_profile_button281($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_profile_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_help_button285($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_help_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_notes_button289($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_notes_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_rules_button293($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_rules_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_clone_button297($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_clone_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_admin_button301($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_admin_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_reload_button305($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_reload_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_hide_rearrange_button309($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_hide_rearrange_button",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_uploads_enabled315($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_uploads_enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_uploads_guests319($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_uploads_guests",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function admin_forbidden3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(!$resolved){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>

<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_access",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_guest_posting",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="guest_posting" value="1"';$buffer.=$this->cfg_guest_posting17($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_guest_captcha",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="guest_captcha" value="1"';$buffer.=$this->cfg_guest_captcha21($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_login_to_read",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="require_login_to_read" value="1"';$buffer.=$this->cfg_require_login_to_read25($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_chat_enabled",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="chat_enabled" value="1"';$buffer.=$this->cfg_chat_enabled29($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_guest_access_mode",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td>
        <select name="guest_access_mode" class="input">
          <option value="open"';$buffer.=$this->cfg_access_open_selected33($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_access_open",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
          <option value="waiting_room"';$buffer.=$this->cfg_access_waiting_selected37($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_access_waiting",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
          <option value="moderator_approval"';$buffer.=$this->cfg_access_approval_selected41($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_access_approval",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
          <option value="members_only"';$buffer.=$this->cfg_access_members_selected45($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_access_members",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
        </select>
      </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_approval_fallback_waiting",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="approval_fallback_waiting" value="1"';$buffer.=$this->cfg_approval_fallback_waiting51($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_disabled_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="disabled_message" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_disabled_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" style="width:30em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_entry_password",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="entry_password" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_entry_password",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="128" style="width:16em" class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_waiting",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_waiting_room_seconds",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="waiting_room_seconds" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_waiting_room_seconds",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input"> s</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_waiting_room_mandatory",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="waiting_room_mandatory" value="1"';$buffer.=$this->cfg_waiting_room_mandatory71($args,$parent,$i);$buffer.=' class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_messages",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_length",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="max_length" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_length",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_messages_shown",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="messages_shown" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_messages_shown",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_retention_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="retention_minutes" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_retention_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"> min</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_newest_first",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="newest_first" value="1"';$buffer.=$this->cfg_newest_first91($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_bbcode_enabled",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="bbcode_enabled" value="1"';$buffer.=$this->cfg_bbcode_enabled95($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_link_conversion",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="link_conversion" value="1"';$buffer.=$this->cfg_link_conversion99($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_announce_join_leave",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="announce_join_leave" value="1"';$buffer.=$this->cfg_announce_join_leave103($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_announce_mod_actions",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="announce_mod_actions" value="1"';$buffer.=$this->cfg_announce_mod_actions107($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_image_embed",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="image_embed" value="1"';$buffer.=$this->cfg_image_embed111($args,$parent,$i);$buffer.=' class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_flood",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_min_flood_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="min_flood_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_min_flood_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input"> s</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_flood_mute_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="flood_mute_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_flood_mute_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input"> s</td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_refresh",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="default_refresh_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"> s</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_min_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="min_refresh_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_min_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"> s</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="max_refresh_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"> s</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_online_window_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="online_window_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_online_window_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="5" class="input"> s</td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_nicknames",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_nick_min_len",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="nick_min_len" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_nick_min_len",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_nick_max_len",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="nick_max_len" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_nick_max_len",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_names_link_to_profile",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="names_link_to_profile" value="1"';$buffer.=$this->cfg_names_link_to_profile159($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_nick_regex",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="nick_regex" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_nick_regex",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="128" style="width:20em" class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_colors",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_user_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="allow_user_color" value="1"';$buffer.=$this->cfg_allow_user_color171($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="default_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="32" style="width:8em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_bg_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="default_bg_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_bg_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="32" style="width:8em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_font_family",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="default_font_family" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_font_family",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="32" style="width:12em" class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="allow_pm" value="1"';$buffer.=$this->cfg_allow_pm191($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_pm_retention_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="pm_retention_minutes" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_pm_retention_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input"> min</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_disable_guest_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="disable_guest_pm" value="1"';$buffer.=$this->cfg_disable_guest_pm199($args,$parent,$i);$buffer.=' class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_censor",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_censor_words",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><textarea name="censor_words" rows="6" cols="40" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_censor_words",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</textarea></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_censor_mode",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td>
        <select name="censor_mode" class="input">
          <option value="replace"';$buffer.=$this->cfg_censor_mode_replace_selected211($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_censor_mode_replace",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
          <option value="block"';$buffer.=$this->cfg_censor_mode_block_selected215($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_censor_mode_block",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
        </select>
      </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_censor_replacement",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="censor_replacement" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_censor_replacement",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="32" style="width:8em" class="input"></td></tr>
  </table>
  <p><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_filters_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_filters_link",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a></p>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_room",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_room_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="room_topic" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_room_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" style="width:30em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_room_rules",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><textarea name="room_rules" rows="4" cols="40" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_room_rules",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</textarea></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_online",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="max_online" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_online",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_chat_name",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="chat_name" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_chat_name",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="64" style="width:20em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_greeting_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><textarea name="greeting_message" rows="3" cols="40" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_greeting_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</textarea></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_timestamps",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_show_timestamps_default",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="show_timestamps_default" value="1"';$buffer.=$this->cfg_show_timestamps_default257($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_timestamp_format",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="timestamp_format" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_timestamp_format",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="32" style="width:8em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_timezone",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="default_timezone" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_timezone",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="48" style="width:16em" class="input"></td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_moderation",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_kick_penalty_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="kick_penalty_minutes" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_kick_penalty_minutes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input"> min</td></tr>
  </table>

  ';$buffer.='
  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_toolbar",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_profile_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_profile_button" value="1"';$buffer.=$this->cfg_hide_profile_button281($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_help_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_help_button" value="1"';$buffer.=$this->cfg_hide_help_button285($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_notes_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_notes_button" value="1"';$buffer.=$this->cfg_hide_notes_button289($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_rules_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_rules_button" value="1"';$buffer.=$this->cfg_hide_rules_button293($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_clone_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_clone_button" value="1"';$buffer.=$this->cfg_hide_clone_button297($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_admin_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_admin_button" value="1"';$buffer.=$this->cfg_hide_admin_button301($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_reload_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_reload_button" value="1"';$buffer.=$this->cfg_hide_reload_button305($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_rearrange_button",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="hide_rearrange_button" value="1"';$buffer.=$this->cfg_hide_rearrange_button309($args,$parent,$i);$buffer.=' class="input"></td></tr>
  </table>

  <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_uploads",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_uploads_enabled",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="uploads_enabled" value="1"';$buffer.=$this->cfg_uploads_enabled315($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_uploads_guests",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="checkbox" name="uploads_guests" value="1"';$buffer.=$this->cfg_uploads_guests319($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_upload_max_kb",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="upload_max_kb" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_upload_max_kb",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" class="input" style="width:8em"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_upload_max_dimension",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="number" name="upload_max_dimension" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_upload_max_dimension",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="0" class="input" style="width:8em"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_upload_types",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="upload_types" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_upload_types",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="128" class="input" style="width:18em"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_upload_dir",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th>
      <td><input type="text" name="upload_dir" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_upload_dir",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="255" class="input" style="width:24em"></td></tr>
  </table>

  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" class="input"></p>
</form>

<p><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_console_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_console_link",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a></p>
';} return $buffer;}}