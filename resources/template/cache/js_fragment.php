<?php class Templatejs_fragment15ab712370142c575b10360038a7966b{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<title>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='</title>
<div id="astrx-js-fragment" data-astrx-js-fragment="1" data-title="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title",$args,$parent,$i));$buffer.='">
    <div id="header">
        <h1 id="title"><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("title_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("website_name",$args,$parent,$i));$buffer.='</a></h1>
    </div>
    <div id="top_nav">
        <ul id="nav" class="nav">';$buffer.=$this->navbar10($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';$buffer.=$this->user_logged_in12($args,$parent,$i);$buffer.='
    ';$buffer.=$this->user_logged_in14($args,$parent,$i);$buffer.='
    ';$buffer.=$this->is_admin16($args,$parent,$i);$buffer.='
    ';$buffer.=$this->has_messages18($args,$parent,$i);$buffer.='
    <div id="main">
        ';$p20Name=$this->TemplateEngine->resolveValue("include",$args,$parent,$i);if(is_string($p20Name)&&$p20Name!==""){$p20=$this->TemplateEngine->loadTemplate($p20Name);if($p20!==null){$buffer.=$p20->render($args,$parent);}}$buffer.='
        ';$buffer.=$this->page_comments23($args,$parent,$i);$buffer.='
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
';return ($buffer) ? $buffer : "";}function highlight14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function navbar10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("navbar",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight14($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function highlight18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function user_nav14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_nav",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight18($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function user_logged_in12($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_logged_in",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="user_top_nav">
        <ul id="user_nav" class="user_nav">
            ';$buffer.=$this->user_nav14($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';} return $buffer;}function user_nav_guest_highlight18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_nav_guest_highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function user_logged_in14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("user_logged_in",$args,$parent,$i);if(!$resolved){$buffer.='
    <div id="user_top_nav">
        <ul id="user_nav" class="user_nav">
            <li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_page_url",$args,$parent,$i));$buffer.='"';$buffer.=$this->user_nav_guest_highlight18($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("user_nav_guest_label",$args,$parent,$i));$buffer.='</a></li>
        </ul>
    </div>
    ';} return $buffer;}function highlight22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("highlight",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="active"';} return $buffer;}function admin_nav18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_nav",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<li><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("url",$args,$parent,$i));$buffer.='"';$buffer.=$this->highlight22($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("name",$args,$parent,$i));$buffer.='</a></li>';} return $buffer;}function is_admin16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_admin",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="admin_top_nav">
        <ul id="admin_nav" class="admin_nav">
            ';$buffer.=$this->admin_nav18($args,$parent,$i);$buffer.='
        </ul>
    </div>
    ';} return $buffer;}function level_label24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("level_label",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='[';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("level_label",$args,$parent,$i));$buffer.='] ';} return $buffer;}function messages20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p class="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("css_class",$args,$parent,$i));$buffer.='">';$buffer.=$this->level_label24($args,$parent,$i);$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("message",$args,$parent,$i));$buffer.='</p>';} return $buffer;}function has_messages18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_messages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div id="message_bar">
        ';$buffer.=$this->messages20($args,$parent,$i);$buffer.='
    </div>
    ';} return $buffer;}function page_comments23($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("page_comments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=$this->TemplateEngine->resolveValue("comments_html",$args,$parent,$i);} return $buffer;}}