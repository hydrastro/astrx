<?php class Templatechat_help2511442b8cb40a4af04241a401cb7a0f{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="chat-help">
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>

    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_formatting_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_formatting",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>

    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_me_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_me",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>

    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_pm_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>

    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_ignore_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_ignore",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>

    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_roles_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_roles",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>

    ';$buffer.=$this->help_has_rules24($args,$parent,$i);$buffer.='
</div>
';return ($buffer) ? $buffer : "";}function help_has_rules24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("help_has_rules",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <h3 id="chat-rules">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_rules_head",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
    <p class="chat-rules-body">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("help_rules",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>
    ';} return $buffer;}}