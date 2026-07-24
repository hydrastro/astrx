<?php class Templateadmin_admin_userse469c4c6754196fa98ed10e068178db6{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_users_heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
';$buffer.=$this->can_manage6($args,$parent,$i);$buffer.='

';$buffer.='
';$buffer.=$this->can_config10($args,$parent,$i);return ($buffer) ? $buffer : "";}function deleted28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("deleted",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' style="opacity:0.4"';} return $buffer;}function verified38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("verified",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function deleted40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("deleted",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function editing26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
    <tr';$buffer.=$this->deleted28($args,$parent,$i);$buffer.='>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</code></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("mailbox",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type",$args,$parent,$i));$buffer.='</td>
    <td><input type="checkbox" disabled ';$buffer.=$this->verified38($args,$parent,$i);$buffer.='></td>
    <td><input type="checkbox" disabled ';$buffer.=$this->deleted40($args,$parent,$i);$buffer.='></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</td>
    <td>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?edit=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="manage">
            <input type="hidden" name="action"    value="delete">
            <input type="hidden" name="user_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <select name="deletion_mode" class="input" title="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_help",$args,$parent,$i));$buffer.='">
                <option value="soft_redact">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_soft_redact",$args,$parent,$i));$buffer.='</option>
                <option value="hard_redact">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_hard_redact",$args,$parent,$i));$buffer.='</option>
                <option value="keep_visible">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_keep_visible",$args,$parent,$i));$buffer.='</option>
                <option value="keep_suspended">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_keep_suspended",$args,$parent,$i));$buffer.='</option>
                <option value="full_delete">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_full_delete",$args,$parent,$i));$buffer.='</option>
            </select>
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input">
        </form>
    </td>
    </tr>
    ';} return $buffer;}function selected58($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function type_options54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("type_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected58($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function verified60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("verified",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function deleted64($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("deleted",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='  checked';} return $buffer;}function token_used94($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("token_used",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="manage">
            <input type="hidden" name="action"    value="update">
            <input type="hidden" name="user_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">

            <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</code></td>
            <td>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_username",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="username" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='"><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_display_name",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="display_name" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display_name",$args,$parent,$i));$buffer.='">
            </td>
            <td>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="mailbox" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("mailbox",$args,$parent,$i));$buffer.='"><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_email",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="email" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("email",$args,$parent,$i));$buffer.='">
            </td>
            <td>
                <select name="type" class="input">
                    ';$buffer.=$this->type_options54($args,$parent,$i);$buffer.='
                </select><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_birth",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="birth" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("birth",$args,$parent,$i));$buffer.='" placeholder="YYYY-MM-DD">
            </td>
            <td>
                <label><input type="checkbox" name="verified" value="1"';$buffer.=$this->verified60($args,$parent,$i);$buffer.='> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_verified",$args,$parent,$i));$buffer.='</label><br>
                <label><input type="checkbox" name="deleted"  value="1"';$buffer.=$this->deleted64($args,$parent,$i);$buffer.='>  ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_deleted",$args,$parent,$i));$buffer.='</label><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_login_attempts",$args,$parent,$i));$buffer.='</small><br>
                <input type="number" name="login_attempts" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_attempts",$args,$parent,$i));$buffer.='" style="width:5em" min="0">
            </td>
            <td>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_created_at",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="created_at" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='" placeholder="YYYY-MM-DD HH:MM:SS"><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_last_access",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="last_access" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("last_access",$args,$parent,$i));$buffer.='" placeholder="YYYY-MM-DD HH:MM:SS">
            </td>
            <td colspan="2">
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password",$args,$parent,$i));$buffer.=' — ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password_hint",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="password" class="input" value="" placeholder="argon2id hash or plain text"><br>
                <label><input type="checkbox" name="hash_password" value="1"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hash_password",$args,$parent,$i));$buffer.='</label>
                <fieldset style="margin-top:8px;font-size:0.85em">
                    <legend>Token</legend>
                    <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_token_hash",$args,$parent,$i));$buffer.='</small><br>
                    <input type="text" name="token_hash" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("token_hash",$args,$parent,$i));$buffer.='" style="width:100%"><br>
                    <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_token_type",$args,$parent,$i));$buffer.=': </small>
                    <input type="number" name="token_type" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("token_type",$args,$parent,$i));$buffer.='" style="width:4em">
                    &nbsp;
                    <label><input type="checkbox" name="token_used" value="1"';$buffer.=$this->token_used94($args,$parent,$i);$buffer.='> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_token_used",$args,$parent,$i));$buffer.='</label><br>
                    <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_token_expires",$args,$parent,$i));$buffer.=': </small>
                    <input type="text" name="token_expires_at" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("token_expires_at",$args,$parent,$i));$buffer.='" placeholder="YYYY-MM-DD HH:MM:SS">
                </fieldset>
                <p>
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input">
                    <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
                </p>
            </td>
        </form>
    </tr>
    ';} return $buffer;}function user_list24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    ';$buffer.=$this->editing26($args,$parent,$i);$buffer.='

    ';$buffer.=$this->editing28($args,$parent,$i);$buffer.='
    ';} return $buffer;}function can_manage6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_manage",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<table>
    <thead><tr>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_id",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_username",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_type",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_verified",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_deleted",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_created_at",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
    </tr></thead>
    <tbody>

    ';$buffer.=$this->user_list24($args,$parent,$i);$buffer.='

    </tbody>
</table>

<details style="margin-top:1em">
<summary><strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_legend_heading",$args,$parent,$i));$buffer.='</strong></summary>
<dl>
    <dt><code>soft_redact</code></dt>
    <dd>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_soft_redact_desc",$args,$parent,$i));$buffer.='</dd>
    <dt><code>hard_redact</code></dt>
    <dd>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_hard_redact_desc",$args,$parent,$i));$buffer.='</dd>
    <dt><code>keep_visible</code></dt>
    <dd>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_keep_visible_desc",$args,$parent,$i));$buffer.='</dd>
    <dt><code>keep_suspended</code></dt>
    <dd>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_keep_suspended_desc",$args,$parent,$i));$buffer.='</dd>
    <dt><code>full_delete</code></dt>
    <dd>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("deletion_mode_full_delete_desc",$args,$parent,$i));$buffer.='</dd>
</dl>
</details>
';} return $buffer;}function cfg_allow_register26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_allow_register",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_allow_login_non_verified_users30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_allow_login_non_verified_users",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_email34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_recovery_email38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_recovery_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_send_verification_email44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_send_verification_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_send_password_reset_email48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_send_password_reset_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_display_name60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_display_name",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_birth_date64($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_birth_date",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_case_sensitive_usernames68($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_case_sensitive_usernames",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function selected84($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function login_captcha_options80($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("login_captcha_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected84($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected92($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function register_captcha_options88($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("register_captcha_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected92($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected96($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function recover_captcha_options92($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("recover_captcha_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected96($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function checking_for116($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("checking_for",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function enabled118($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function username_regex_list110($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("username_regex_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <td><input type="number" name="username_regex_key[]"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='"  min="1" style="width:4em" class="input"></td>
            <td><input type="text"   name="username_regex_pattern[]"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("regex",$args,$parent,$i));$buffer.='" style="width:20em" class="input"></td>
            <td style="text-align:center"><input type="checkbox" name="username_regex_checking_for[]" value="1"';$buffer.=$this->checking_for116($args,$parent,$i);$buffer.='></td>
            <td style="text-align:center"><input type="checkbox" name="username_regex_enabled[]"      value="1"';$buffer.=$this->enabled118($args,$parent,$i);$buffer.='></td>
            <td><input type="text"   name="username_regex_message[]"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='" style="width:18em" class="input"></td>
        </tr>
        ';} return $buffer;}function has_username_regex112($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_username_regex",$args,$parent,$i);if(!$resolved){$buffer.='<tr><td colspan="5"><em>No rules.</em></td></tr>';} return $buffer;}function checking_for132($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("checking_for",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function enabled134($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function password_regex_list126($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("password_regex_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <td><input type="number" name="password_regex_key[]"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='"  min="1" style="width:4em" class="input"></td>
            <td><input type="text"   name="password_regex_pattern[]"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("regex",$args,$parent,$i));$buffer.='" style="width:20em" class="input"></td>
            <td style="text-align:center"><input type="checkbox" name="password_regex_checking_for[]" value="1"';$buffer.=$this->checking_for132($args,$parent,$i);$buffer.='></td>
            <td style="text-align:center"><input type="checkbox" name="password_regex_enabled[]"      value="1"';$buffer.=$this->enabled134($args,$parent,$i);$buffer.='></td>
            <td><input type="text"   name="password_regex_message[]"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='" style="width:18em" class="input"></td>
        </tr>
        ';} return $buffer;}function has_password_regex128($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_password_regex",$args,$parent,$i);if(!$resolved){$buffer.='<tr><td colspan="5"><em>No rules.</em></td></tr>';} return $buffer;}function cfg_use_identicons148($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_use_identicons",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_identicon_high_quality172($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_identicon_high_quality",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function can_config10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_config",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<hr>
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_config_heading",$args,$parent,$i));$buffer.='</h3>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_userservice",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="userservice">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_token_expiration_time",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="token_expiration_time" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_token_expiration_time",$args,$parent,$i));$buffer.='" min="60" class="input"> s</td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_register",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="allow_register" value="1"';$buffer.=$this->cfg_allow_register26($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_login_non_verified_users",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="allow_login_non_verified_users" value="1"';$buffer.=$this->cfg_allow_login_non_verified_users30($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_email",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="require_email" value="1"';$buffer.=$this->cfg_require_email34($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_recovery_email",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="require_recovery_email" value="1"';$buffer.=$this->cfg_require_recovery_email38($args,$parent,$i);$buffer.='></td></tr>

        <tr><th colspan="2" style="text-align:left;padding-top:18px"><strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_email_settings",$args,$parent,$i));$buffer.='</strong></th></tr>

        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_send_verification_email",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="send_verification_email" value="1"';$buffer.=$this->cfg_send_verification_email44($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_send_password_reset_email",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="send_password_reset_email" value="1"';$buffer.=$this->cfg_send_password_reset_email48($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_site_url",$args,$parent,$i));$buffer.='</th>
            <td><input type="url" name="site_url" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_site_url",$args,$parent,$i));$buffer.='" class="input" style="width:100%" placeholder="https://example.com"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_site_name",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="site_name" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_site_name",$args,$parent,$i));$buffer.='" class="input" style="width:100%"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_display_name",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="require_display_name" value="1"';$buffer.=$this->cfg_require_display_name60($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_birth_date",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="require_birth_date" value="1"';$buffer.=$this->cfg_require_birth_date64($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_case_sensitive_usernames",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="case_sensitive_usernames" value="1"';$buffer.=$this->cfg_case_sensitive_usernames68($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_minimum_age",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="minimum_age" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_minimum_age",$args,$parent,$i));$buffer.='" min="0" class="input"> s</td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_maximum_age",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="maximum_age" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_maximum_age",$args,$parent,$i));$buffer.='" min="0" class="input"> s</td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_login_captcha_type",$args,$parent,$i));$buffer.='</th><td>
            <select name="login_captcha_type" class="input">
                ';$buffer.=$this->login_captcha_options80($args,$parent,$i);$buffer.='
            </select></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_login_captcha_attempts",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="login_captcha_attempts" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_login_captcha_attempts",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_register_captcha_type",$args,$parent,$i));$buffer.='</th><td>
            <select name="register_captcha_type" class="input">
                ';$buffer.=$this->register_captcha_options88($args,$parent,$i);$buffer.='
            </select></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_recover_captcha_type",$args,$parent,$i));$buffer.='</th><td>
            <select name="recover_captcha_type" class="input">
                ';$buffer.=$this->recover_captcha_options92($args,$parent,$i);$buffer.='
            </select></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_remember_me_time",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="remember_me_time" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_remember_me_time",$args,$parent,$i));$buffer.='" min="0" class="input"> s</td></tr>
    </table>

    <h5>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_username_regex",$args,$parent,$i));$buffer.='</h5>
    <table>
        <thead><tr>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_key",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_pattern",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_checking_for",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_enabled",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_message",$args,$parent,$i));$buffer.='</th>
        </tr></thead>
        <tbody>
        ';$buffer.=$this->username_regex_list110($args,$parent,$i);$buffer.='
        ';$buffer.=$this->has_username_regex112($args,$parent,$i);$buffer.='
        </tbody>
    </table>

    <h5>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password_regex",$args,$parent,$i));$buffer.='</h5>
    <table>
        <thead><tr>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_key",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_pattern",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_checking_for",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_enabled",$args,$parent,$i));$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_message",$args,$parent,$i));$buffer.='</th>
        </tr></thead>
        <tbody>
        ';$buffer.=$this->password_regex_list126($args,$parent,$i);$buffer.='
        ';$buffer.=$this->has_password_regex128($args,$parent,$i);$buffer.='
        </tbody>
    </table>

    <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></p>
</form>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_avatar",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="avatar">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_avatar_dir",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="avatar_dir" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_avatar_dir",$args,$parent,$i));$buffer.='" style="width:30em" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_avatar_file_size",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="avatar_file_size" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_avatar_file_size",$args,$parent,$i));$buffer.='" min="1024" class="input"> bytes</td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_use_identicons",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="use_identicons" value="1"';$buffer.=$this->cfg_use_identicons148($args,$parent,$i);$buffer.='></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_identicon",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="identicon">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_identicon_size",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="size" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_identicon_size",$args,$parent,$i));$buffer.='" min="16" class="input"> px</td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_identicon_tiles",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="tiles" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_identicon_tiles",$args,$parent,$i));$buffer.='" min="2" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_identicon_colors",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="colors" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_identicon_colors",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_identicon_high_quality",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="high_quality" value="1"';$buffer.=$this->cfg_identicon_high_quality172($args,$parent,$i);$buffer.='></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>
';} return $buffer;}}