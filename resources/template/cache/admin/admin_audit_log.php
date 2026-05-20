<?php class Templateadmin_admin_audit_log8a3acd67a0dacef4a6159a48ef51bc65{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<form method="GET" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_url",$args,$parent,$i));$buffer.='">
  <table>
    <tr>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_user",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="user" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_user",$args,$parent,$i));$buffer.='" class="input"></td>
      <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_action",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="action" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_action",$args,$parent,$i));$buffer.='" class="input" placeholder="e.g. config"></td>
      <td>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_filter",$args,$parent,$i));$buffer.='" class="input">
        <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("filter_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_clear",$args,$parent,$i));$buffer.='</a>
      </td>
    </tr>
  </table>
</form>

<p><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_total",$args,$parent,$i));$buffer.=': ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("audit_total",$args,$parent,$i));$buffer.='</small></p>

';$buffer.=$this->audit_rows26($args,$parent,$i);$buffer.='
';$buffer.=$this->audit_rows28($args,$parent,$i);$buffer.='

<p>
  ';$buffer.=$this->has_prev30($args,$parent,$i);$buffer.='
  ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("audit_page",$args,$parent,$i));$buffer.=' / ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("audit_pages",$args,$parent,$i));$buffer.='
  ';$buffer.=$this->has_next36($args,$parent,$i);$buffer.='
</p>';return ($buffer) ? $buffer : "";}function audit_rows26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("audit_rows",$args,$parent,$i);if(!$resolved){$buffer.='<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_no_entries",$args,$parent,$i));$buffer.='</em></p>';} return $buffer;}function audit_rows42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("audit_rows",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
  <tr style="border-bottom:1px solid #eee">
    <td><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</small></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='</td>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("action",$args,$parent,$i));$buffer.='</code></td>
    <td><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("resource",$args,$parent,$i));$buffer.='</code></td>
    <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("detail",$args,$parent,$i));$buffer.='</td>
    <td><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("ip",$args,$parent,$i));$buffer.='</small></td>
  </tr>
  ';} return $buffer;}function audit_rows28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("audit_rows",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<table style="width:100%;border-collapse:collapse;font-size:0.9em">
  <thead>
  <tr>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_time",$args,$parent,$i));$buffer.='</th>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_user",$args,$parent,$i));$buffer.='</th>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_action",$args,$parent,$i));$buffer.='</th>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_resource",$args,$parent,$i));$buffer.='</th>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_detail",$args,$parent,$i));$buffer.='</th>
    <th style="text-align:left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_ip",$args,$parent,$i));$buffer.='</th>
  </tr>
  </thead>
  <tbody>
  ';$buffer.=$this->audit_rows42($args,$parent,$i);$buffer.='
  </tbody>
</table>
';} return $buffer;}function has_prev30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_prev",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prev_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_prev",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function has_next36($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_next",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("next_url",$args,$parent,$i));$buffer.='" class="input">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_next",$args,$parent,$i));$buffer.='</a>';} return $buffer;}}