<?php class Templatecaptcha_test025d7fe326955a6789295678c864d1a9{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>Captcha Test</h2>

';$buffer.=$this->has_result2($args,$parent,$i);$buffer.='

';$buffer.=$this->has_captchas4($args,$parent,$i);$buffer.='
';$buffer.=$this->has_captchas6($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function result_ok4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("result_ok",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='captcha_ok';} return $buffer;}function result_fail5($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("result_fail",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='captcha_fail';} return $buffer;}function result_ok9($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("result_ok",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='✓ Correct';} return $buffer;}function result_fail10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("result_fail",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='✗ Wrong';} return $buffer;}function has_result2($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_result",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div id="captcha_result" class="';$buffer.=$this->result_ok4($args,$parent,$i);$buffer.=$this->result_fail5($args,$parent,$i);$buffer.='">
    <p><b>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("result_label",$args,$parent,$i));$buffer.=':</b> ';$buffer.=$this->result_ok9($args,$parent,$i);$buffer.=$this->result_fail10($args,$parent,$i);$buffer.='</p>
</div>
';} return $buffer;}function captchas6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("captchas",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="captcha_box">
    <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_label",$args,$parent,$i));$buffer.='</h3>
    <p><img src="data:image/gif;base64,';$buffer.=$this->TemplateEngine->resolveValue("image_b64",$args,$parent,$i);$buffer.='" alt="captcha"></p>
    <form method="POST">
        <input type="hidden" name="prg_id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="captcha_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_id",$args,$parent,$i));$buffer.='">
        <input type="hidden" name="type_label"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("type_label",$args,$parent,$i));$buffer.='">
        <p>
            <input type="text" name="captcha_text" class="input" size="10" autocomplete="off" placeholder="type here">
            <input type="submit" value="Verify" class="input">
        </p>
    </form>
</div>
';} return $buffer;}function has_captchas4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_captchas",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div id="captcha_tests">
';$buffer.=$this->captchas6($args,$parent,$i);$buffer.='
</div>
';} return $buffer;}function has_captchas6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_captchas",$args,$parent,$i);if(!$resolved){$buffer.='
<p>Could not generate captchas — check diagnostics above.</p>
';} return $buffer;}}