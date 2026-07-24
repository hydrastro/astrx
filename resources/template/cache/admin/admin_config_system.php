<?php class Templateadmin_admin_config_system1ef707afbc8f8b969f572071c03a4f29{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_prelude",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="prelude">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_environment",$args,$parent,$i));$buffer.='</th><td>
      <select name="environment" class="input">
        ';$buffer.=$this->cfg_env_options14($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_available_languages",$args,$parent,$i));$buffer.='</th><td><input type="text" name="available_languages" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_available_languages",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_language",$args,$parent,$i));$buffer.='</th><td><input type="text" name="default_language" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_language",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_routing",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="routing">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_url_rewrite",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="url_rewrite" value="1"';$buffer.=$this->cfg_url_rewrite36($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_base_path",$args,$parent,$i));$buffer.='</th><td><input type="text" name="base_path" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_base_path",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_entry_point",$args,$parent,$i));$buffer.='</th><td><input type="text" name="entry_point" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_entry_point",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_locale_key",$args,$parent,$i));$buffer.='</th><td><input type="text" name="locale_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_locale_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_session_key",$args,$parent,$i));$buffer.='</th><td><input type="text" name="session_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_session_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_page_key",$args,$parent,$i));$buffer.='</th><td><input type="text" name="page_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_page_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_page",$args,$parent,$i));$buffer.='</th><td><input type="text" name="default_page" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_page",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_session",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="session">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_use_cookies",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="use_cookies" value="1"';$buffer.=$this->cfg_use_cookies74($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sid_bytes",$args,$parent,$i));$buffer.='</th><td><input type="number" name="sid_bytes" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_sid_bytes",$args,$parent,$i));$buffer.='" min="32" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_session_id_regex",$args,$parent,$i));$buffer.='</th><td><input type="text" name="session_id_regex" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_session_id_regex",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_encrypt",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="encrypt" value="1"';$buffer.=$this->cfg_encrypt86($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_cipher",$args,$parent,$i));$buffer.='</th><td><input type="text" name="cipher" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_cipher",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hmac_algo",$args,$parent,$i));$buffer.='</th><td><input type="text" name="hmac_algo" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_hmac_algo",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_prg_token_key",$args,$parent,$i));$buffer.='</th><td><input type="text" name="prg_token_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_prg_token_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_prg_token_regex",$args,$parent,$i));$buffer.='</th><td><input type="text" name="prg_token_regex" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_prg_token_regex",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_sid_retries",$args,$parent,$i));$buffer.='</th><td><input type="number" name="max_sid_retries" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_sid_retries",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_template",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="template">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_template_dir",$args,$parent,$i));$buffer.='</th><td><input type="text" name="template_dir" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_template_dir",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_template_extension",$args,$parent,$i));$buffer.='</th><td><input type="text" name="template_extension" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_template_extension",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_template_cache_dir",$args,$parent,$i));$buffer.='</th><td><input type="text" name="template_cache_dir" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_template_cache_dir",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_cache_templates",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="cache_templates" value="1"';$buffer.=$this->cfg_cache_templates132($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_php_processing",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="php_processing" value="1"';$buffer.=$this->cfg_php_processing136($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_parse_mode",$args,$parent,$i));$buffer.='</th><td><input type="number" name="parse_mode" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_parse_mode",$args,$parent,$i));$buffer.='" min="0" max="1" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_translator",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="translator">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_lang_dir",$args,$parent,$i));$buffer.='</th><td><input type="text" name="lang_dir" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_lang_dir",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_fallback_to_key",$args,$parent,$i));$buffer.='</th><td><input type="checkbox" name="fallback_to_key" value="1"';$buffer.=$this->cfg_fallback_to_key158($args,$parent,$i);$buffer.='></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_contentmanager",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="contentmanager">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_default_template",$args,$parent,$i));$buffer.='</th><td><input type="text" name="default_template" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_default_template",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_error_page_url_id",$args,$parent,$i));$buffer.='</th><td><input type="text" name="error_page_url_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_error_page_url_id",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_main_page_id",$args,$parent,$i));$buffer.='</th><td><input type="text" name="main_page_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_main_page_id",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_pages_lang_domain",$args,$parent,$i));$buffer.='</th><td><input type="text" name="pages_lang_domain" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_pages_lang_domain",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_navbar_lang_domain",$args,$parent,$i));$buffer.='</th><td><input type="text" name="navbar_lang_domain" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_navbar_lang_domain",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_diagnostics_lang_domain",$args,$parent,$i));$buffer.='</th><td><input type="text" name="diagnostics_lang_domain" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_diagnostics_lang_domain",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_public_navbar_id",$args,$parent,$i));$buffer.='</th><td><input type="number" name="public_navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_public_navbar_id",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_user_navbar_id",$args,$parent,$i));$buffer.='</th><td><input type="number" name="user_navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_user_navbar_id",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_admin_navbar_id",$args,$parent,$i));$buffer.='</th><td><input type="number" name="admin_navbar_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_admin_navbar_id",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_extra_lang_domains",$args,$parent,$i));$buffer.='</th><td><input type="text" name="extra_lang_domains" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_extra_lang_domains",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_status_bar_min_level",$args,$parent,$i));$buffer.='</th><td><input type="number" name="status_bar_min_level" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_status_bar_min_level",$args,$parent,$i));$buffer.='" min="0" max="7" class="input"></td></tr>
    <tr><th colspan="2">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_level_classes",$args,$parent,$i));$buffer.='</th></tr>
    <tr><th>DEBUG</th><td><input type="text" name="level_class_debug"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_debug",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>INFO</th><td><input type="text" name="level_class_info"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_info",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>NOTICE</th><td><input type="text" name="level_class_notice"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_notice",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>WARNING</th><td><input type="text" name="level_class_warning"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_warning",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>ERROR</th><td><input type="text" name="level_class_error"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_error",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>CRITICAL</th><td><input type="text" name="level_class_critical"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_critical",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>ALERT</th><td><input type="text" name="level_class_alert"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_alert",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>EMERGENCY</th><td><input type="text" name="level_class_emergency" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_level_class_emergency",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_news",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="news">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_per_page",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="per_page" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_per_page",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_descending",$args,$parent,$i));$buffer.='</th>
      <td><input type="checkbox" name="descending" value="1"';$buffer.=$this->cfg_descending248($args,$parent,$i);$buffer.='></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_pn_key",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="pn_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_pn_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_show_key",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="show_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_show_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_order_key",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="order_key" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_order_key",$args,$parent,$i));$buffer.='" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_page_window",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="page_window" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_page_window",$args,$parent,$i));$buffer.='" min="1" class="input"></td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>';return ($buffer) ? $buffer : "";}function selected18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function cfg_env_options14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_env_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected18($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function cfg_url_rewrite36($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_url_rewrite",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_use_cookies74($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_use_cookies",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_encrypt86($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_encrypt",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_cache_templates132($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_cache_templates",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_php_processing136($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_php_processing",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_fallback_to_key158($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_fallback_to_key",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_descending248($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_descending",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}}