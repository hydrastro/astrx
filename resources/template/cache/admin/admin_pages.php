<?php class Templateadmin_admin_pages71bc7d38c412c11b26fba3da199fa222{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_pages_heading",$args,$parent,$i));$buffer.='</h2>

<table>
    <thead><tr>
        <th>ID</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_url_id",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_file_name",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_title",$args,$parent,$i));$buffer.='</th>
        <th>i18n</th>
        <th>tpl</th>
        <th>ctrl</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hidden",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_comments",$args,$parent,$i));$buffer.='</th>
        <th>API</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
    </tr></thead>
    <tbody>

    ';$buffer.=$this->page_list16($args,$parent,$i);$buffer.='

    ';$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action" value="add">
            <td><em>new</em></td>
            <td>
                <small style="color:#c00">⚠ ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pages_routing_warning",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="url_id" class="input" required placeholder="WORDING_…">
            </td>
            <td><input type="text" name="file_name" class="input" required placeholder="file_name"></td>
            <td>
                <input type="text" name="title" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_title",$args,$parent,$i));$buffer.='"><br>
                <textarea name="description" rows="2" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_description",$args,$parent,$i));$buffer.='"></textarea>
            </td>
            <td><input type="checkbox" name="i18n"       value="1" checked></td>
            <td><input type="checkbox" name="template"   value="1" checked></td>
            <td><input type="checkbox" name="controller" value="1" checked></td>
            <td><input type="checkbox" name="hidden"     value="1"></td>
            <td><input type="checkbox" name="comments"   value="1"></td>
            <td><input type="checkbox" name="api_enabled" value="1"></td>
            <td><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add",$args,$parent,$i));$buffer.='" class="input"></td>
        </form>
    </tr>

    </tbody>
</table>';return ($buffer) ? $buffer : "";}function hidden20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' style="opacity:0.5"';} return $buffer;}function i18n30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("i18n",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function template32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("template",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function controller34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("controller",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function hidden42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='✓';} return $buffer;}function hidden43($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(!$resolved){$buffer.='—';} return $buffer;}function comments51($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='✓';} return $buffer;}function comments52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments",$args,$parent,$i);if(!$resolved){$buffer.='—';} return $buffer;}function api_enabled60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("api_enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='✓';} return $buffer;}function api_enabled61($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("api_enabled",$args,$parent,$i);if(!$resolved){$buffer.='—';} return $buffer;}function editing18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
    <tr';$buffer.=$this->hidden20($args,$parent,$i);$buffer.='>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url_id",$args,$parent,$i));$buffer.='</code></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("file_name",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='</td>
    <td><input type="checkbox" disabled ';$buffer.=$this->i18n30($args,$parent,$i);$buffer.='></td>
    <td><input type="checkbox" disabled ';$buffer.=$this->template32($args,$parent,$i);$buffer.='></td>
    <td><input type="checkbox" disabled ';$buffer.=$this->controller34($args,$parent,$i);$buffer.='></td>
    <td>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="toggle_hidden">
            <input type="hidden" name="page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=$this->hidden42($args,$parent,$i);$buffer.=$this->hidden43($args,$parent,$i);$buffer.='" class="input">
        </form>
    </td>
    <td>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="toggle_comments">
            <input type="hidden" name="page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=$this->comments51($args,$parent,$i);$buffer.=$this->comments52($args,$parent,$i);$buffer.='" class="input">
        </form>
    </td>
    <td>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="toggle_api_enabled">
            <input type="hidden" name="page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=$this->api_enabled60($args,$parent,$i);$buffer.=$this->api_enabled61($args,$parent,$i);$buffer.='" class="input">
        </form>
    </td>
    <td>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?edit=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="delete">
            <input type="hidden" name="page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input"
            >
        </form>
    </td>
    </tr>
    ';} return $buffer;}function i18n40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("i18n",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='       checked';} return $buffer;}function template42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("template",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='   checked';} return $buffer;}function controller44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("controller",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function hidden46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='     checked';} return $buffer;}function comments48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='   checked';} return $buffer;}function api_enabled50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("api_enabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action"  value="update">
            <input type="hidden" name="page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
            <td>
                <small style="color:#c00">⚠ ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pages_routing_warning",$args,$parent,$i));$buffer.='</small><br>
                <input type="text" name="url_id" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url_id",$args,$parent,$i));$buffer.='">
            </td>
            <td><input type="text" name="file_name" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("file_name",$args,$parent,$i));$buffer.='"></td>
            <td>
                <input type="text" name="title" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='"><br>
                <textarea name="description" rows="2" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("description",$args,$parent,$i));$buffer.='</textarea>
            </td>
            <td><input type="checkbox" name="i18n"       value="1"';$buffer.=$this->i18n40($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="template"   value="1"';$buffer.=$this->template42($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="controller" value="1"';$buffer.=$this->controller44($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="hidden"     value="1"';$buffer.=$this->hidden46($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="comments"   value="1"';$buffer.=$this->comments48($args,$parent,$i);$buffer.='></td>
            <td><input type="checkbox" name="api_enabled" value="1"';$buffer.=$this->api_enabled50($args,$parent,$i);$buffer.='></td>
            <td>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input"><br>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
            </td>
        </form>
    </tr>
    ';} return $buffer;}function page_list16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("page_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    ';$buffer.=$this->editing18($args,$parent,$i);$buffer.='

    ';$buffer.=$this->editing20($args,$parent,$i);$buffer.='
    ';} return $buffer;}}