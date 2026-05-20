<?php class Templateadmin_admin_config_captchaffbca8e710a66a16ff354e2cc4a2c561{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("heading",$args,$parent,$i));$buffer.='</h2>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_preview",$args,$parent,$i));$buffer.='</h3>
<table>
  <tr>
    <th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("preview_text",$args,$parent,$i));$buffer.='</th>
    <td><img src="data:image/gif;base64,';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("preview_image",$args,$parent,$i));$buffer.='" alt="captcha preview"></td>
    <!-- TODO refresh captcha btn + captcha in a frame -->
  </tr>
</table>
<hr>

';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_service",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="service">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_captcha_expiration",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="captcha_expiration" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_captcha_expiration",$args,$parent,$i));$buffer.='" min="60" class="input"> s</td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

';$buffer.='
';$buffer.='
<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_context_difficulty",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="context_difficulty">
  <table>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_login_captcha_difficulty",$args,$parent,$i));$buffer.='</th><td>
      <select name="login_captcha_difficulty" class="input">
        ';$buffer.=$this->login_captcha_difficulty_options38($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_register_captcha_difficulty",$args,$parent,$i));$buffer.='</th><td>
      <select name="register_captcha_difficulty" class="input">
        ';$buffer.=$this->register_captcha_difficulty_options42($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_recover_captcha_difficulty",$args,$parent,$i));$buffer.='</th><td>
      <select name="recover_captcha_difficulty" class="input">
        ';$buffer.=$this->recover_captcha_difficulty_options46($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_comment_captcha_difficulty",$args,$parent,$i));$buffer.='</th><td>
      <select name="comment_captcha_difficulty" class="input">
        ';$buffer.=$this->comment_captcha_difficulty_options50($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><td colspan="2"><input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input"></td></tr>
  </table>
</form>
<hr>

<h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_renderer",$args,$parent,$i));$buffer.='</h3>
<form method="POST">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="renderer">
  <table>

    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_captcha_type",$args,$parent,$i));$buffer.='</th><td>
      <select name="captcha_type" class="input">
        ';$buffer.=$this->type_options62($args,$parent,$i);$buffer.='
      </select>
    </td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_captcha_length",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="captcha_length" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_captcha_length",$args,$parent,$i));$buffer.='" min="1" max="20" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_char_list",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="char_list" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_char_list",$args,$parent,$i));$buffer.='" style="width:30em" class="input"></td></tr>

    <tr><th colspan="2">Canvas</th></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_image_width",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="image_width" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_image_width",$args,$parent,$i));$buffer.='" min="1" class="input"> <small>(1 = auto)</small></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_image_height",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="image_height" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_image_height",$args,$parent,$i));$buffer.='" min="1" class="input"> <small>(1 = auto)</small></td></tr>

    <tr><th colspan="2">Colors</th></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_background_color",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="background_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_background_color",$args,$parent,$i));$buffer.='" maxlength="6" style="width:6em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_text_color",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="text_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_text_color",$args,$parent,$i));$buffer.='" maxlength="6" style="width:6em" class="input">
        <input type="checkbox" name="text_color_random" value="1"';$buffer.=$this->cfg_text_color_random88($args,$parent,$i);$buffer.=' class="input">
        ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_text_color_random",$args,$parent,$i));$buffer.='</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_lines_color",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="lines_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_lines_color",$args,$parent,$i));$buffer.='" maxlength="6" style="width:6em" class="input">
        <input type="checkbox" name="lines_color_random" value="1"';$buffer.=$this->cfg_lines_color_random96($args,$parent,$i);$buffer.=' class="input">
        ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_lines_color_random",$args,$parent,$i));$buffer.='</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_dots_color",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="dots_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_dots_color",$args,$parent,$i));$buffer.='" maxlength="6" style="width:6em" class="input">
        <input type="checkbox" name="dots_color_random" value="1"';$buffer.=$this->cfg_dots_color_random104($args,$parent,$i);$buffer.=' class="input">
        ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_dots_color_random",$args,$parent,$i));$buffer.='</td></tr>

    <tr><th colspan="2">Noise</th></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_lines_start_from_border",$args,$parent,$i));$buffer.='</th>
      <td><input type="checkbox" name="lines_start_from_border" value="1"';$buffer.=$this->cfg_lines_start_from_border110($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_lines_number",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="lines_number" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_lines_number",$args,$parent,$i));$buffer.='" min="0" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_dots_number",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="dots_number" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_dots_number",$args,$parent,$i));$buffer.='" min="0" class="input"></td></tr>

    <tr><th colspan="2">Font</th></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_file",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="font_file" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_file",$args,$parent,$i));$buffer.='" style="width:30em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_size",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="font_size" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_size",$args,$parent,$i));$buffer.='" min="8" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_min_angle",$args,$parent,$i));$buffer.=' / ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_max_angle",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="font_min_angle" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_min_angle",$args,$parent,$i));$buffer.='" class="input">
        / <input type="number" name="font_max_angle" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_max_angle",$args,$parent,$i));$buffer.='" class="input"> °</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_min_distance",$args,$parent,$i));$buffer.=' / ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_max_distance",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="font_min_distance" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_min_distance",$args,$parent,$i));$buffer.='" min="0" class="input">
        / <input type="number" name="font_max_distance" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_max_distance",$args,$parent,$i));$buffer.='" min="0" class="input"> px</td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_x_border",$args,$parent,$i));$buffer.=' / ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_y_border",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="font_x_border" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_x_border",$args,$parent,$i));$buffer.='" min="0" class="input">
        / <input type="number" name="font_y_border" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_y_border",$args,$parent,$i));$buffer.='" min="0" class="input"> px</td></tr>

    <tr><th colspan="2">HARD mode</th></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_trace_line_color",$args,$parent,$i));$buffer.='</th>
      <td><input type="text" name="trace_line_color" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_trace_line_color",$args,$parent,$i));$buffer.='" maxlength="6" style="width:6em" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_non_captcha_char_number",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="non_captcha_char_number" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_non_captcha_char_number",$args,$parent,$i));$buffer.='" min="0" class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_use_border_linear_randomness",$args,$parent,$i));$buffer.='</th>
      <td><input type="checkbox" name="use_border_linear_randomness" value="1"';$buffer.=$this->cfg_use_border_linear_randomness162($args,$parent,$i);$buffer.=' class="input"></td></tr>
    <tr><th>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_max_rounds_number",$args,$parent,$i));$buffer.='</th>
      <td><input type="number" name="max_rounds_number" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_rounds_number",$args,$parent,$i));$buffer.='" min="100" class="input"></td></tr>

    <tr><td colspan="2">
      <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input">
    </td></tr>
  </table>
</form>

';$buffer.='
<form method="POST" id="preview-form" style="display:inline">
  <input type="hidden" name="prg_id"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="section" value="preview">
  <input type="hidden" name="captcha_type"               value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_captcha_type",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="captcha_length"             value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_captcha_length",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="char_list"                  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_char_list",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="image_width"                value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_image_width",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="image_height"               value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_image_height",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="background_color"           value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_background_color",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="text_color"                 value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_text_color",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="lines_color"                value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_lines_color",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="dots_color"                 value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_dots_color",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="lines_number"               value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_lines_number",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="dots_number"                value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_dots_number",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_file"                  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_file",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_size"                  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_size",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_min_angle"             value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_min_angle",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_max_angle"             value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_max_angle",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_min_distance"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_min_distance",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_max_distance"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_max_distance",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_x_border"              value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_x_border",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="font_y_border"              value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_font_y_border",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="trace_line_color"           value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_trace_line_color",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="non_captcha_char_number"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_non_captcha_char_number",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="max_rounds_number"          value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cfg_max_rounds_number",$args,$parent,$i));$buffer.='">
  <input type="hidden" name="text_color_random"          value="';$buffer.=$this->cfg_text_color_random220($args,$parent,$i);$buffer.='">
  <input type="hidden" name="lines_color_random"         value="';$buffer.=$this->cfg_lines_color_random222($args,$parent,$i);$buffer.='">
  <input type="hidden" name="dots_color_random"          value="';$buffer.=$this->cfg_dots_color_random224($args,$parent,$i);$buffer.='">
  <input type="hidden" name="lines_start_from_border"    value="';$buffer.=$this->cfg_lines_start_from_border226($args,$parent,$i);$buffer.='">
  <input type="hidden" name="use_border_linear_randomness" value="';$buffer.=$this->cfg_use_border_linear_randomness228($args,$parent,$i);$buffer.='">
  <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_preview",$args,$parent,$i));$buffer.='" class="input">
</form>';return ($buffer) ? $buffer : "";}function selected42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function login_captcha_difficulty_options38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("login_captcha_difficulty_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected42($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function register_captcha_difficulty_options42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("register_captcha_difficulty_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected46($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function recover_captcha_difficulty_options46($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("recover_captcha_difficulty_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected50($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected54($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function comment_captcha_difficulty_options50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comment_captcha_difficulty_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected54($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function selected66($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function type_options62($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("type_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i));$buffer.='"';$buffer.=$this->selected66($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i));$buffer.='</option>';} return $buffer;}function cfg_text_color_random88($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_text_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_lines_color_random96($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_lines_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_dots_color_random104($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_dots_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_lines_start_from_border110($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_lines_start_from_border",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_use_border_linear_randomness162($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_use_border_linear_randomness",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' checked';} return $buffer;}function cfg_text_color_random220($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_text_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='1';} return $buffer;}function cfg_lines_color_random222($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_lines_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='1';} return $buffer;}function cfg_dots_color_random224($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_dots_color_random",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='1';} return $buffer;}function cfg_lines_start_from_border226($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_lines_start_from_border",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='1';} return $buffer;}function cfg_use_border_linear_randomness228($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("cfg_use_border_linear_randomness",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='1';} return $buffer;}}