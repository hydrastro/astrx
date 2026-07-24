<?php class Templateadmin_admin_navbard6190c37aa7d489d9cda8a71a2c8f683{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<style>
    /* ── CSS-only tabs ────────────────────────────────────────────────────── */
    input.nb-tab { display: none; }
    .nb-content  { display: none; }

    #nb1:checked ~ #nb1-content,
    #nb2:checked ~ #nb2-content,
    #nb3:checked ~ #nb3-content { display: block; }

    /* Tab bar: labels follow their radio inputs, CSS makes them look like a bar */
    label.nb-label { margin-top: 12px; }
    /* Separator line under the tab row — achieved via bottom border on all labels */
    label.nb-label { border-bottom: 2px solid #ccc; }

    label.nb-label {
        display: inline-block;
        padding: 6px 18px;
        border: 1px solid #ccc;
        border-bottom: none;
        margin-right: 2px;
        cursor: pointer;
    }

    /* Active tab — input immediately precedes its label, so + works */
    #nb1:checked + label,
    #nb2:checked + label,
    #nb3:checked + label {
        border-bottom: 2px solid #fff;
        margin-bottom: -2px;
        font-weight: bold;
    }
</style>

<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_navbar_heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
';$buffer.=$this->navbars6($args,$parent,$i);$buffer.='


';$buffer.='
';$buffer.=$this->navbars10($args,$parent,$i);return ($buffer) ? $buffer : "";}function active10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function navbars6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("navbars",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<input type="radio" class="nb-tab" name="nb" id="nb';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='"';$buffer.=$this->active10($args,$parent,$i);$buffer.='>
<label class="nb-label" for="nb';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</label>
';} return $buffer;}function alpha20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("alpha",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_alpha",$args,$parent,$i));} return $buffer;}function custom21($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("custom",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_custom",$args,$parent,$i));} return $buffer;}function editing16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
            <strong>Group #';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</strong>
            &mdash;
            ';$buffer.=$this->alpha20($args,$parent,$i);$buffer.=$this->custom21($args,$parent,$i);$buffer.=',
            ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort",$args,$parent,$i));$buffer.=' ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_order",$args,$parent,$i));$buffer.='
            &nbsp;
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?nb=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='&pin=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
            &nbsp;
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"    value="delete_pin">
                <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.=' group" class="input"
                >
            </form>
            ';} return $buffer;}function alpha32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("alpha",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function custom36($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("custom",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function editing18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <strong>Group #';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</strong>
            &mdash;
            <form method="POST" style="display:inline">
                <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="action"    value="update_pin">
                <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort_mode",$args,$parent,$i));$buffer.=':
                <select name="sort_mode" class="input">
                    <option value="0"';$buffer.=$this->alpha32($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_alpha",$args,$parent,$i));$buffer.='</option>
                    <option value="1"';$buffer.=$this->custom36($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_custom",$args,$parent,$i));$buffer.='</option>
                </select>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort",$args,$parent,$i));$buffer.=':
                <input type="number" name="sort_order" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_order",$args,$parent,$i));$buffer.='" style="width:5em">
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input">
            </form>
            &nbsp;
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?nb=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
            ';} return $buffer;}function active34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(!$resolved){$buffer.=' style="opacity:0.5"';} return $buffer;}function i18n40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("i18n",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function internal42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("internal",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_internal",$args,$parent,$i));} return $buffer;}function internal43($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("internal",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_external",$args,$parent,$i));} return $buffer;}function page_url_id48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("page_url_id",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' <em>(';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("page_url_id",$args,$parent,$i));$buffer.=')</em>';} return $buffer;}function internal45($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("internal",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='page #';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("page_id",$args,$parent,$i));$buffer.=$this->page_url_id48($args,$parent,$i);} return $buffer;}function internal47($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("internal",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));} return $buffer;}function active51($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function editing32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(!$resolved){$buffer.='
            <tr';$buffer.=$this->active34($args,$parent,$i);$buffer.='>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</td>
            <td><input type="checkbox" disabled ';$buffer.=$this->i18n40($args,$parent,$i);$buffer.='></td>
            <td>';$buffer.=$this->internal42($args,$parent,$i);$buffer.=$this->internal43($args,$parent,$i);$buffer.='</td>
            <td>
                ';$buffer.=$this->internal45($args,$parent,$i);$buffer.='
                ';$buffer.=$this->internal47($args,$parent,$i);$buffer.='
            </td>
            <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_order",$args,$parent,$i));$buffer.='</td>
            <td><input type="checkbox" disabled ';$buffer.=$this->active51($args,$parent,$i);$buffer.='></td>
            <td>
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?nb=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='&pin=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pin_id",$args,$parent,$i));$buffer.='&entry=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_edit",$args,$parent,$i));$buffer.='</a>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"    value="toggle_entry">
                    <input type="hidden" name="entry_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pin_id",$args,$parent,$i));$buffer.='">
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_toggle",$args,$parent,$i));$buffer.='" class="input">
                </form>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"    value="delete_entry">
                    <input type="hidden" name="entry_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pin_id",$args,$parent,$i));$buffer.='">
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete",$args,$parent,$i));$buffer.='" class="input"
                    >
                </form>
            </td>
            </tr>
            ';} return $buffer;}function i18n50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("i18n",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function i18n54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("i18n",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function external56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("external",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function internal60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("internal",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function selected74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function title77($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("title",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.=')';} return $buffer;}function available_pages70($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("available_pages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected74($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url_id",$args,$parent,$i));$buffer.=$this->title77($args,$parent,$i);$buffer.='</option>';} return $buffer;}function active74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function editing34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("editing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <tr>
                <form method="POST">
                    <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"    value="update_entry">
                    <input type="hidden" name="entry_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pin_id",$args,$parent,$i));$buffer.='">
                    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</td>
                    <td>
                        <input type="text" name="name" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='"><br>
                        <label style="font-size:0.85em">
                            <input type="checkbox" name="i18n" value="1"';$buffer.=$this->i18n50($args,$parent,$i);$buffer.='> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_i18n",$args,$parent,$i));$buffer.='
                        </label>
                    </td>
                    <td><input type="checkbox" disabled ';$buffer.=$this->i18n54($args,$parent,$i);$buffer.='></td>
                    <td>
                        <select name="type" class="input">
                            <option value="external"';$buffer.=$this->external56($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_external",$args,$parent,$i));$buffer.='</option>
                            <option value="internal"';$buffer.=$this->internal60($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_internal",$args,$parent,$i));$buffer.='</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="url" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_external",$args,$parent,$i));$buffer.='"><br>
                        <select name="page_id" class="input">
                            <option value="0">— ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_internal",$args,$parent,$i));$buffer.=' —</option>
                            ';$buffer.=$this->available_pages70($args,$parent,$i);$buffer.='
                        </select>
                    </td>
                    <td><input type="number" name="sort_order" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_order",$args,$parent,$i));$buffer.='" style="width:5em"></td>
                    <td><input type="checkbox" name="active" value="1"';$buffer.=$this->active74($args,$parent,$i);$buffer.='></td>
                    <td>
                        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_update",$args,$parent,$i));$buffer.='" class="input">
                        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?nb=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='&pin=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("pin_id",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_cancel",$args,$parent,$i));$buffer.='</a>
                    </td>
                </form>
            </tr>
            ';} return $buffer;}function entries30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("entries",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            ';$buffer.=$this->editing32($args,$parent,$i);$buffer.='
            ';$buffer.=$this->editing34($args,$parent,$i);$buffer.='
            ';} return $buffer;}function title59($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("title",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.=')';} return $buffer;}function available_pages54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("available_pages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url_id",$args,$parent,$i));$buffer.=$this->title59($args,$parent,$i);$buffer.='</option>';} return $buffer;}function pins14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("pins",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <fieldset style="margin:10px 0">
        <legend>
            ';$buffer.=$this->editing16($args,$parent,$i);$buffer.='
            ';$buffer.=$this->editing18($args,$parent,$i);$buffer.='
        </legend>

        <table>
            <thead><tr>
                <th>#</th>
                <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_name",$args,$parent,$i));$buffer.='</th>
                <th>i18n</th>
                <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_type",$args,$parent,$i));$buffer.='</th>
                <th>Target</th>
                <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort",$args,$parent,$i));$buffer.='</th>
                <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_active",$args,$parent,$i));$buffer.='</th>
                <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_actions",$args,$parent,$i));$buffer.='</th>
            </tr></thead>
            <tbody>

            ';$buffer.=$this->entries30($args,$parent,$i);$buffer.='

            ';$buffer.='
            <tr>
                <form method="POST">
                    <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"    value="add_entry">
                    <input type="hidden" name="pin_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("navbar_id",$args,$parent,$i));$buffer.='">
                    <td><em>new</em></td>
                    <td>
                        <input type="text" name="name" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_name",$args,$parent,$i));$buffer.='"><br>
                        <label style="font-size:0.85em">
                            <input type="checkbox" name="i18n" value="1"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_i18n",$args,$parent,$i));$buffer.='
                        </label>
                    </td>
                    <td></td>
                    <td>
                        <select name="type" class="input">
                            <option value="external">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_external",$args,$parent,$i));$buffer.='</option>
                            <option value="internal">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_internal",$args,$parent,$i));$buffer.='</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="url" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_external",$args,$parent,$i));$buffer.='"><br>
                        <select name="page_id" class="input">
                            <option value="0">— ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_internal",$args,$parent,$i));$buffer.=' —</option>
                            ';$buffer.=$this->available_pages54($args,$parent,$i);$buffer.='
                        </select>
                    </td>
                    <td><input type="number" name="sort_order" class="input" value="0" style="width:5em"></td>
                    <td><input type="checkbox" name="active" value="1" checked></td>
                    <td><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add_entry",$args,$parent,$i));$buffer.='" class="input"></td>
                </form>
            </tr>

            </tbody>
        </table>
    </fieldset>
    ';} return $buffer;}function pins16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("pins",$args,$parent,$i);if(!$resolved){$buffer.='<p><em>No groups yet.</em></p>';} return $buffer;}function navbars10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("navbars",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div id="nb';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='-content" class="nb-content">

    ';$buffer.=$this->pins14($args,$parent,$i);$buffer.='
    ';$buffer.=$this->pins16($args,$parent,$i);$buffer.='

    <form method="POST">
        <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="action"    value="add_pin">
        <input type="hidden" name="navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
        ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort_mode",$args,$parent,$i));$buffer.=':
        <select name="sort_mode" class="input">
            <option value="0">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_alpha",$args,$parent,$i));$buffer.='</option>
            <option value="1">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sort_custom",$args,$parent,$i));$buffer.='</option>
        </select>
        ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort",$args,$parent,$i));$buffer.=':
        <input type="number" name="sort_order" class="input" value="0" style="width:5em">
        <input type="submit" value="+ ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add_pin",$args,$parent,$i));$buffer.='" class="input">
    </form>

</div>
';} return $buffer;}}