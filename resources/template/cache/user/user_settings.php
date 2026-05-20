<?php class Templateuser_user_settings5c17c27419e752765ef8cb24252537b9{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_heading",$args,$parent,$i));$buffer.='</h2>
<hr>
';$buffer.=$this->show_avatar4($args,$parent,$i);$buffer.='
';$buffer.=$this->show_display_name6($args,$parent,$i);$buffer.='
';$buffer.=$this->show_email8($args,$parent,$i);$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_username",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.change_username",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.change_username",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="change_username">
  <p>
    ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("field_current_value",$args,$parent,$i));$buffer.=': ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='<br>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_new_username",$args,$parent,$i));$buffer.=': <input type="text" name="username" class="input"></label><br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
<hr>
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_password",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.change_password",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.change_password",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="change_password">
  <p>
    ';$buffer.=$this->token_unlock30($args,$parent,$i);$buffer.='
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_new_password",$args,$parent,$i));$buffer.=': <input type="password" name="password" class="input" autocomplete="new-password"></label><br>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_repeat",$args,$parent,$i));$buffer.=': <input type="password" name="repeat" class="input" autocomplete="new-password"></label><br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
<hr>
';$buffer.=$this->is_verified38($args,$parent,$i);$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys",$args,$parent,$i));$buffer.='</h3>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_desc",$args,$parent,$i));$buffer.='</p>

';$buffer.=$this->show_new_api_key44($args,$parent,$i);$buffer.='

';$buffer.=$this->has_api_keys46($args,$parent,$i);$buffer.='

';$buffer.=$this->has_api_keys48($args,$parent,$i);$buffer.='

';$buffer.=$this->show_api_key_create50($args,$parent,$i);$buffer.='

';$buffer.=$this->show_api_key_create52($args,$parent,$i);$buffer.='
<hr>

';$buffer.=$this->show_theme_picker54($args,$parent,$i);$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_delete",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.delete_account",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.delete_account",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="delete_account">
  <p>
    ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_delete_confirm",$args,$parent,$i));$buffer.='<br>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_old_password",$args,$parent,$i));$buffer.=': <input type="password" name="password" class="input"></label>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>';return ($buffer) ? $buffer : "";}function show_avatar4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_avatar",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_avatar",$args,$parent,$i));$buffer.='</h3>
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.set_avatar",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.set_avatar",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="set_avatar">
  <p>
    <img src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("avatar_url",$args,$parent,$i));$buffer.='" alt="avatar" style="width:80px;height:80px;display:block;margin-bottom:6px"><br>
    <input type="file" name="image"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_max_size",$args,$parent,$i));$buffer.=': ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_avatar_mb",$args,$parent,$i));$buffer.=' MB<br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_set_avatar",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.remove_avatar",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.remove_avatar",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="remove_avatar">
  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_remove_avatar",$args,$parent,$i));$buffer.='" class="input"></p>
</form>
<hr>
';} return $buffer;}function show_display_name6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_display_name",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_display_name",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.change_display_name",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.change_display_name",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="change_display_name">
  <p>
    ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("field_current_value",$args,$parent,$i));$buffer.=': ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display_name",$args,$parent,$i));$buffer.='<br>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_new_display_name",$args,$parent,$i));$buffer.=': <input type="text" name="display_name" class="input"></label><br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
<hr>
';} return $buffer;}function show_email8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_recovery_email",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.change_recovery_email",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.change_recovery_email",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="change_recovery_email">
  <p>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_new_email",$args,$parent,$i));$buffer.=': <input type="email" name="email" class="input"></label><br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
<hr>
';} return $buffer;}function token_unlock30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("token_unlock",$args,$parent,$i);if(!$resolved){$buffer.='<label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_old_password",$args,$parent,$i));$buffer.=': <input type="password" name="old_password" class="input"></label><br>';} return $buffer;}function is_verified38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_verified",$args,$parent,$i);if(!$resolved){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_verify_email",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.verify_email",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.verify_email",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="verify_email">
  <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_verify_desc",$args,$parent,$i));$buffer.='<br>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_verify_email",$args,$parent,$i));$buffer.='" class="input"></p>
</form>
<hr>
';} return $buffer;}function show_new_api_key44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_new_api_key",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="flash-warning">
  <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_save_now",$args,$parent,$i));$buffer.='</strong> &mdash; ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("new_api_key_label",$args,$parent,$i));$buffer.='
  <br><br>
  <code style="word-break:break-all;font-size:1.05em">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("new_api_key",$args,$parent,$i));$buffer.='</code>
  <br><br>
  <em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_save_warn",$args,$parent,$i));$buffer.='</em>
</div>
';} return $buffer;}function revoked66($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("revoked",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_revoked",$args,$parent,$i));$buffer.='</em>';} return $buffer;}function revoked68($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("revoked",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_active",$args,$parent,$i));} return $buffer;}function show_api_key_revoke72($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_api_key_revoke",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <form method="POST" style="display:inline">
          <input type="hidden" name="prg_id"  value="';$buffer.=$this->TemplateEngine->resolveValue("prg.revoke_api_key",$args,$parent,$i);$buffer.='">
          <input type="hidden" name="_csrf"   value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.revoke_api_key",$args,$parent,$i);$buffer.='">
          <input type="hidden" name="action"  value="revoke_api_key">
          <input type="hidden" name="key_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
          <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_revoke",$args,$parent,$i));$buffer.='" class="input">
        </form>
        ';} return $buffer;}function revoked70($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("revoked",$args,$parent,$i);if(!$resolved){$buffer.='
        ';$buffer.=$this->show_api_key_revoke72($args,$parent,$i);$buffer.='
        ';} return $buffer;}function api_keys58($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("api_keys",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</td>
      <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</td>
      <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("last_used_at",$args,$parent,$i));$buffer.='</td>
      <td>
        ';$buffer.=$this->revoked66($args,$parent,$i);$buffer.='
        ';$buffer.=$this->revoked68($args,$parent,$i);$buffer.='
      </td>
      <td>
        ';$buffer.=$this->revoked70($args,$parent,$i);$buffer.='
      </td>
    </tr>
    ';} return $buffer;}function has_api_keys46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_api_keys",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<table>
  <thead>
    <tr>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_label",$args,$parent,$i));$buffer.='</th>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_created",$args,$parent,$i));$buffer.='</th>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_last_used",$args,$parent,$i));$buffer.='</th>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_status",$args,$parent,$i));$buffer.='</th>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_actions",$args,$parent,$i));$buffer.='</th>
    </tr>
  </thead>
  <tbody>
    ';$buffer.=$this->api_keys58($args,$parent,$i);$buffer.='
  </tbody>
</table>
';} return $buffer;}function has_api_keys48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_api_keys",$args,$parent,$i);if(!$resolved){$buffer.='
<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_none",$args,$parent,$i));$buffer.='</em></p>
';} return $buffer;}function show_api_key_create50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_api_key_create",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.create_api_key",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.create_api_key",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="create_api_key">
  <p>
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_label",$args,$parent,$i));$buffer.=': <input type="text" name="label" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_new_label_ph",$args,$parent,$i));$buffer.='"></label>
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_create",$args,$parent,$i));$buffer.='" class="input">
  </p>
</form>
';} return $buffer;}function show_api_key_create52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_api_key_create",$args,$parent,$i);if(!$resolved){$buffer.='
<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_api_keys_no_permission",$args,$parent,$i));$buffer.='</em></p>
';} return $buffer;}function theme_default_active64($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("theme_default_active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function active72($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function themes68($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("themes",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
  <p>
    <label>
      <input type="radio" name="theme" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='" ';$buffer.=$this->active72($args,$parent,$i);$buffer.='>
      <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</strong> — <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("description",$args,$parent,$i));$buffer.='</small>
    </label>
  </p>
  ';} return $buffer;}function show_theme_picker54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_theme_picker",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_theme",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=$this->TemplateEngine->resolveValue("prg.change_theme",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=$this->TemplateEngine->resolveValue("csrf.change_theme",$args,$parent,$i);$buffer.='">
  <input type="hidden" name="action" value="change_theme">
  <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_theme_desc",$args,$parent,$i));$buffer.='</p>
  <p>
    <label>
      <input type="radio" name="theme" value="" ';$buffer.=$this->theme_default_active64($args,$parent,$i);$buffer.='>
      <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_theme_default",$args,$parent,$i));$buffer.='</strong>
    </label>
  </p>
  ';$buffer.=$this->themes68($args,$parent,$i);$buffer.='
  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_submit",$args,$parent,$i));$buffer.='" class="input"></p>
</form>
<hr>
';} return $buffer;}}