<?php class Templateuser54b212aa316bb306f0120b1bea1bd780{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->logged_in1($args,$parent,$i);$buffer.='
';$buffer.=$this->logged_in3($args,$parent,$i);return ($buffer) ? $buffer : "";}function logged_in1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("logged_in",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_welcome_heading",$args,$parent,$i));$buffer.=', ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='!</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_welcome_body",$args,$parent,$i));$buffer.='</p>
<hr>
<h3><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_profile_heading",$args,$parent,$i));$buffer.='</a></h3>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_profile_text",$args,$parent,$i));$buffer.='</p>
<hr>
<h3><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_settings_heading",$args,$parent,$i));$buffer.='</a></h3>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_settings_text",$args,$parent,$i));$buffer.='</p>
';} return $buffer;}function show_recover22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_recover",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("recover_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_lost_password",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function logged_in3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("logged_in",$args,$parent,$i);if(!$resolved){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_heading",$args,$parent,$i));$buffer.='</h2>
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <p>
    <label for="username">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_username",$args,$parent,$i));$buffer.=': </label>
    <input type="text" name="username" class="input" id="username" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username_value",$args,$parent,$i));$buffer.='" autocomplete="username"><br>
    <label for="password">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_password",$args,$parent,$i));$buffer.=': </label>
    <input type="password" name="password" class="input" id="password" autocomplete="current-password"><br>
    ';$p17Name=$this->TemplateEngine->resolveValue("captcha",$args,$parent,$i);if(is_string($p17Name)&&$p17Name!==""){$p17=$this->TemplateEngine->loadTemplate($p17Name);if($p17!==null){$buffer.=$p17->render($args,$parent);}}$buffer.='
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_submit",$args,$parent,$i));$buffer.='" class="input">
    ';$buffer.=$this->show_recover22($args,$parent,$i);$buffer.='<br>
    <label><input type="checkbox" name="remember_me" value="1"> ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_remember_me",$args,$parent,$i));$buffer.='</label><br>
    ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_need_account",$args,$parent,$i));$buffer.=' <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("register_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_register",$args,$parent,$i));$buffer.='</a>.
  </p>
</form>
';} return $buffer;}}