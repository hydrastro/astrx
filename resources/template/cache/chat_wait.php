<?php class Templatechat_waita6a98cfa303f42128f246a78f78d88b7{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="chat-wait" class="chat-wait">
    <style>
    .chat-wait { max-width: 26em; margin: 2em auto; text-align: center; }
    .chat-wait h2 { margin: 0 0 .4em; }
    .chat-wait p { margin: .3em 0; opacity: .85; }
    .chat-wait-count { font-size: 2.6em; font-weight: bold; opacity: 1 !important; margin: .2em 0; }
    </style>
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>
    ';$buffer.=$this->wait_show_count6($args,$parent,$i);$buffer.='
    ';$buffer.=$this->wait_continue_url8($args,$parent,$i);$buffer.='
</div>
';return ($buffer) ? $buffer : "";}function wait_show_count6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("wait_show_count",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <p class="chat-wait-count">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_remaining",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_seconds_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>
    ';} return $buffer;}function wait_continue_url8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("wait_continue_url",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p class="chat-wait-continue"><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_continue_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("wait_continue_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a></p>';} return $buffer;}}