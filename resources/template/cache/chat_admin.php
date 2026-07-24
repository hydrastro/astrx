<?php class Templatechat_admineaa423dc8e319366fd0960920a849832{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->chat_admin_forbidden1($args,$parent,$i);$buffer.='

';$buffer.=$this->chat_admin_forbidden3($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function chat_admin_forbidden1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_admin_forbidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="error-page"><h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_forbidden_msg",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2></div>
';} return $buffer;}function sessions33($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sessions",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <tr>
                <td><input type="checkbox" name="idents[]" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("ident",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"></td>
                <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("role",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("ip",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("status",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("joined",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td>
            </tr>
            ';} return $buffer;}function has_sessions13($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_sessions",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <form method="POST">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="kick">
        <table>
            <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_select",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_type",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_role",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_ip",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_status",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("col_joined",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th></tr>
            ';$buffer.=$this->sessions33($args,$parent,$i);$buffer.='
        </table>
        <p class="chat-admin-row">
            <label for="chat_kick_msg">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_kick_msg",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <input type="text" name="message" id="chat_kick_msg" class="input" maxlength="128" autocomplete="off" style="width:18em">
        </p>
        <p class="chat-admin-row">
            <label><input type="checkbox" name="all_guests" value="1"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_all_guests",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            &nbsp; <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_kick_sel",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </p>
    </form>
    ';} return $buffer;}function has_sessions15($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_sessions",$args,$parent,$i);if(!$resolved){$buffer.='<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_sessions_none",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>';} return $buffer;}function has_link45($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_link",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <form method="POST" class="chat-inline">
                    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="hidden" name="action" value="report_block">
                    <input type="hidden" name="id"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_block",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                </form>
                ';} return $buffer;}function reports37($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("reports",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("preview",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("count",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</td>
            <td>
                ';$buffer.=$this->has_link45($args,$parent,$i);$buffer.='
                <form method="POST" class="chat-inline">
                    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="hidden" name="action" value="report_dismiss">
                    <input type="hidden" name="id"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                    <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_dismiss",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
                </form>
            </td>
        </tr>
        ';} return $buffer;}function has_reports29($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_reports",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_col_msg",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_col_by",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_col_count",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</th><th></th></tr>
        ';$buffer.=$this->reports37($args,$parent,$i);$buffer.='
    </table>
    ';} return $buffer;}function has_reports31($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_reports",$args,$parent,$i);if(!$resolved){$buffer.='<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reports_none",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>';} return $buffer;}function chat_admin_forbidden3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_admin_forbidden",$args,$parent,$i);if(!$resolved){$buffer.='
<div id="chat-admin">
    <style>
    #chat-admin h3 { margin: 1em 0 .3em; padding-bottom: .15em; border-bottom: 1px solid rgba(128,128,128,.3); }
    #chat-admin table { border-collapse: collapse; margin: .3em 0; }
    #chat-admin th, #chat-admin td { padding: .15em .55em; text-align: left; border-bottom: 1px solid rgba(128,128,128,.2); }
    #chat-admin form.chat-inline { display: inline-block; margin: 0 .5em .35em 0; vertical-align: top; }
    #chat-admin .chat-admin-row { margin: .45em 0; }
    </style>
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>

    ';$buffer.='
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_sessions_h",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=' (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("session_count",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=')</h3>
    ';$buffer.=$this->has_sessions13($args,$parent,$i);$buffer.='
    ';$buffer.=$this->has_sessions15($args,$parent,$i);$buffer.='

    <form method="POST" class="chat-inline">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="logout_inactive">
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_logout",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>

    ';$buffer.='
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reports_h",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=' (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("report_count",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=')</h3>
    ';$buffer.=$this->has_reports29($args,$parent,$i);$buffer.='
    ';$buffer.=$this->has_reports31($args,$parent,$i);$buffer.='

    ';$buffer.='
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_clean_h",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <form method="POST" class="chat-inline">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="clean">
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_clean_room",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>
    <form method="POST" class="chat-inline">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="clean_nick">
        <input type="text" name="nick" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_clean_nick_ph",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="64" autocomplete="off">
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_clean_nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>

    ';$buffer.='
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_topic_h",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <form method="POST" class="chat-inline">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="topic">
        <input type="text" name="topic" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("room_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_topic_ph",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="255" autocomplete="off" style="width:22em">
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_set_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>

    ';$buffer.='
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_broadcast_h",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <form method="POST">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="broadcast">
        <input type="text" name="message" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_broadcast_ph",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="2000" autocomplete="off" style="width:28em">
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_broadcast",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>

    <p><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("config_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_admin_config_link",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a></p>
</div>
';} return $buffer;}}