<?php class Templateerrord4eb1ca3a65f4b43fbfb481f6c7baa15{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("error",$args,$parent,$i));$buffer.=' ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("error_code",$args,$parent,$i));$buffer.=' - ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("error_name",$args,$parent,$i));$buffer.='</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("error_message",$args,$parent,$i));$buffer.='</p>
<hr>
<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("home_url",$args,$parent,$i));$buffer.='" class="btn">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("error_go_home",$args,$parent,$i));$buffer.='</a>
';$buffer.=$this->error_show_diagnostics14($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function error_diagnostics16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("error_diagnostics",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li class="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("css_class",$args,$parent,$i));$buffer.='"><code>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='</code></li>';} return $buffer;}function error_show_diagnostics14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("error_show_diagnostics",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<details class="error-diagnostics" open>
  <summary>Diagnostics</summary>
  <ul>
    ';$buffer.=$this->error_diagnostics16($args,$parent,$i);$buffer.='
  </ul>
</details>
';} return $buffer;}}