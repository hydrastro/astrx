<?php class Templateemail_password_reset_htmlc6c01e16ff670c24b01c4472d9a34486{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=' — Reset your password</title>
</head>
<body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#222;background:#fff">
  <h2 style="margin:0 0 16px;font-size:20px">Password reset for ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='</h2>
  <p>You (or someone using your address) asked to reset the password for your ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=' account.</p>
  <p>Click the link below to set a new password:</p>
  <p style="margin:24px 0">
    <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='" style="display:inline-block;padding:10px 20px;background:#2a6ed4;color:#fff;text-decoration:none;border-radius:4px">
      Reset my password
    </a>
  </p>
  <p style="font-size:13px;color:#666">Or paste this URL into your browser:</p>
  <p style="font-size:13px;color:#666;word-break:break-all">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='</p>
  <hr style="border:none;border-top:1px solid #ddd;margin:32px 0">
  <p style="font-size:12px;color:#999">
    If you did not request a password reset, you can safely ignore this email
    — your password will not change. The link expires automatically.
  </p>
</body>
</html>
';return ($buffer) ? $buffer : "";}}