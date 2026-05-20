<?php class Templateadmin_admin_commentsd8c027a01edc1700f2eb3719731e0df0{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_comments_heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
';$buffer.=$this->can_moderate6($args,$parent,$i);$buffer.='

';$buffer.='
';$buffer.=$this->can_config10($args,$parent,$i);return ($buffer) ? $buffer : "";}function filter_flagged12($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("filter_flagged",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function filter_show_hidden18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("filter_show_hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' ';} return $buffer;}function filter_show_hidden22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("filter_show_hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' ';} return $buffer;}function hidden52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' style="opacity:0.5"';} return $buffer;}function hidden68($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function flagged70($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("flagged",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function hidden84($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(!$resolved){$buffer.='<input type="hidden" name="action" value="hide">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_hide",$args,$parent,$i));$buffer.='" class="input">';} return $buffer;}function hidden86($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<input type="hidden" name="action" value="unhide">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_unhide",$args,$parent,$i));$buffer.='" class="input">';} return $buffer;}function flagged94($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("flagged",$args,$parent,$i);if(!$resolved){$buffer.='<input type="hidden" name="action" value="flag">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_flag",$args,$parent,$i));$buffer.='" class="input">';} return $buffer;}function flagged96($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("flagged",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<input type="hidden" name="action" value="unflag">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_unflag",$args,$parent,$i));$buffer.='" class="input">';} return $buffer;}function editing50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
    <tr';$buffer.=$this->hidden52($args,$parent,$i);$buffer.='>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("page_id",$args,$parent,$i));$buffer.='</td>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_id",$args,$parent,$i));$buffer.='</code></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reply_to",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("content",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</td>
    <td><input type="checkbox" disabled ';$buffer.=$this->hidden68($args,$parent,$i);$buffer.='></td>
    <td><input type="checkbox" disabled ';$buffer.=$this->flagged70($args,$parent,$i);$buffer.='></td>
    <td>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?edit=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="moderation">
            <input type="hidden" name="id"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            ';$buffer.=$this->hidden84($args,$parent,$i);$buffer.='
            ';$buffer.=$this->hidden86($args,$parent,$i);$buffer.='
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="moderation">
            <input type="hidden" name="id"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            ';$buffer.=$this->flagged94($args,$parent,$i);$buffer.='
            ';$buffer.=$this->flagged96($args,$parent,$i);$buffer.='
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="moderation">
            <input type="hidden" name="id"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"    value="delete">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input">
        </form>
    </td>
    </tr>
    ';} return $buffer;}function hidden80($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='  checked';} return $buffer;}function flagged82($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("flagged",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="section"   value="moderation">
            <input type="hidden" name="action"    value="update">
            <input type="hidden" name="id"        value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("page_id",$args,$parent,$i));$buffer.='</td>
            <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_id",$args,$parent,$i));$buffer.='</code></td>
            <td>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_name",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="name" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='"><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_email",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="email" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("email",$args,$parent,$i));$buffer.='">
            </td>
            <td><input type="number" name="reply_to" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reply_to",$args,$parent,$i));$buffer.='" style="width:5em" min="0" class="input"></td>
            <td><textarea name="content" rows="5" class="input" style="width:100%">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("content",$args,$parent,$i));$buffer.='</textarea></td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</td>
            <td><input type="checkbox" name="hidden"  value="1"';$buffer.=$this->hidden80($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="flagged" value="1"';$buffer.=$this->flagged82($args,$parent,$i);$buffer.='></td>
            <td>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input"><br>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
            </td>
        </form>
    </tr>
    ';} return $buffer;}function comment_list48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comment_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    ';$buffer.=$this->editing50($args,$parent,$i);$buffer.='

    ';$buffer.=$this->editing52($args,$parent,$i);$buffer.='
    ';} return $buffer;}function can_moderate6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_moderate",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<form method="GET">
    <p>
        <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_filter",$args,$parent,$i));$buffer.=' page ID:
            <input type="number" name="page_id" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_page_id",$args,$parent,$i));$buffer.='" style="width:5em">
        </label>
        <label><input type="checkbox" name="flagged" value="1"';$buffer.=$this->filter_flagged12($args,$parent,$i);$buffer.='> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_flagged",$args,$parent,$i));$buffer.=' only</label>
        <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_show_hidden",$args,$parent,$i));$buffer.=':
            <select name="show_hidden" class="input">
                <option value="">All</option>
                <option value="0"';$buffer.=$this->filter_show_hidden18($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_visible_only",$args,$parent,$i));$buffer.='</option>
                <option value="1"';$buffer.=$this->filter_show_hidden22($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hidden_only",$args,$parent,$i));$buffer.='</option>
            </select>
        </label>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_filter",$args,$parent,$i));$buffer.='" class="input">
    </p>
</form>

<table>
    <thead><tr>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_id",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_page",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_user",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_name",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_reply_to",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_content",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_date",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hidden",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_flagged",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
    </tr></thead>
    <tbody>

    ';$buffer.=$this->comment_list48($args,$parent,$i);$buffer.='

    </tbody>
</table>
';} return $buffer;}function cfg_allow_replies26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_allow_replies",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_require_email30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_require_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function enabled62($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function antispam_list56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("antispam_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <td><input type="number" name="regex_key[]"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='" min="1" style="width:4em" class="input"></td>
            <td><input type="text"   name="regex_pattern[]" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("regex",$args,$parent,$i));$buffer.='" style="width:24em" class="input"></td>
            <td style="text-align:center"><input type="checkbox" name="regex_enabled[]" value="1"';$buffer.=$this->enabled62($args,$parent,$i);$buffer.='></td>
            <td><input type="text"   name="regex_message[]" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='" style="width:18em" class="input"></td>
        </tr>
        ';} return $buffer;}function has_antispam58($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_antispam",$args,$parent,$i);if(!$resolved){$buffer.='
        <tr><td colspan="4"><em>No antispam rules defined.</em></td></tr>
        ';} return $buffer;}function can_config10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_config",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<hr>
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_config_heading",$args,$parent,$i));$buffer.='</h3>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_general",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="general">
    <table>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_comments_per_page",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="comments_per_page" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_comments_per_page",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_replies",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="allow_replies" value="1"';$buffer.=$this->cfg_allow_replies26($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_require_email_comment",$args,$parent,$i));$buffer.='</th>
            <td><input type="checkbox" name="require_email" value="1"';$buffer.=$this->cfg_require_email30($args,$parent,$i);$buffer.='></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_minimum_flood_secs",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="minimum_flood_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_minimum_flood_secs",$args,$parent,$i));$buffer.='" min="0" class="input"></td></tr>
        <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_antispam_time_secs",$args,$parent,$i));$buffer.='</th>
            <td><input type="number" name="antispam_time_secs" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_antispam_time_secs",$args,$parent,$i));$buffer.='" min="0" class="input"></td></tr>
        <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    </table>
</form>

<h4>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_antispam",$args,$parent,$i));$buffer.='</h4>
<form method="POST">
    <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="section" value="antispam">
    <table>
        <thead><tr>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_key",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_pattern",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_enabled",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_regex_message",$args,$parent,$i));$buffer.='</th>
        </tr></thead>
        <tbody>
        ';$buffer.=$this->antispam_list56($args,$parent,$i);$buffer.='
        ';$buffer.=$this->has_antispam58($args,$parent,$i);$buffer.='
        </tbody>
    </table>
    <p>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input">
    </p>
</form>
';} return $buffer;}}