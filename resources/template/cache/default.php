<?php class Templatedefaultc93c1312b01484ce1aa6661188df3886{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<!DOCTYPE html>
<html lang="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("lang",$args,$parent,$i));$buffer.='">
<head>
    <!--
         _        _      __  __
        / \\   ___| |_ _ _\\ \\/ /
       / _ \\ / __| __| \'__\\  /
      / ___ \\\\__ \\ |_| |  /  \\
     /_/   \\_\\___/\\__|_| /_/\\_\\

	Copyright (c) ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("year",$args,$parent,$i));$buffer.='-->
    <title>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="author" content="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("website_name",$args,$parent,$i));$buffer.='">
    <meta name="dcterms.dateCopyrighted" content="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("year",$args,$parent,$i));$buffer.='">
    <meta name="dcterms.rights" content="All Rights Reserved.">
    <meta name="dcterms.rightsHolder" content="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("website_name",$args,$parent,$i));$buffer.='">
    <meta name="description" content="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("description",$args,$parent,$i));$buffer.='">
    <meta name="keywords" content="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("keywords",$args,$parent,$i));$buffer.='">
    <meta name="robots"
          content="';$buffer.=$this->index18($args,$parent,$i);$buffer.='index, ';$buffer.=$this->follow20($args,$parent,$i);$buffer.='follow">
    <meta name="viewport"
          content="width=device-width, initial-scale=1, maximum-scale=1;">
    <link rel="icon" type="icon/ico" href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("icon",$args,$parent,$i));$buffer.='">
    <style>';$buffer.=$this->TemplateEngine->resolveValue("css",$args,$parent,$i);$buffer.='</style>
</head>
<body>
<div id="wrap">
    <div id="header">
        <h1 id="title"><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("website_name",$args,$parent,$i));$buffer.='</a></h1>
    </div>
    <div id="top_nav">
        <ul id="nav" class="nav">';$buffer.=$this->navbar30($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';$buffer.=$this->user_logged_in32($args,$parent,$i);$buffer.='
    ';$buffer.=$this->user_logged_in34($args,$parent,$i);$buffer.='
    ';$buffer.=$this->is_admin36($args,$parent,$i);$buffer.='
    ';$buffer.=$this->has_messages38($args,$parent,$i);$buffer.='
    <div id="main">
        ';$p40Name=$this->TemplateEngine->resolveValue("include",$args,$parent,$i);if(is_string($p40Name)&&$p40Name!==""){$p40=$this->TemplateEngine->loadTemplate($p40Name);if($p40!==null){$buffer.=$p40->render($args,$parent);}}$buffer.='
        ';$buffer.=$this->page_comments43($args,$parent,$i);$buffer.='
        <p id="go_top">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("generated_in",$args,$parent,$i));$buffer.=' ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("time",$args,$parent,$i));$buffer.='s<span class="right"><a
                href="#">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("go_top",$args,$parent,$i));$buffer.='</a></span></p>
    </div>
    <div id="footer">
        <p class="left"><a href="">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("ip",$args,$parent,$i));$buffer.='</a></p>
        <p class="right">Copyright &copy; ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("year",$args,$parent,$i));$buffer.=' - <a
                href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("cur",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("website_name",$args,$parent,$i));$buffer.='</a></p>
        <div class="clear"></div>
    </div>
</div>
</body>
</html>';return ($buffer) ? $buffer : "";}function index18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("index",$args,$parent,$i);if(!$resolved){$buffer.='no';} return $buffer;}function follow20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("follow",$args,$parent,$i);if(!$resolved){$buffer.='no';} return $buffer;}function highlight34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function navbar30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("navbar",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight34($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function highlight38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function user_nav34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_nav",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight38($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function user_logged_in32($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_logged_in",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="user_top_nav">
        <ul id="user_nav" class="user_nav">
            ';$buffer.=$this->user_nav34($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';} return $buffer;}function user_nav_guest_highlight38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_nav_guest_highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function user_logged_in34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_logged_in",$args,$parent,$i);if(!$resolved){$buffer.='
    <div id="user_top_nav">
        <ul id="user_nav" class="user_nav">
            <li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_page_url",$args,$parent,$i));$buffer.='"';$buffer.=$this->user_nav_guest_highlight38($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_nav_guest_label",$args,$parent,$i));$buffer.='</a></li>
        </ul>
    </div>
    ';} return $buffer;}function highlight42($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function admin_nav38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_nav",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight42($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function is_admin36($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_admin",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="admin_top_nav">
        <ul id="admin_nav" class="admin_nav">
            ';$buffer.=$this->admin_nav38($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';} return $buffer;}function level_label44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("level_label",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='[';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("level_label",$args,$parent,$i));$buffer.='] ';} return $buffer;}function messages40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p class="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("css_class",$args,$parent,$i));$buffer.='">';$buffer.=$this->level_label44($args,$parent,$i);$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='</p>';} return $buffer;}function has_messages38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="message_bar">
        ';$buffer.=$this->messages40($args,$parent,$i);$buffer.='
    </div>
    ';} return $buffer;}function page_comments43($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("page_comments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=$this->TemplateEngine->resolveValue("comments_html",$args,$parent,$i);} return $buffer;}}