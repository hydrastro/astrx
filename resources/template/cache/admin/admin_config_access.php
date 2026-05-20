<?php class Templateadmin_admin_config_access6b311f0bb767bafa2c7839b0cabdc2ec{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_grants",$args,$parent,$i));$buffer.='</h3>
<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_admin_note",$args,$parent,$i));$buffer.='</em></p>

<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="grants">
  <table>
    <thead>
    <tr>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_permission",$args,$parent,$i));$buffer.='</th>
      ';$buffer.=$this->group_headers16($args,$parent,$i);$buffer.='
      <th>ADMIN</th>
    </tr>
    </thead>
    <tbody>
    ';$buffer.=$this->prefix_sections18($args,$parent,$i);$buffer.='
    </tbody>
  </table>
  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></p>
</form>

';$buffer.='
<table>
  <tr>
    <td>
      <form method="POST">
        <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="section" value="add_group">
        <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_new_group_name",$args,$parent,$i));$buffer.=':
          <input type="text" name="new_group_name" class="input" placeholder="e.g. EDITOR">
        </label>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_add_group",$args,$parent,$i));$buffer.='" class="input">
      </form>
    </td>
    <td style="padding-left:2em">
      <form method="POST">
        <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="section" value="delete_group">
        <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_delete_group",$args,$parent,$i));$buffer.=':
          <select name="delete_group" class="input">
            ';$buffer.=$this->deletable_groups38($args,$parent,$i);$buffer.='
          </select>
        </label>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_delete_group",$args,$parent,$i));$buffer.='" class="input">
      </form>
    </td>
  </tr>
</table>

';$buffer.='
';$buffer.=$this->has_diag_sections44($args,$parent,$i);return ($buffer) ? $buffer : "";}function group_headers16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("group_headers",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</th>';} return $buffer;}function granted30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("granted",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cells26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cells",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
      <td style="text-align:center">
        <input type="checkbox" name="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("field",$args,$parent,$i));$buffer.='" value="1"';$buffer.=$this->granted30($args,$parent,$i);$buffer.='>
      </td>
      ';} return $buffer;}function rows22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("rows",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("perm_value",$args,$parent,$i));$buffer.='</code></td>
      ';$buffer.=$this->cells26($args,$parent,$i);$buffer.='
      <td style="text-align:center">*</td>
    </tr>
    ';} return $buffer;}function prefix_sections18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("prefix_sections",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <th colspan="10" style="text-align:left;padding-top:8px">
        <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prefix_label",$args,$parent,$i));$buffer.='</strong>
      </th>
    </tr>
    ';$buffer.=$this->rows22($args,$parent,$i);$buffer.='
    ';} return $buffer;}function deletable_groups38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("deletable_groups",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function diag_group_headers56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("diag_group_headers",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</th>';} return $buffer;}function visible70($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("visible",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cells66($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cells",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
      <td style="text-align:center">
        <input type="checkbox" name="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("field",$args,$parent,$i));$buffer.='" value="1"';$buffer.=$this->visible70($args,$parent,$i);$buffer.='>
      </td>
      ';} return $buffer;}function rows62($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("rows",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("code",$args,$parent,$i));$buffer.='</code></td>
      ';$buffer.=$this->cells66($args,$parent,$i);$buffer.='
      <td style="text-align:center">*</td>
    </tr>
    ';} return $buffer;}function diag_sections58($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("diag_sections",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <th colspan="10" style="text-align:left;padding-top:8px">
        <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prefix",$args,$parent,$i));$buffer.='</strong>
      </th>
    </tr>
    ';$buffer.=$this->rows62($args,$parent,$i);$buffer.='
    ';} return $buffer;}function has_override85($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_override",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' (override active)';} return $buffer;}function selected91($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function level_options87($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("level_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
          <option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected91($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</option>
          ';} return $buffer;}function rows78($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("rows",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("code",$args,$parent,$i));$buffer.='</code></td>
      <td>
        <select name="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("level_field",$args,$parent,$i));$buffer.='" class="input">
          <option value="">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diag_level_default",$args,$parent,$i));$buffer.=$this->has_override85($args,$parent,$i);$buffer.='</option>
          ';$buffer.=$this->level_options87($args,$parent,$i);$buffer.='
        </select>
      </td>
    </tr>
    ';} return $buffer;}function diag_sections74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("diag_sections",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr>
      <th colspan="2" style="text-align:left;padding-top:8px">
        <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prefix",$args,$parent,$i));$buffer.='</strong>
      </th>
    </tr>
    ';$buffer.=$this->rows78($args,$parent,$i);$buffer.='
    ';} return $buffer;}function has_diag_sections44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_diag_sections",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_diag_visibility",$args,$parent,$i));$buffer.='</h3>
<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diag_admin_note",$args,$parent,$i));$buffer.='</em></p>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="diag_visibility">
  <table>
    <thead>
    <tr>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diag_code",$args,$parent,$i));$buffer.='</th>
      ';$buffer.=$this->diag_group_headers56($args,$parent,$i);$buffer.='
      <th>ADMIN</th>
    </tr>
    </thead>
    <tbody>
    ';$buffer.=$this->diag_sections58($args,$parent,$i);$buffer.='
    </tbody>
  </table>
  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></p>
</form>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_diag_levels",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="diag_levels">
  <table>
    <thead>
    <tr>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diag_code",$args,$parent,$i));$buffer.='</th>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diag_level",$args,$parent,$i));$buffer.='</th>
    </tr>
    </thead>
    <tbody>
    ';$buffer.=$this->diag_sections74($args,$parent,$i);$buffer.='
    </tbody>
  </table>
  <p><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></p>
</form>
';} return $buffer;}}