<?php class Templatechat_loginf55d6290af2af0c93c5beda1c0b9f303{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="chat-login">
    <style>
    #chat-login .chat-online-now { margin: .2em 0 .8em; opacity: .9; }
    #chat-login .chat-online-now .chat-member { text-decoration: underline; }
    </style>
    ';$buffer.=$this->chat_disabled2($args,$parent,$i);$buffer.='
    ';$buffer.=$this->chat_disabled4($args,$parent,$i);$buffer.='
</div>
';return ($buffer) ? $buffer : "";}function chat_disabled2($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_disabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<div class="chat-disabled"><p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_disabled_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p></div>';} return $buffer;}function is_member16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_member",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' chat-member';} return $buffer;}function online_users14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("online_users",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<span class="chat-user';$buffer.=$this->is_member16($args,$parent,$i);$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</span>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("sep",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');} return $buffer;}function online_present8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("online_present",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("online_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=' (';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("online_count",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='): ';$buffer.=$this->online_users14($args,$parent,$i);$buffer.='</p>';} return $buffer;}function online_present10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("online_present",$args,$parent,$i);if(!$resolved){$buffer.='<p><em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("online_empty",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</em></p>';} return $buffer;}function has_room_rules12($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_room_rules",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div class="chat-rules">
        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_login_rules_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
        <p class="chat-rules-body">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("room_rules",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>
    </div>
    ';} return $buffer;}function color_options30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("color_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" style="color:';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function allow_color22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("allow_color",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <p>
            <label for="chat_color">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_login_color_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': </label>
            <select name="color" id="chat_color" class="input">
                <option value="">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_default_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                <option value="random">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_random_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->color_options30($args,$parent,$i);$buffer.='
            </select>
            <input type="text" name="color_custom" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_custom_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"
                   maxlength="7" autocomplete="off" spellcheck="false">
        </p>
        ';} return $buffer;}function has_entry_password24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_entry_password",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <p>
            <label for="chat_entry_password">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_entry_password_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': </label>
            <input type="password" name="entry_password" id="chat_entry_password" class="input" autocomplete="off">
        </p>
        ';} return $buffer;}function chat_disabled4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_disabled",$args,$parent,$i);if(!$resolved){$buffer.='
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_login_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>

    <div class="chat-online-now">
        ';$buffer.=$this->online_present8($args,$parent,$i);$buffer.='
        ';$buffer.=$this->online_present10($args,$parent,$i);$buffer.='
    </div>

    ';$buffer.=$this->has_room_rules12($args,$parent,$i);$buffer.='

    <form method="POST" class="chat-login-form">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <p>
            <label for="chat_nick">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_login_nick_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': </label>
            <input type="text" name="nick" id="chat_nick" class="input"
                   maxlength="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick_max",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" autocomplete="off" spellcheck="false">
        </p>
        ';$buffer.=$this->allow_color22($args,$parent,$i);$buffer.='
        ';$buffer.=$this->has_entry_password24($args,$parent,$i);$buffer.='
        ';$p26Name=$this->TemplateEngine->resolveValue("captcha",$args,$parent,$i);if(is_string($p26Name)&&$p26Name!==""){$p26=$this->TemplateEngine->loadTemplate($p26Name);if($p26!==null){$buffer.=$p26->render($args,$parent);}}$buffer.='
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_login_submit",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>
    ';} return $buffer;}}