<?php class Templateemail_password_reset_txtb506c5c272706e072012a6231578344b{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='Password reset for ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='.

You (or someone using your address) asked to reset the password for
your ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=' account. Open the link below to set a new password:

  ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='

If you did not request a password reset, you can safely ignore this
message — your password will not change. The link expires automatically.

-- ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.='
';return ($buffer) ? $buffer : "";}}