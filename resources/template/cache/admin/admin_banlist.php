<?php class Templateadmin_admin_banlist167229204b96c5356806c70720f073ca{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_banlist_heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<table>
    <thead><tr>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_id",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_type",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_value",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_reason",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_route",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_end",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_active",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
    </tr></thead>
    <tbody>

    ';$buffer.=$this->ban_list22($args,$parent,$i);$buffer.='

    ';$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action" value="ban">
            <td><em>new</em></td>
            <td>
                <select name="type" class="input">
                    <option value="ip">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_ip",$args,$parent,$i));$buffer.='</option>
                    <option value="email">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_email",$args,$parent,$i));$buffer.='</option>
                    <option value="user">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_user",$args,$parent,$i));$buffer.='</option>
                </select>
            </td>
            <td>
                <input type="text" name="value" class="input" placeholder="IP / CIDR / email / user id"><br>
                <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_ip_hint",$args,$parent,$i));$buffer.='</small>
            </td>
            <td><input type="text" name="reason" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_reason",$args,$parent,$i));$buffer.='"></td>
            <td>
                <select name="route" class="input">
                    ';$buffer.=$this->add_ban_routes40($args,$parent,$i);$buffer.='
                </select>
            </td>
            <td><input type="text" name="end" class="input" placeholder="YYYY-MM-DD HH:MM:SS"></td>
            <td><input type="checkbox" name="active" value="1" checked></td>
            <td><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_ban",$args,$parent,$i));$buffer.='" class="input"></td>
        </form>
    </tr>

    </tbody>
</table>
<hr>

';$buffer.='
<h3>Routes</h3>

';$buffer.=$this->ban_routes46($args,$parent,$i);$buffer.='
';$buffer.=$this->has_routes48($args,$parent,$i);$buffer.='

';$buffer.='
<form method="POST">
    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="action" value="add_route">
    <p>
        <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_new_route",$args,$parent,$i));$buffer.=':
            <input type="text" name="route_key" class="input" placeholder="e.g. spam_link">
        </label>
        <input type="submit" value="+ ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add",$args,$parent,$i));$buffer.=' route" class="input">
    </p>
</form>';return ($buffer) ? $buffer : "";}function active26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(!$resolved){$buffer.=' style="opacity:0.5"';} return $buffer;}function active40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function active54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <input type="hidden" name="action" value="deactivate">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_deactivate",$args,$parent,$i));$buffer.='" class="input">
            ';} return $buffer;}function active56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(!$resolved){$buffer.='
            <input type="hidden" name="action" value="activate">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_activate",$args,$parent,$i));$buffer.='" class="input">
            ';} return $buffer;}function editing24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
    <tr';$buffer.=$this->active26($args,$parent,$i);$buffer.='>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reason",$args,$parent,$i));$buffer.='</td>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("route_name",$args,$parent,$i));$buffer.='</code></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("end",$args,$parent,$i));$buffer.='</td>
    <td><input type="checkbox" disabled ';$buffer.=$this->active40($args,$parent,$i);$buffer.='></td>
    <td>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?edit=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="ban_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            ';$buffer.=$this->active54($args,$parent,$i);$buffer.='
            ';$buffer.=$this->active56($args,$parent,$i);$buffer.='
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="ban_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="delete_ban">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_mercy",$args,$parent,$i));$buffer.='" class="input">
        </form>
    </td>
    </tr>
    ';} return $buffer;}function selected46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function route_options42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("route_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected46($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function active46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="update_ban">
            <input type="hidden" name="ban_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type",$args,$parent,$i));$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='</td>
            <td><input type="text" name="reason" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reason",$args,$parent,$i));$buffer.='"></td>
            <td>
                <select name="route" class="input">
                    ';$buffer.=$this->route_options42($args,$parent,$i);$buffer.='
                </select>
            </td>
            <td><input type="text" name="end" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("end",$args,$parent,$i));$buffer.='" placeholder="YYYY-MM-DD HH:MM:SS"></td>
            <td><input type="checkbox" name="active" value="1"';$buffer.=$this->active46($args,$parent,$i);$buffer.='></td>
            <td>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input">
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
            </td>
        </form>
    </tr>
    ';} return $buffer;}function ban_list22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("ban_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    ';$buffer.=$this->editing24($args,$parent,$i);$buffer.='

    ';$buffer.=$this->editing26($args,$parent,$i);$buffer.='
    ';} return $buffer;}function add_ban_routes40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("add_ban_routes",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function is_editing50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_editing",$args,$parent,$i);if(!$resolved){$buffer.='
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("edit_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        ';} return $buffer;}function is_editing52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
        ';} return $buffer;}function enabled76($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(!$resolved){$buffer.=' style="opacity:0.5"';} return $buffer;}function enabled90($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function editing74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
        <tr';$buffer.=$this->enabled76($args,$parent,$i);$buffer.='>
        <td>#';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("index",$args,$parent,$i));$buffer.='</td>
        <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("penalty",$args,$parent,$i));$buffer.=' <small>(';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("penalty_fmt",$args,$parent,$i));$buffer.=')</small></td>
        <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_tries_fmt",$args,$parent,$i));$buffer.='</td>
        <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("check_time",$args,$parent,$i));$buffer.=' <small>(';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("check_time_fmt",$args,$parent,$i));$buffer.=')</small></td>
        <td><input type="checkbox" disabled ';$buffer.=$this->enabled90($args,$parent,$i);$buffer.='></td>
        <td>
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"    value="delete_round">
                <input type="hidden" name="route_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("route_key_val",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="round_idx" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("index",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input">
            </form>
        </td>
        </tr>
        ';} return $buffer;}function enabled94($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing76($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <form method="POST">
                <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"    value="update_round">
                <input type="hidden" name="route_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("route_key_val",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="round_idx" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("index",$args,$parent,$i));$buffer.='">
                <td>#';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("index",$args,$parent,$i));$buffer.='</td>
                <td><input type="number" name="penalty"    class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("penalty",$args,$parent,$i));$buffer.='"    min="0" style="width:7em"><br><small>s, 0=∞</small></td>
                <td><input type="number" name="max_tries"  class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_tries",$args,$parent,$i));$buffer.='"  min="0" style="width:5em"><br><small>0=∞</small></td>
                <td><input type="number" name="check_time" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("check_time",$args,$parent,$i));$buffer.='" min="0" style="width:7em"><br><small>s, 0=—</small></td>
                <td><input type="checkbox" name="enabled" value="1"';$buffer.=$this->enabled94($args,$parent,$i);$buffer.='></td>
                <td>
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input">
                    <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
                </td>
            </form>
        </tr>
        ';} return $buffer;}function rounds72($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("rounds",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        ';$buffer.=$this->editing74($args,$parent,$i);$buffer.='

        ';$buffer.=$this->editing76($args,$parent,$i);$buffer.='
        ';} return $buffer;}function is_editing76($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <tr>
            <form method="POST">
                <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"    value="add_round">
                <input type="hidden" name="route_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='">
                <td><em>new</em></td>
                <td><input type="number" name="penalty"    class="input" value="0"    min="0" style="width:7em"><br><small>s, 0=∞</small></td>
                <td><input type="number" name="max_tries"  class="input" value="3"    min="0" style="width:5em"><br><small>0=∞</small></td>
                <td><input type="number" name="check_time" class="input" value="3600" min="0" style="width:7em"><br><small>s, 0=—</small></td>
                <td><input type="checkbox" name="enabled" value="1" checked></td>
                <td><input type="submit" value="+ ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add",$args,$parent,$i));$buffer.='" class="input"></td>
            </form>
        </tr>
        ';} return $buffer;}function ban_routes46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("ban_routes",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<fieldset style="margin:12px 0">
    <legend>
        <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='</strong>
        &nbsp;
        ';$buffer.=$this->is_editing50($args,$parent,$i);$buffer.='
        ';$buffer.=$this->is_editing52($args,$parent,$i);$buffer.='
        &nbsp;
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"    value="delete_route">
            <input type="hidden" name="route_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input">
        </form>
    </legend>

    <table>
        <thead><tr>
            <th>Round</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_penalty",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_tries",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_check_win",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_enabled",$args,$parent,$i));$buffer.='</th>
            <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
        </tr></thead>
        <tbody>

        ';$buffer.=$this->rounds72($args,$parent,$i);$buffer.='

        ';$buffer.='
        ';$buffer.=$this->is_editing76($args,$parent,$i);$buffer.='

        </tbody>
    </table>
</fieldset>
';} return $buffer;}function has_routes48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_routes",$args,$parent,$i);if(!$resolved){$buffer.='<p><em>No routes defined.</em></p>';} return $buffer;}}