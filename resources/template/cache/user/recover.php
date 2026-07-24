<?php class Templateuser_recover20bb1ec9ae5f48a10c602f2cb69a03e0{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_heading",$args,$parent,$i));$buffer.='</h2>
';$buffer.=$this->recovery_unavailable4($args,$parent,$i);$buffer.='
';$buffer.=$this->recovery_unavailable6($args,$parent,$i);$buffer.='
<p><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_back",$args,$parent,$i));$buffer.='</a>.</p>';return ($buffer) ? $buffer : "";}function recovery_unavailable4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("recovery_unavailable",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_unavailable_msg",$args,$parent,$i));$buffer.='</p>
';} return $buffer;}function recovery_unavailable6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("recovery_unavailable",$args,$parent,$i);if(!$resolved){$buffer.='
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_description",$args,$parent,$i));$buffer.='</p>
<hr>
<form method="POST">
    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <p>
        <label for="rec_id">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_identifier",$args,$parent,$i));$buffer.=': </label>
        <input type="text" name="username_or_email" class="input" id="rec_id"><br>
        ';$p16Name=$this->TemplateEngine->resolveValue("captcha",$args,$parent,$i);if(is_string($p16Name)&&$p16Name!==""){$p16=$this->TemplateEngine->loadTemplate($p16Name);if($p16!==null){$buffer.=$p16->render($args,$parent);}}$buffer.='
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_submit",$args,$parent,$i));$buffer.='" class="input">
    </p>
</form>
';} return $buffer;}}