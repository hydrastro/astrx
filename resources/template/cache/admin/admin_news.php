<?php class Templateadmin_admin_news55959a5b3bebc6f23459d6366f1d7002{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_news_heading",$args,$parent,$i));$buffer.='</h2>

<table>
    <thead><tr>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_id",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_title",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_date",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hidden",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
    </tr></thead>
    <tbody>

    ';$buffer.=$this->news_list14($args,$parent,$i);$buffer.='

    ';$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action" value="create">
            <td><em>new</em></td>
            <td colspan="2">
                <input type="text" name="title" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_title",$args,$parent,$i));$buffer.='"><br>
                <textarea name="content" rows="5" class="input" style="width:100%"></textarea>
            </td>
            <td><input type="checkbox" name="hidden" value="1"></td>
            <td><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_create",$args,$parent,$i));$buffer.='" class="input"></td>
        </form>
    </tr>

    </tbody>
</table>';return ($buffer) ? $buffer : "";}function hidden18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' style="opacity:0.5"';} return $buffer;}function hidden26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function editing16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
    <tr';$buffer.=$this->hidden18($args,$parent,$i);$buffer.='>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='</td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</td>
    <td><input type="checkbox" disabled ';$buffer.=$this->hidden26($args,$parent,$i);$buffer.='></td>
    <td>
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?edit=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
        <form method="POST" style="display:inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input"
            >
        </form>
    </td>
    </tr>
    ';} return $buffer;}function hidden32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
        <form method="POST">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
            <td colspan="2">
                <input type="text" name="title" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='"><br>
                <textarea name="content" rows="10" class="input" style="width:100%">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("content",$args,$parent,$i));$buffer.='</textarea>
            </td>
            <td><input type="checkbox" name="hidden" value="1"';$buffer.=$this->hidden32($args,$parent,$i);$buffer.='></td>
            <td>
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input"><br>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
            </td>
        </form>
    </tr>
    ';} return $buffer;}function news_list14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("news_list",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    ';$buffer.=$this->editing16($args,$parent,$i);$buffer.='
    ';$buffer.=$this->editing18($args,$parent,$i);$buffer.='
    ';} return $buffer;}}