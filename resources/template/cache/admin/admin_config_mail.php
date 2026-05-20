<?php class Templateadmin_admin_config_mailae07c2ab385d230555ca7aff3b8a85d8{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_mailer",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="mailer">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_host",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="host" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_host",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_port",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="port" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_port",$args,$parent,$i));$buffer.='" min="1" max="65535" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_encryption",$args,$parent,$i));$buffer.='</th><td>
            <select name="encryption" class="input" >
                ';$buffer.=$this->encryption_options22($args,$parent,$i);$buffer.='
            </select>
        </td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_username",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="username" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_username",$args,$parent,$i));$buffer.='" autocomplete="off" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password",$args,$parent,$i));$buffer.='</th>
            <td>
                ';$buffer.=$this->cfg_password_set30($args,$parent,$i);$buffer.='
                ';$buffer.=$this->cfg_password_set32($args,$parent,$i);$buffer.='
                <br><input type="password" name="password" placeholder="leave blank to keep current" autocomplete="new-password" class="input">
            </td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_from_address",$args,$parent,$i));$buffer.='</th>
            <td><input type="email" name="from_address" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_from_address",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_from_name",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="from_name" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_from_name",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_timeout",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="timeout" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_timeout",$args,$parent,$i));$buffer.='" min="5" class="input"> s</td></tr>

        <tr><th colspan="2">Tor / SOCKS5</th></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_socks5_host",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="socks5_host" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_socks5_host",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_socks5_port",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="socks5_port" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_socks5_port",$args,$parent,$i));$buffer.='" min="1" max="65535" class="input"></td></tr>

        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_mailbox",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="mailbox">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox_domain",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="mailbox_domain" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_mailbox_domain",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailapi_url",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="mailapi_url" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_mailapi_url",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailapi_secret",$args,$parent,$i));$buffer.='</th>
            <td>
                ';$buffer.=$this->cfg_mailapi_secret_set74($args,$parent,$i);$buffer.='
                ';$buffer.=$this->cfg_mailapi_secret_set76($args,$parent,$i);$buffer.='
                <br><input type="password" name="mailapi_secret" placeholder="leave blank to keep current" autocomplete="new-password" class="input">
            </td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_test",$args,$parent,$i));$buffer.='</h3>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_test_recipient",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="test_recipient">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_test_recipient",$args,$parent,$i));$buffer.='</th>
            <td><input type="email" name="test_recipient" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_test_recipient",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save_recipient",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>

<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="test">
    <input type="hidden" name="test_recipient" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_test_recipient",$args,$parent,$i));$buffer.='">
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_send_test",$args,$parent,$i));$buffer.='" class="input">
</form>';return ($buffer) ? $buffer : "";}function selected26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function encryption_options22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("encryption_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected26($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function cfg_password_set30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_password_set",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password_set",$args,$parent,$i));$buffer.='</em>';} return $buffer;}function cfg_password_set32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_password_set",$args,$parent,$i);if(!$resolved){$buffer.='<em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_password_not_set",$args,$parent,$i));$buffer.='</em>';} return $buffer;}function cfg_mailapi_secret_set74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_mailapi_secret_set",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_secret_set",$args,$parent,$i));$buffer.='</em>';} return $buffer;}function cfg_mailapi_secret_set76($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_mailapi_secret_set",$args,$parent,$i);if(!$resolved){$buffer.='<em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_secret_not_set",$args,$parent,$i));$buffer.='</em>';} return $buffer;}}