<?php class Templateadmin_admin_config_webmaila7d31f52e3390916e831201f815c0d4e{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_imap",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="imap">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_host",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="imap_host" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_imap_host",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_port",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="imap_port" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_imap_port",$args,$parent,$i));$buffer.='" min="1" max="65535" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_encryption",$args,$parent,$i));$buffer.='</th>
            <td>
                <select name="imap_encryption" class="input">
                    ';$buffer.=$this->encryption_options22($args,$parent,$i);$buffer.='
                </select>
            </td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_timeout",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="imap_timeout" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_imap_timeout",$args,$parent,$i));$buffer.='" min="5" class="input"> s</td></tr>
        <tr><th colspan="2">Tor / SOCKS5</th></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_socks5_host",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="imap_socks5_host" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_imap_socks5_host",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_socks5_port",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="imap_socks5_port" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_imap_socks5_port",$args,$parent,$i));$buffer.='" min="1" max="65535" class="input"></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_folders",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="folders">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_messages_per_page",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="messages_per_page" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_messages_per_page",$args,$parent,$i));$buffer.='" min="5" max="200" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_trash_folder",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="trash_folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_trash_folder",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sent_folder",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="sent_folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_sent_folder",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_drafts_folder",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="drafts_folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_drafts_folder",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mail_domain",$args,$parent,$i));$buffer.='</th>
            <td><input type="text" name="mail_domain" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_mail_domain",$args,$parent,$i));$buffer.='" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_login_use_full_address",$args,$parent,$i));$buffer.='</th>
            <td><label><input type="checkbox" name="imap_login_use_full_address" value="1"
                              ';$buffer.=$this->cfg_imap_login_use_full_address68($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_login_use_full_address",$args,$parent,$i));$buffer.='</label></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_verify_ssl",$args,$parent,$i));$buffer.='</th>
            <td><label><input type="checkbox" name="imap_verify_ssl" value="1"
                              ';$buffer.=$this->cfg_imap_verify_ssl74($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_imap_verify_ssl",$args,$parent,$i));$buffer.='</label></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox_is_username",$args,$parent,$i));$buffer.='</th>
            <td><label><input type="checkbox" name="mailbox_is_username" value="1"
                              ';$buffer.=$this->cfg_mailbox_is_username80($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox_is_username",$args,$parent,$i));$buffer.='</label>
                <p><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_mailbox_is_username_warning",$args,$parent,$i));$buffer.='</small></p></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>';return ($buffer) ? $buffer : "";}function selected26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function encryption_options22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("encryption_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected26($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function cfg_imap_login_use_full_address68($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_imap_login_use_full_address",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function cfg_imap_verify_ssl74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_imap_verify_ssl",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function cfg_mailbox_is_username80($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_mailbox_is_username",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}}