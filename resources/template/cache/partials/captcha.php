<?php class Templatepartials_captcha16fcee7253232cfd65f641e36838b374{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->show_captcha1($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function has_captcha_frame3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_captcha_frame",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
  <iframe name="astrx_captcha_iframe"
          src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_frame_url",$args,$parent,$i));$buffer.='"
          title="captcha"
          width="260" height="90"
          style="border:0;display:block;overflow:hidden;background:transparent">
  </iframe>
  <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_frame_url",$args,$parent,$i));$buffer.='&amp;refresh=1"
     target="astrx_captcha_iframe"
     class="captcha-reload"
     style="display:inline-block;margin:4px 0">
    &#x21bb; ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_reload_label",$args,$parent,$i));$buffer.='
  </a>
  <br>
  ';} return $buffer;}function has_captcha_frame5($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_captcha_frame",$args,$parent,$i);if(!$resolved){$buffer.='
  <img src="data:image/png;base64,';$buffer.=$this->TemplateEngine->resolveValue("captcha_image",$args,$parent,$i);$buffer.='" alt="captcha" class="captcha-image">
  ';} return $buffer;}function show_captcha1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_captcha",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="captcha-block">
  ';$buffer.=$this->has_captcha_frame3($args,$parent,$i);$buffer.='
  ';$buffer.=$this->has_captcha_frame5($args,$parent,$i);$buffer.='
  <label for="captcha_text">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_label",$args,$parent,$i));$buffer.='</label>
  <input type="text" name="captcha_text" id="captcha_text"
         class="input captcha-input" autocomplete="off" spellcheck="false">
  <input type="hidden" name="captcha_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("captcha_id",$args,$parent,$i));$buffer.='">
</div>
';} return $buffer;}}