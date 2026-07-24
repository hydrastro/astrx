<?php class Templateuser_register8be3206b42a1d7a11f6907a797effb1b{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_heading",$args,$parent,$i));$buffer.='</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_description",$args,$parent,$i));$buffer.='</p>
<hr>
';$buffer.=$this->registrations_closed6($args,$parent,$i);$buffer.='
';$buffer.=$this->registrations_closed8($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function registrations_closed6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("registrations_closed",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_closed_msg",$args,$parent,$i));$buffer.='</p>
';} return $buffer;}function show_mailbox22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_mailbox",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <label for="reg_mailbox">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_mailbox",$args,$parent,$i));$buffer.=': </label>
    <input type="text" name="mailbox" class="input" id="reg_mailbox"
           value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("mailbox_value",$args,$parent,$i));$buffer.='" autocomplete="username"
           placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_mailbox_hint",$args,$parent,$i));$buffer.='"
           pattern="[a-zA-Z0-9][a-zA-Z0-9.\\-_]{0,63}"
           title="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_mailbox_hint",$args,$parent,$i));$buffer.='"><br>
    ';} return $buffer;}function show_email24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_email",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <label for="reg_email">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_email",$args,$parent,$i));$buffer.=': </label>
    <input type="email" name="email" class="input" id="reg_email" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("email_value",$args,$parent,$i));$buffer.='" autocomplete="email"><br>
    ';} return $buffer;}function show_display_name26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_display_name",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <label for="reg_dname">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_display_name",$args,$parent,$i));$buffer.=': </label>
    <input type="text" name="display_name" class="input" id="reg_dname" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display_name_value",$args,$parent,$i));$buffer.='"><br>
    ';} return $buffer;}function show_birth_date28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_birth_date",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_birth_date",$args,$parent,$i));$buffer.=': </label>
    <input type="number" name="day"   class="input" min="1" max="31"  style="width:3.5em" placeholder="DD">
    <input type="number" name="month" class="input" min="1" max="12"  style="width:3.5em" placeholder="MM">
    <input type="number" name="year"  class="input" style="width:5em" placeholder="YYYY"><br>
    ';} return $buffer;}function terms_url35($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("terms_url",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("terms_url",$args,$parent,$i));$buffer.='" target="_blank" rel="noopener">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_terms_label",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function terms_url37($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("terms_url",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_terms_label",$args,$parent,$i));} return $buffer;}function show_terms33($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_terms",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <p>
      <label>
        <input type="checkbox" name="terms_accepted" value="1">
        ';$buffer.=$this->terms_url35($args,$parent,$i);$buffer.='
        ';$buffer.=$this->terms_url37($args,$parent,$i);$buffer.='
      </label>
    </p>
    ';} return $buffer;}function data_usage_url37($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("data_usage_url",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("data_usage_url",$args,$parent,$i));$buffer.='" target="_blank" rel="noopener">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_data_usage_label",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function data_usage_url39($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("data_usage_url",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_data_usage_label",$args,$parent,$i));} return $buffer;}function show_data_usage35($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_data_usage",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <p>
      <label>
        <input type="checkbox" name="data_usage_accepted" value="1">
        ';$buffer.=$this->data_usage_url37($args,$parent,$i);$buffer.='
        ';$buffer.=$this->data_usage_url39($args,$parent,$i);$buffer.='
      </label>
    </p>
    ';} return $buffer;}function registrations_closed8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("registrations_closed",$args,$parent,$i);if(!$resolved){$buffer.='
<form method="POST">
  <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <p>
    <label for="reg_user">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_username",$args,$parent,$i));$buffer.=': </label>
    <input type="text" name="username" class="input" id="reg_user" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username_value",$args,$parent,$i));$buffer.='" autocomplete="username"><br>
    <label for="reg_pass">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_password",$args,$parent,$i));$buffer.=': </label>
    <input type="password" name="password" class="input" id="reg_pass" autocomplete="new-password"><br>
    <label for="reg_repeat">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_repeat",$args,$parent,$i));$buffer.=': </label>
    <input type="password" name="repeat" class="input" id="reg_repeat" autocomplete="new-password"><br>
    ';$buffer.=$this->show_mailbox22($args,$parent,$i);$buffer.='
    ';$buffer.=$this->show_email24($args,$parent,$i);$buffer.='
    ';$buffer.=$this->show_display_name26($args,$parent,$i);$buffer.='
    ';$buffer.=$this->show_birth_date28($args,$parent,$i);$buffer.='
    ';$p30Name=$this->TemplateEngine->resolveValue("captcha",$args,$parent,$i);if(is_string($p30Name)&&$p30Name!==""){$p30=$this->TemplateEngine->loadTemplate($p30Name);if($p30!==null){$buffer.=$p30->render($args,$parent);}}$buffer.='
    ';$buffer.=$this->show_terms33($args,$parent,$i);$buffer.='
    ';$buffer.=$this->show_data_usage35($args,$parent,$i);$buffer.='
    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_submit",$args,$parent,$i));$buffer.='" class="input"><br>
    <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("login_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reg_back",$args,$parent,$i));$buffer.='</a>.
  </p>
</form>
';} return $buffer;}}