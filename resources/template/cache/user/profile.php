<?php class Templateuser_profile539476d2d20fb35c6a3c203549dc10c6{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->profile_not_found1($args,$parent,$i);$buffer.='
';$buffer.=$this->profile_not_found3($args,$parent,$i);return ($buffer) ? $buffer : "";}function profile_not_found1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("profile_not_found",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<p class="error">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_not_found_msg",$args,$parent,$i));$buffer.='</p>
';} return $buffer;}function profile_has_avatar5($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("profile_has_avatar",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
  <img src="';$buffer.=$this->TemplateEngine->resolveValue("profile_avatar_src",$args,$parent,$i);$buffer.='" alt="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_username",$args,$parent,$i));$buffer.='" class="profile-avatar">
  ';} return $buffer;}function profile_verified19($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("profile_verified",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_label_verified",$args,$parent,$i));$buffer.='</th><td>✓</td></tr>
    ';} return $buffer;}function profile_is_own21($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("profile_is_own",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
  <p><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_settings_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_label_settings",$args,$parent,$i));$buffer.='</a></p>
  ';} return $buffer;}function profile_not_found3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("profile_not_found",$args,$parent,$i);if(!$resolved){$buffer.='
<div class="profile">
  ';$buffer.=$this->profile_has_avatar5($args,$parent,$i);$buffer.='
  <h2 class="profile-name">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_display_name",$args,$parent,$i));$buffer.='</h2>
  <p class="profile-username">@';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_username",$args,$parent,$i));$buffer.='</p>
  <table class="profile-table">
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_label_group",$args,$parent,$i));$buffer.='</th><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_group",$args,$parent,$i));$buffer.='</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_label_joined",$args,$parent,$i));$buffer.='</th><td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_joined",$args,$parent,$i));$buffer.='</td></tr>
    ';$buffer.=$this->profile_verified19($args,$parent,$i);$buffer.='
  </table>
  ';$buffer.=$this->profile_is_own21($args,$parent,$i);$buffer.='
</div>
';} return $buffer;}}