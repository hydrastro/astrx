<?php class Templateadmin_themesad6ac994f246315b76e579353ad48102{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.=$this->admin_forbidden1($args,$parent,$i);$buffer.='

';$buffer.=$this->admin_forbidden3($args,$parent,$i);$buffer.='
';return ($buffer) ? $buffer : "";}function admin_forbidden1($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<div class="error-page">
  <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("forbidden_message",$args,$parent,$i));$buffer.='</h2>
</div>
';} return $buffer;}function has_themes9($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_themes",$args,$parent,$i);if(!$resolved){$buffer.='
<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("no_themes",$args,$parent,$i));$buffer.='</em></p>
';} return $buffer;}function active31($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("active",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function themes25($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("themes",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
      <tr>
        <td>
          <input type="radio"
                 name="theme"
                 value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='"
                 id="theme_';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='"
                 ';$buffer.=$this->active31($args,$parent,$i);$buffer.='>
        </td>
        <td>
          <label for="theme_';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("key",$args,$parent,$i));$buffer.='">
            <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</strong><br>
            <small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("description",$args,$parent,$i));$buffer.='</small>
          </label>
        </td>
        <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("author",$args,$parent,$i));$buffer.='</td>
        <td>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("version",$args,$parent,$i));$buffer.='</td>
      </tr>
      ';} return $buffer;}function allow_user_override27($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("allow_user_override",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function has_themes11($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_themes",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
<form method="POST" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?_prg=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="global">

  <table>
    <thead>
      <tr>
        <th>&nbsp;</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_theme",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_author",$args,$parent,$i));$buffer.='</th>
        <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_version",$args,$parent,$i));$buffer.='</th>
      </tr>
    </thead>
    <tbody>
      ';$buffer.=$this->themes25($args,$parent,$i);$buffer.='
    </tbody>
  </table>

  <p>
    <label>
      <input type="checkbox"
             name="allow_user_override"
             value="1"
             ';$buffer.=$this->allow_user_override27($args,$parent,$i);$buffer.='>
      ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_user_override",$args,$parent,$i));$buffer.='
    </label>
    <br><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_allow_user_override_hint",$args,$parent,$i));$buffer.='</small>
  </p>

  <p>
    <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='">
  </p>
</form>


<hr>
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cache_heading",$args,$parent,$i));$buffer.='</h3>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cache_desc",$args,$parent,$i));$buffer.='</p>
<form method="POST" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("base_url",$args,$parent,$i));$buffer.='?_prg=';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="clear_cache">
  <p>
    <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_clear_cache",$args,$parent,$i));$buffer.='">
  </p>
</form>
';} return $buffer;}function admin_forbidden3($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_forbidden",$args,$parent,$i);if(!$resolved){$buffer.='
<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_themes_heading",$args,$parent,$i));$buffer.='</h2>
<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_themes_intro",$args,$parent,$i));$buffer.='</p>

';$buffer.=$this->has_themes9($args,$parent,$i);$buffer.='

';$buffer.=$this->has_themes11($args,$parent,$i);$buffer.='
';} return $buffer;}}