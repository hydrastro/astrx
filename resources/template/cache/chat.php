<?php class Templatechat7b976996afb7d11fbe14f43754917daf{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="chat"';$buffer.=$this->chat_alt_layout2($args,$parent,$i);$buffer.='>
    <style>';$buffer.=$this->TemplateEngine->resolveValue("chat_css",$args,$parent,$i);$buffer.='</style>

    ';$buffer.=$this->chat_disabled6($args,$parent,$i);$buffer.='
    ';$buffer.=$this->chat_disabled8($args,$parent,$i);$buffer.='
</div>
';return ($buffer) ? $buffer : "";}function chat_alt_layout2($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_alt_layout",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' class="chat-alt"';} return $buffer;}function chat_disabled6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_disabled",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<div class="chat-disabled"><p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_disabled_message",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p></div>';} return $buffer;}function room_topic_present12($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("room_topic_present",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<p class="chat-topic">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("room_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</p>';} return $buffer;}function hide_chatters20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hide_chatters",$args,$parent,$i);if(!$resolved){$buffer.='<a class="input" href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("users_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" target="astrx_chat_users">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_reload_online",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a>';} return $buffer;}function can_post22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_post",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a class="input" href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("self_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" target="_top">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_reload_postbox",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a>';} return $buffer;}function nav_show_reload14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("nav_show_reload",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <a class="input" href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("stream_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" target="astrx_chat_stream">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_reload_messages",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</a>
        ';$buffer.=$this->hide_chatters20($args,$parent,$i);$buffer.='
        ';$buffer.=$this->can_post22($args,$parent,$i);$buffer.='
        ';} return $buffer;}function can_post16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_post",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="postbox">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_postbox_toggle_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
        ';} return $buffer;}function nav_show_rearrange18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("nav_show_rearrange",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="rearrange">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_rearrange",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
        ';} return $buffer;}function is_mod20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_mod",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="clean">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_clean_room",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
        ';} return $buffer;}function has_unread_pm28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("has_unread_pm",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<span class="chat-pm-badge">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_unread_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("unread_pm",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</span>';} return $buffer;}function is_mod30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_mod",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div class="chat-mod-tools">
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="topic">
            <input type="text" name="topic" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("room_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_topic_ph",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="255" autocomplete="off">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_set_topic",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="clean_nick">
            <input type="text" name="nick" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_clean_nick_ph",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="64" autocomplete="off">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_clean_nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
    </div>
    ';} return $buffer;}function hide_chatters36($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hide_chatters",$args,$parent,$i);if(!$resolved){$buffer.='<iframe class="chat-users" name="astrx_chat_users" src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("users_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" title="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_users_title",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"></iframe>';} return $buffer;}function uploads_ok40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("uploads_ok",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' enctype="multipart/form-data"';} return $buffer;}function is_member50($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_member",$args,$parent,$i);if(!$resolved){$buffer.=' <em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_guest_tag",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</em>';} return $buffer;}function pm_recipients56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("pm_recipients",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function postbox_multiline58($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("postbox_multiline",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <textarea name="content" id="chat_content" rows="3" class="input chat-postbox" maxlength="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_length",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_placeholder",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" aria-label="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_placeholder",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" autocomplete="off"></textarea><br>
        ';} return $buffer;}function postbox_multiline60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("postbox_multiline",$args,$parent,$i);if(!$resolved){$buffer.='
        <input type="text" name="content" id="chat_content" class="input chat-postbox" maxlength="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_length",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_placeholder",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" aria-label="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_placeholder",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" autocomplete="off">
        ';} return $buffer;}function uploads_ok62($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("uploads_ok",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <p class="chat-upload"><label for="chat_attach">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_attach_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <input type="file" name="attachment" id="chat_attach" class="input" accept="image/*"></p>
        ';} return $buffer;}function can_post38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_post",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <form method="POST" class="chat-post"';$buffer.=$this->uploads_ok40($args,$parent,$i);$buffer.='>
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="action" value="post">
        <p class="chat-meta"><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_posting_as",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("my_nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</strong>';$buffer.=$this->is_member50($args,$parent,$i);$buffer.='</small></p>
        <p class="chat-send-to">
            <label for="chat_to">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_send_to",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=':</label>
            <select name="to" id="chat_to" class="input">
                <option value="">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_to_everyone",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->pm_recipients56($args,$parent,$i);$buffer.='
            </select>
        </p>
        ';$buffer.=$this->postbox_multiline58($args,$parent,$i);$buffer.='
        ';$buffer.=$this->postbox_multiline60($args,$parent,$i);$buffer.='
        ';$buffer.=$this->uploads_ok62($args,$parent,$i);$buffer.='
        <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_send",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
    </form>
    <p class="chat-hint"><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_formatting_hint",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</small></p>
    ';} return $buffer;}function can_post40($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("can_post",$args,$parent,$i);if(!$resolved){$buffer.='<p class="chat-hint"><small>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_cannot_post",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</small></p>';} return $buffer;}function chat_disabled8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("chat_disabled",$args,$parent,$i);if(!$resolved){$buffer.='
    <div class="chat-header">
        <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>
        ';$buffer.=$this->room_topic_present12($args,$parent,$i);$buffer.='
    </div>

    <div class="chat-controls">
        ';$buffer.=$this->nav_show_reload14($args,$parent,$i);$buffer.='
        ';$buffer.=$this->can_post16($args,$parent,$i);$buffer.='
        ';$buffer.=$this->nav_show_rearrange18($args,$parent,$i);$buffer.='
        ';$buffer.=$this->is_mod20($args,$parent,$i);$buffer.='
        <form method="POST" class="chat-inline">
            <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
            <input type="hidden" name="action" value="leave">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_leave",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </form>
        ';$buffer.=$this->has_unread_pm28($args,$parent,$i);$buffer.='
    </div>

    ';$buffer.=$this->is_mod30($args,$parent,$i);$buffer.='

    <div class="chat-panes">
        <iframe class="chat-stream" name="astrx_chat_stream" src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("stream_url",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" title="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("chat_messages_title",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"></iframe>
        ';$buffer.=$this->hide_chatters36($args,$parent,$i);$buffer.='
    </div>

    ';$buffer.=$this->can_post38($args,$parent,$i);$buffer.='

    ';$buffer.=$this->can_post40($args,$parent,$i);$buffer.='
    ';} return $buffer;}}