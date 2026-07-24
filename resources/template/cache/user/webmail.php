<?php class Templateuser_webmailbed3d86902266d41daff376f3211828c{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='

';$buffer.='
';$buffer.=$this->webmail_forbidden5($args,$parent,$i);$buffer.='
';$buffer.=$this->webmail_forbidden7($args,$parent,$i);return ($buffer) ? $buffer : "";}function webmail_forbidden5($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_forbidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<h2>403</h2><p>Access denied.</p>';} return $buffer;}function webmail_imap_error17($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_imap_error",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p class="diag-error">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_login_error",$args,$parent,$i));$buffer.='</p>';} return $buffer;}function webmail_view_login11($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_login",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_login_heading",$args,$parent,$i));$buffer.='</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_login_body",$args,$parent,$i));$buffer.='</p>
';$buffer.=$this->webmail_imap_error17($args,$parent,$i);$buffer.='
<form method="POST">
    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="action" value="imap_login">
    <table>
        <tr>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_login_password",$args,$parent,$i));$buffer.='</th>
            <td><input type="password" name="imap_password" class="input"
                       autocomplete="current-password" style="width:20em"></td>
        </tr>
        <tr>
            <td></td>
            <td><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_login_btn",$args,$parent,$i));$buffer.='" class="input"></td>
        </tr>
    </table>
</form>
';} return $buffer;}function is_current21($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_current",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='wm-cur ';} return $buffer;}function has_unseen22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_unseen",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='wm-unseen';} return $buffer;}function has_unseen27($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_unseen",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='&nbsp;(';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("unseen",$args,$parent,$i));$buffer.=')';} return $buffer;}function folders19($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("folders",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <li class="';$buffer.=$this->is_current21($args,$parent,$i);$buffer.=$this->has_unseen22($args,$parent,$i);$buffer.='">
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display",$args,$parent,$i));$buffer.=$this->has_unseen27($args,$parent,$i);$buffer.='</a>
            </li>
            ';} return $buffer;}function sort_newest49($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sort_newest",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function sort_oldest53($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sort_oldest",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function has_messages85($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_messages",$args,$parent,$i);if(!$resolved){$buffer.='<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_no_messages",$args,$parent,$i));$buffer.='</em></p>';} return $buffer;}function row_even97($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("row_even",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='wm-even ';} return $buffer;}function unread98($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("unread",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='wm-unread';} return $buffer;}function messages95($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <tr class="';$buffer.=$this->row_even97($args,$parent,$i);$buffer.=$this->unread98($args,$parent,$i);$buffer.='">
                    <td><input type="checkbox" name="uid[]" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("uid",$args,$parent,$i));$buffer.='"></td>
                    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("from_display",$args,$parent,$i));$buffer.='</td>
                    <td><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("view_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("subject",$args,$parent,$i));$buffer.='</a></td>
                    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("date_fmt",$args,$parent,$i));$buffer.='</td>
                </tr>
                ';} return $buffer;}function has_messages87($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <table id="wm-list">
                <thead>
                <tr>
                    <th style="width:1.5em">&#x2610;</th>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_from",$args,$parent,$i));$buffer.='</th>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_subject",$args,$parent,$i));$buffer.='</th>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_date",$args,$parent,$i));$buffer.='</th>
                </tr>
                </thead>
                <tbody>
                ';$buffer.=$this->messages95($args,$parent,$i);$buffer.='
                </tbody>
            </table>
            ';} return $buffer;}function move_options127($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("move_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function has_prev133($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_prev",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prev_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_prev",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function has_next149($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_next",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("next_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_next",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function webmail_view_list35($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='

        ';$buffer.='
        <div class="wm-toolbar">
            <form method="GET" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_action",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_show",$args,$parent,$i));$buffer.=': <input type="text" name="show" class="input" size="4"
                                       value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_per_page",$args,$parent,$i));$buffer.='">
                &nbsp;
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort",$args,$parent,$i));$buffer.=':
                <select name="sort" class="input">
                    <option value="newest"';$buffer.=$this->sort_newest49($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort_newest",$args,$parent,$i));$buffer.='</option>
                    <option value="oldest"';$buffer.=$this->sort_oldest53($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort_oldest",$args,$parent,$i));$buffer.='</option>
                </select>
                &nbsp;
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_go",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>

        ';$buffer.='
        <p class="wm-info">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_viewing",$args,$parent,$i));$buffer.=': <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_from",$args,$parent,$i));$buffer.='</strong>
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_to",$args,$parent,$i));$buffer.=' <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_to",$args,$parent,$i));$buffer.='</strong> (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_total",$args,$parent,$i));$buffer.=' ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_total",$args,$parent,$i));$buffer.=')</p>

        ';$buffer.='
        ';$buffer.='
        ';$buffer.='
        <form method="POST" id="wm-bulk-form">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="folder"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">

            ';$buffer.=$this->has_messages85($args,$parent,$i);$buffer.='
            ';$buffer.=$this->has_messages87($args,$parent,$i);$buffer.='

            ';$buffer.='
        </form>';$buffer.='
        <div class="wm-bulk">
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_transform",$args,$parent,$i));$buffer.=':
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="mark_seen_bulk">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_mark_read_bulk",$args,$parent,$i));$buffer.='" class="input">
            </form>
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="mark_unseen_bulk">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_mark_unread_bulk",$args,$parent,$i));$buffer.='" class="input">
            </form>
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="delete_bulk">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete_bulk",$args,$parent,$i));$buffer.='" class="input">
            </form>
            &nbsp;|&nbsp;
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_move_selected",$args,$parent,$i));$buffer.=':
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="move_bulk">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                <select name="target_folder" class="input">
                    ';$buffer.=$this->move_options127($args,$parent,$i);$buffer.='
                </select>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_move_bulk",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>

        ';$buffer.='
        <div class="wm-pager">
            ';$buffer.=$this->has_prev133($args,$parent,$i);$buffer.='
            <form method="GET" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_action",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="folder"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="show"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_per_page",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="sort"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_sort",$args,$parent,$i));$buffer.='">
                <input type="text" name="pn" class="input" size="3" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_page",$args,$parent,$i));$buffer.='">
                / ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_pages",$args,$parent,$i));$buffer.='
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_go",$args,$parent,$i));$buffer.='" class="input">
            </form>
            ';$buffer.=$this->has_next149($args,$parent,$i);$buffer.='
        </div>

        ';} return $buffer;}function message_seen73($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message.seen",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="mark_unseen">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_mark_unread",$args,$parent,$i));$buffer.='" class="input">
            </form>
            ';} return $buffer;}function message_seen75($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message.seen",$args,$parent,$i);if(!$resolved){$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="mark_seen">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_mark_read",$args,$parent,$i));$buffer.='" class="input">
            </form>
            ';} return $buffer;}function move_options87($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("move_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function images_blocked93($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("images_blocked",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <div class="wm-notice">
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_images_blocked",$args,$parent,$i));$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"       value="trust_sender">
                <input type="hidden" name="folder"       value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="sender_email" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sender_email",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_trust_sender",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>
        ';} return $buffer;}function sender_trusted95($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sender_trusted",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <div class="wm-notice">
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sender_trusted",$args,$parent,$i));$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"       value="untrust_sender">
                <input type="hidden" name="folder"       value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="sender_email" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sender_email",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_untrust_sender",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>
        ';} return $buffer;}function message_cc107($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message.cc",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<tr><th>CC:</th><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.cc",$args,$parent,$i));$buffer.='</td></tr>';} return $buffer;}function message_body_text119($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message.body_text",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <pre>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.body_text",$args,$parent,$i));$buffer.='</pre>
            ';} return $buffer;}function has_html_body123($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_html_body",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=$this->TemplateEngine->resolveValue("body_html_safe",$args,$parent,$i);} return $buffer;}function message_body_text121($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message.body_text",$args,$parent,$i);if(!$resolved){$buffer.='
            ';$buffer.=$this->has_html_body123($args,$parent,$i);$buffer.='
            ';} return $buffer;}function attachments129($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("attachments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <tr>
                <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</td>
                <td><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("content_type",$args,$parent,$i));$buffer.='</small></td>
                <td><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("size_fmt",$args,$parent,$i));$buffer.='</small></td>
                <td><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("download_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_download",$args,$parent,$i));$buffer.='</a></td>
            </tr>
            ';} return $buffer;}function has_attachments125($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_attachments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <hr>
        <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_attachments",$args,$parent,$i));$buffer.=':</strong>
        <table class="wm-att-table">
            ';$buffer.=$this->attachments129($args,$parent,$i);$buffer.='
        </table>
        ';} return $buffer;}function message_found41($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message_found",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='

        <div class="wm-msg-actions">
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("back_url",$args,$parent,$i));$buffer.='"    class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_back",$args,$parent,$i));$buffer.='</a>
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reply_url",$args,$parent,$i));$buffer.='"   class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_reply",$args,$parent,$i));$buffer.='</a>
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("forward_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_forward",$args,$parent,$i));$buffer.='</a>
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("headers_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_view_headers",$args,$parent,$i));$buffer.='</a>
            ';$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input">
            </form>
            ';$buffer.='
            ';$buffer.=$this->message_seen73($args,$parent,$i);$buffer.='
            ';$buffer.=$this->message_seen75($args,$parent,$i);$buffer.='
            ';$buffer.='
            <form method="POST">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="folder" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_folder",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="uid"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("msg_uid",$args,$parent,$i));$buffer.='">
                <select name="target_folder" class="input">
                    ';$buffer.=$this->move_options87($args,$parent,$i);$buffer.='
                </select>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_move",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>

        ';$buffer.='
        ';$buffer.=$this->images_blocked93($args,$parent,$i);$buffer.='
        ';$buffer.=$this->sender_trusted95($args,$parent,$i);$buffer.='

        ';$buffer.='
        <table id="wm-msg-hdr">
            <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_from",$args,$parent,$i));$buffer.=':</th><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.from",$args,$parent,$i));$buffer.='</td></tr>
            <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_to",$args,$parent,$i));$buffer.=':</th>  <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.to",$args,$parent,$i));$buffer.='</td></tr>
            ';$buffer.=$this->message_cc107($args,$parent,$i);$buffer.='
            <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_subject",$args,$parent,$i));$buffer.=':</th><td><strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.subject",$args,$parent,$i));$buffer.='</strong></td></tr>
            <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_date",$args,$parent,$i));$buffer.=':</th>   <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message.date",$args,$parent,$i));$buffer.='</td></tr>
        </table>
        <hr>

        ';$buffer.='
        <div class="wm-msg-body">
            ';$buffer.=$this->message_body_text119($args,$parent,$i);$buffer.='
            ';$buffer.=$this->message_body_text121($args,$parent,$i);$buffer.='
        </div>

        ';$buffer.='
        ';$buffer.=$this->has_attachments125($args,$parent,$i);$buffer.='

        ';} return $buffer;}function message_found43($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("message_found",$args,$parent,$i);if(!$resolved){$buffer.='
        <p><em>Message not found.</em></p>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("back_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_back",$args,$parent,$i));$buffer.='</a>
        ';} return $buffer;}function webmail_view_message39($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_message",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        ';$buffer.=$this->message_found41($args,$parent,$i);$buffer.='
        ';$buffer.=$this->message_found43($args,$parent,$i);$buffer.='
        ';} return $buffer;}function webmail_view_headers43($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_headers",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <p>
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("back_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_back",$args,$parent,$i));$buffer.='</a>
        </p>
        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_raw_headers_heading",$args,$parent,$i));$buffer.='</h3>
        <pre class="wm-raw-headers">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("raw_headers",$args,$parent,$i));$buffer.='</pre>
        ';} return $buffer;}function webmail_view_compose47($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_compose",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_heading",$args,$parent,$i));$buffer.='</h3>
        <form method="POST" id="wm-compose">
            <input type="hidden" name="prg_id"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"       value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="folder"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("current_folder",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="in_reply_to" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_in_reply_to",$args,$parent,$i));$buffer.='">

            <p style="margin:0 0 5px">
                <input type="hidden" name="action" value="send">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_send",$args,$parent,$i));$buffer.='" class="input">
                <button type="submit" name="action" value="save_draft" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_save_draft",$args,$parent,$i));$buffer.='</button>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("back_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_cancel",$args,$parent,$i));$buffer.='</a>
            </p>

            <table id="wm-compose-hdr">
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_from",$args,$parent,$i));$buffer.='</th>
                    <td><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_mailbox",$args,$parent,$i));$buffer.='</em></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_to",$args,$parent,$i));$buffer.='</th>
                    <td><input type="email" name="to" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_to",$args,$parent,$i));$buffer.='"></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_cc",$args,$parent,$i));$buffer.='</th>
                    <td><input type="text" name="cc" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_cc",$args,$parent,$i));$buffer.='" placeholder="optional"></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_bcc",$args,$parent,$i));$buffer.='</th>
                    <td><input type="text" name="bcc" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_bcc",$args,$parent,$i));$buffer.='" placeholder="optional"></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_reply_to_field",$args,$parent,$i));$buffer.='</th>
                    <td><input type="email" name="reply_to" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_reply_to",$args,$parent,$i));$buffer.='" placeholder="optional"></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_subject",$args,$parent,$i));$buffer.='</th>
                    <td><input type="text" name="subject" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_subject",$args,$parent,$i));$buffer.='"></td>
                </tr>
                <tr>
                    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_priority",$args,$parent,$i));$buffer.='</th>
                    <td>
                        <select name="priority" class="input">
                            <option value="high">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_priority_high",$args,$parent,$i));$buffer.='</option>
                            <option value="normal" selected>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_priority_normal",$args,$parent,$i));$buffer.='</option>
                            <option value="low">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_priority_low",$args,$parent,$i));$buffer.='</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td><label><input type="checkbox" name="read_receipt" value="1"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_read_receipt",$args,$parent,$i));$buffer.='</label></td>
                </tr>
            </table>

            <textarea name="body" class="input"
                      style="width:100%;box-sizing:border-box;height:18em;margin-top:4px;resize:vertical"
            >';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_body",$args,$parent,$i));$buffer.='</textarea>

            <p style="margin:4px 0 0">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_send",$args,$parent,$i));$buffer.='" class="input">
                <button type="submit" name="action" value="save_draft" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_save_draft",$args,$parent,$i));$buffer.='</button>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("back_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("webmail_compose_cancel",$args,$parent,$i));$buffer.='</a>
            </p>
        </form>
        ';} return $buffer;}function webmail_view_login15($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_view_login",$args,$parent,$i);if(!$resolved){$buffer.='
<div id="wm-wrap">

    ';$buffer.='
    <div id="wm-sidebar">
        <ul id="wm-nav">
            ';$buffer.=$this->folders19($args,$parent,$i);$buffer.='
        </ul>
        <div id="wm-sidebar-btns">
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("compose_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_compose",$args,$parent,$i));$buffer.='</a>
            <form method="POST">
                <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action" value="disconnect">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_disconnect",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </div>
    </div>

    ';$buffer.='
    <div id="wm-content">

        ';$buffer.='
        ';$buffer.=$this->webmail_view_list35($args,$parent,$i);$buffer.='

        ';$buffer.='
        ';$buffer.=$this->webmail_view_message39($args,$parent,$i);$buffer.='

        ';$buffer.='
        ';$buffer.=$this->webmail_view_headers43($args,$parent,$i);$buffer.='

        ';$buffer.='
        ';$buffer.=$this->webmail_view_compose47($args,$parent,$i);$buffer.='

    </div>';$buffer.='
</div>';$buffer.='
';} return $buffer;}function webmail_forbidden7($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("webmail_forbidden",$args,$parent,$i);if(!$resolved){$buffer.='

';$buffer.='
';$buffer.=$this->webmail_view_login11($args,$parent,$i);$buffer.='

';$buffer.='
';$buffer.=$this->webmail_view_login15($args,$parent,$i);$buffer.='

';} return $buffer;}}