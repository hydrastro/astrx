<?php class Templatechat_settings3d3d30e28c7555afc414e938ce91ca81{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="chat-settings">
    <style>
    #chat-settings h3 { margin: 1em 0 .2em; padding-bottom: .15em; border-bottom: 1px solid rgba(128,128,128,.3); }
    #chat-settings .chat-set-id { opacity: .85; margin: .2em 0 .4em; }
    #chat-settings .chat-set-row { margin: .35em 0; }
    #chat-settings .chat-set-row label.lbl { display: inline-block; min-width: 14em; }
    #chat-settings .chat-set-hint { opacity: .65; font-size: 12px; }
    </style>
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("settings_heading",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h2>

    <p class="chat-set-id">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("posting_as_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.=': <strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("posting_as_nick",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</strong>';$buffer.=$this->is_member8($args,$parent,$i);$buffer.='</p>

    <form method="POST" class="chat-settings">
        <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("prg_id",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        <input type="hidden" name="_csrf"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("csrf_token",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">

        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_display",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
        <p class="chat-set-row">
            <label class="lbl" for="chat_refresh_secs">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <input type="number" name="refresh_secs" id="chat_refresh_secs" class="input"
                   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("refresh_secs",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("min_refresh",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" max="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("max_refresh",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" step="1">
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_messages_shown">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_messages_shown",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <input type="number" name="messages_shown" id="chat_messages_shown" class="input"
                   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("messages_shown",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="1" max="200" step="1">
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_sort_dir">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_sort_dir",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <select name="sort_dir" id="chat_sort_dir" class="input">
                <option value=""';$buffer.=$this->sort_default_selected30($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("opt_sort_default",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                <option value="1"';$buffer.=$this->sort_newest_selected34($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("opt_sort_newest",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                <option value="0"';$buffer.=$this->sort_oldest_selected38($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("opt_sort_oldest",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
            </select>
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_timezone">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_timezone",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <select name="timezone" id="chat_timezone" class="input">
                <option value=""';$buffer.=$this->tz_default_selected44($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("opt_tz_default",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->tz_options48($args,$parent,$i);$buffer.='
            </select>
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_font_size">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_size",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <input type="number" name="font_size" id="chat_font_size" class="input"
                   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("font_size",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" min="8" max="28" step="1">
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_font_family">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_font_family",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <select name="font_family" id="chat_font_family" class="input">
                <option value=""';$buffer.=$this->font_default_selected56($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("opt_font_default",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->font_options60($args,$parent,$i);$buffer.='
            </select>
        </p>
        <p class="chat-set-row">
            <label>
                <input type="checkbox" name="show_timestamps" value="1" ';$buffer.=$this->show_timestamps62($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_show_timestamps",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='
            </label>
        </p>

        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_colours",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
        <p class="chat-set-row">
            <label class="lbl" for="chat_text_color">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_text_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <select name="text_color" id="chat_text_color" class="input">
                <option value=""';$buffer.=$this->color_default_selected70($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_default_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                <option value="random">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_random_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->color_options76($args,$parent,$i);$buffer.='
            </select>
            <input type="text" name="text_color_custom" id="chat_text_color_custom" class="input"
                   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("text_color_custom",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="7" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_custom_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" autocomplete="off" spellcheck="false">
        </p>
        <p class="chat-set-row">
            <label class="lbl" for="chat_bg_color">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_bg_color",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</label>
            <select name="bg_color" id="chat_bg_color" class="input">
                <option value=""';$buffer.=$this->bg_default_selected84($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_default_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                <option value="random">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_random_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>
                ';$buffer.=$this->bg_options90($args,$parent,$i);$buffer.='
            </select>
            <input type="text" name="bg_color_custom" id="chat_bg_color_custom" class="input"
                   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("bg_color_custom",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" maxlength="7" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("color_custom_label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" autocomplete="off" spellcheck="false">
        </p>

        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_privacy",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
        <p class="chat-set-row">
            <label>
                <input type="checkbox" name="hide_chatters" value="1" ';$buffer.=$this->hide_chatters98($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_hide_chatters",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='
            </label>
        </p>
        <p class="chat-set-row">
            <label>
                <input type="checkbox" name="incognito" value="1" ';$buffer.=$this->incognito102($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_incognito",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='
            </label>
        </p>
        <p class="chat-set-row">
            <label>
                <input type="checkbox" name="link_conversion" value="1" ';$buffer.=$this->link_conversion106($args,$parent,$i);$buffer.='>
                ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_link_conversion",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='
            </label>
        </p>

        <h3>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("section_notes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</h3>
        <p class="chat-set-row">
            <textarea name="notes" id="chat_notes" rows="4" cols="48" class="input" placeholder="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_notes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("notes",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</textarea>
        </p>

        <p class="chat-set-row">
            <input type="submit" class="input" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label_submit",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='">
        </p>
    </form>
</div>
';return ($buffer) ? $buffer : "";}function is_member8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("is_member",$args,$parent,$i);if(!$resolved){$buffer.=' <em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("guest_tag",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</em>';} return $buffer;}function sort_default_selected30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sort_default_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function sort_newest_selected34($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sort_newest_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function sort_oldest_selected38($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("sort_oldest_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function tz_default_selected44($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("tz_default_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function selected52($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function tz_options48($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("tz_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"';$buffer.=$this->selected52($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function font_default_selected56($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("font_default_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function selected64($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function font_options60($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("font_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"';$buffer.=$this->selected64($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function show_timestamps62($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("show_timestamps",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function color_default_selected70($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("color_default_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function selected82($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function color_options76($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("color_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" style="color:';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"';$buffer.=$this->selected82($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function bg_default_selected84($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("bg_default_selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function selected96($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("selected",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function bg_options90($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("bg_options",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<option value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='" style="color:';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("value",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='"';$buffer.=$this->selected96($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("label",$args,$parent,$i), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');$buffer.='</option>';} return $buffer;}function hide_chatters98($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("hide_chatters",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function incognito102($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("incognito",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}function link_conversion106($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("link_conversion",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='checked';} return $buffer;}}