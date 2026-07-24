<?php class Templateadmin2aa4656e21863b59b12fcd81b2e485be{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->admin_forbidden1($args,$parent,$i);$buffer.='
';$buffer.=$this->admin_forbidden3($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function admin_forbidden1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h2>403 — Forbidden</h2>
<p>You do not have permission to access the administration panel.</p>
';} return $buffer;}function admin_sections9($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_sections",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h3><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></h3>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("desc",$args,$parent,$i));$buffer.='</p>
<hr>
';} return $buffer;}function admin_forbidden3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(!$resolved){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_heading",$args,$parent,$i));$buffer.='</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_welcome",$args,$parent,$i));$buffer.='</p>
<hr>
';$buffer.=$this->admin_sections9($args,$parent,$i);$buffer.='
';} return $buffer;}}