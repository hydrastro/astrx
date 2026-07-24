<?php class Templateemail_verify_account_html54d978d062662540f4cda4c6f2de230e{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=' — Verify your account</title>
</head>
<body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#222;background:#fff">
  <h2 style="margin:0 0 16px;font-size:20px">Welcome to ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=', ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("username",$args,$parent,$i));$buffer.='</h2>
  <p>Thanks for registering. To finish setting up your account, click the link below:</p>
  <p style="margin:24px 0">
    <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='" style="display:inline-block;padding:10px 20px;background:#2a6ed4;color:#fff;text-decoration:none;border-radius:4px">
      Verify my account
    </a>
  </p>
  <p style="font-size:13px;color:#666">Or paste this URL into your browser:</p>
  <p style="font-size:13px;color:#666;word-break:break-all">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("link",$args,$parent,$i));$buffer.='</p>
  <hr style="border:none;border-top:1px solid #ddd;margin:32px 0">
  <p style="font-size:12px;color:#999">
    If you did not create an account at ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("site_name",$args,$parent,$i));$buffer.=', you can safely ignore this email.
  </p>
</body>
</html>
';return ($buffer) ? $buffer : "";}}