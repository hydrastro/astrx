<?php class Templatetestdaf8de5b07bfcd4b1ac6cf76b695d56d{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h1>Test</h1>
<pre>
    remember. ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("test",$args,$parent,$i));$buffer.=' this is a test.
';$buffer.=$this->ar4($args,$parent,$i);$buffer.='
loll';return ($buffer) ? $buffer : "";}function ar4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("ar",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue(".",$args,$parent,$i));$buffer.='
';} return $buffer;}}