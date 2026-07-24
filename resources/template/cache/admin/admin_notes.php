<?php class Templateadmin_admin_notesa1ff78308ac455a8e85e3862ca9b6d62{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("admin_notes_heading",$args,$parent,$i));$buffer.='</h2>
<form method="POST">
    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <p>
        <label for="notes">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_notes",$args,$parent,$i));$buffer.=':</label><br>
        <textarea name="notes" id="notes" rows="20" class="input">';$buffer.=$this->TemplateEngine->resolveValue("notes",$args,$parent,$i);$buffer.='</textarea><br>
        <input type="hidden" name="action" value="save">
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_save",$args,$parent,$i));$buffer.='" class="input">
    </p>
</form>
<form method="POST">
    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i));$buffer.='">
    <input type="hidden" name="action" value="clear">
    <p>
        <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("btn_clear",$args,$parent,$i));$buffer.='" class="input">
    </p>
</form>';return ($buffer) ? $buffer : "";}}