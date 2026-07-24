<?php class Templateemail_verify_account_txt1641cd74a5683ccc4a6974e413cd1164{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='Welcome to ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=', ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='.

Thanks for registering. To finish setting up your account, open the link below:

  ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='

If you did not create an account at ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=', you can safely ignore
this message.

-- ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.='
';return ($buffer) ? $buffer : "";}}