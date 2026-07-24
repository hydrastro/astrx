<?php class Templatepartials_comments6e592040bc83cdcf1a7bde541dafed51{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){$buffer="";$i=0;$buffer.='<div id="comments_area">
    <hr>
    <h2>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_heading",$args,$parent,$i));$buffer.='</h2>

    ';$buffer.=$this->comments_any4($args,$parent,$i);$buffer.='

    ';$buffer.=$this->comments_any6($args,$parent,$i);$buffer.='

    ';$buffer.=$this->comments8($args,$parent,$i);$buffer.='

        ';$buffer.=$this->comments_has_pagination10($args,$parent,$i);$buffer.='

        <hr>
        <h3 id="comment_form">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_submit_heading",$args,$parent,$i));$buffer.='</h3>
        <form method="POST">
            <input type="hidden" name="prg_id"    value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_prg_id",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_csrf"     value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_csrf",$args,$parent,$i));$buffer.='">
            <input type="hidden" name="_comment"  value="1">
            <input type="hidden" name="reply_to"  value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_reply_to",$args,$parent,$i));$buffer.='">
            <p>
                ';$buffer.=$this->comments_reply_to20($args,$parent,$i);$buffer.='
                ';$buffer.=$this->comments_logged_in22($args,$parent,$i);$buffer.='
                <label for="comment_content">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_content",$args,$parent,$i));$buffer.=': </label><br>
                <textarea name="content" id="comment_content" rows="10" class="input"></textarea><br>
                ';$p26Name=$this->TemplateEngine->resolveValue("captcha",$args,$parent,$i);if(is_string($p26Name)&&$p26Name!==""){$p26=$this->TemplateEngine->loadTemplate($p26Name);if($p26!==null){$buffer.=$p26->render($args,$parent);}}$buffer.='
                <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_submit",$args,$parent,$i));$buffer.='" class="input">
            </p>
        </form>
    </div>';return ($buffer) ? $buffer : "";}function comments_order_asc16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_order_asc",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function comments_order_desc20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_order_desc",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function comments_indent_on26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_indent_on",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function comments_indent_off30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_indent_off",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' selected';} return $buffer;}function comments_any4($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_any",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <form method="GET" action="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_filter_action",$args,$parent,$i));$buffer.='">
        ';$buffer.=$this->TemplateEngine->resolveValue("comments_base_query_inputs",$args,$parent,$i);$buffer.='
        <p>
            <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_show",$args,$parent,$i));$buffer.=': <input type="number" name="cs" class="input" size="4" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_per_page_val",$args,$parent,$i));$buffer.='" min="0"></label>
            <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_order",$args,$parent,$i));$buffer.=':
                <select name="co" class="input">
                    <option value="asc"';$buffer.=$this->comments_order_asc16($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_order_asc",$args,$parent,$i));$buffer.='</option>
                    <option value="desc"';$buffer.=$this->comments_order_desc20($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_order_desc",$args,$parent,$i));$buffer.='</option>
                </select>
            </label>
            <label>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_indent",$args,$parent,$i));$buffer.=':
                <select name="ci" class="input">
                    <option value="1"';$buffer.=$this->comments_indent_on26($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_indent_nest",$args,$parent,$i));$buffer.='</option>
                    <option value="0"';$buffer.=$this->comments_indent_off30($args,$parent,$i);$buffer.='>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_indent_flat",$args,$parent,$i));$buffer.='</option>
                </select>
            </label>
            <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_filter",$args,$parent,$i));$buffer.='" class="input">
        </p>
    </form>
    ';} return $buffer;}function comments_any6($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_any",$args,$parent,$i);if(!$resolved){$buffer.='
    <p>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_none",$args,$parent,$i));$buffer.='</p>
    ';} return $buffer;}function avatar_profile_section14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("avatar_profile_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_url",$args,$parent,$i));$buffer.='"><img src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("avatar_src",$args,$parent,$i));$buffer.='" alt="avatar" style="width:75px;height:75px;float:left;margin-right:10px;"></a>
            ';} return $buffer;}function avatar_plain_section16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("avatar_plain_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <img src="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("avatar_src",$args,$parent,$i));$buffer.='" alt="avatar" style="width:75px;height:75px;float:left;margin-right:10px;">
            ';} return $buffer;}function name_profile_section18($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("name_profile_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <p style="margin:0;float:left"><strong><a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("profile_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display_name",$args,$parent,$i));$buffer.='</a></strong></p>
            ';} return $buffer;}function name_plain_section20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("name_plain_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <p style="margin:0;float:left"><strong>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("display_name",$args,$parent,$i));$buffer.='</strong></p>
            ';} return $buffer;}function reply_section22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("reply_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reply_url",$args,$parent,$i));$buffer.='" class="input" style="display:inline-block">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_reply",$args,$parent,$i));$buffer.='</a>
                ';} return $buffer;}function admin_hide_section24($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_hide_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <form method="POST" style="display:inline">
                    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_csrf",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_comment" value="1">
                    <input type="hidden" name="id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"  value="hide">
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_hide",$args,$parent,$i));$buffer.='" class="input">
                </form>
                ';} return $buffer;}function admin_unhide_section26($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_unhide_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <form method="POST" style="display:inline">
                    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_csrf",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_comment" value="1">
                    <input type="hidden" name="id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"  value="unhide">
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_unhide",$args,$parent,$i));$buffer.='" class="input">
                </form>
                ';} return $buffer;}function admin_delete_section28($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("admin_delete_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <form method="POST" style="display:inline">
                    <input type="hidden" name="prg_id" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_prg_id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_csrf"   value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_csrf",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="_comment" value="1">
                    <input type="hidden" name="id"      value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='">
                    <input type="hidden" name="action"  value="delete">
                    <input type="submit" value="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_delete",$args,$parent,$i));$buffer.='" class="input">
                </form>
                ';} return $buffer;}function reply_to_section30($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("reply_to_section",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
            <small style="font-size:10px">(&#8617; #';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("reply_to",$args,$parent,$i));$buffer.=')</small>
            ';} return $buffer;}function comments8($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
    <div class="comment" id="comment-';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='" style="border:1px solid white;padding:10px 10px 0 10px;height:auto;overflow:auto;margin-bottom:10px;';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("row_opacity",$args,$parent,$i));$buffer.='">
        <div style="overflow:auto;margin-bottom:10px">

            ';$buffer.=$this->avatar_profile_section14($args,$parent,$i);$buffer.='

            ';$buffer.=$this->avatar_plain_section16($args,$parent,$i);$buffer.='

            ';$buffer.=$this->name_profile_section18($args,$parent,$i);$buffer.='

            ';$buffer.=$this->name_plain_section20($args,$parent,$i);$buffer.='

            <div class="right">
                ';$buffer.=$this->reply_section22($args,$parent,$i);$buffer.='
                ';$buffer.=$this->admin_hide_section24($args,$parent,$i);$buffer.='
                ';$buffer.=$this->admin_unhide_section26($args,$parent,$i);$buffer.='
                ';$buffer.=$this->admin_delete_section28($args,$parent,$i);$buffer.='
            </div>

            <br>

            ';$buffer.=$this->reply_to_section30($args,$parent,$i);$buffer.='

            <blockquote>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("content",$args,$parent,$i));$buffer.='</blockquote>

            <p><small>
                <span class="left">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("created_at",$args,$parent,$i));$buffer.='</span>
                <span class="right">ID: ';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("id",$args,$parent,$i));$buffer.='</span>
            </small></p>
            <div class="clear"></div>
        </div>
        ';$buffer.=$this->TemplateEngine->resolveValue("close_divs_html",$args,$parent,$i);$buffer.='
        ';} return $buffer;}function comments_has_first12($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_has_first",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_first_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_word_first",$args,$parent,$i));$buffer.='</a> ';} return $buffer;}function comments_has_prev13($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_has_prev",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='<a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_prev_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_word_prev",$args,$parent,$i));$buffer.='</a> ';} return $buffer;}function url17($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("url",$args,$parent,$i);if(!$resolved){$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("number",$args,$parent,$i));} return $buffer;}function comments_pages14($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_pages",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' ';$buffer.=$this->TemplateEngine->resolveValue("link",$args,$parent,$i);$buffer.=$this->url17($args,$parent,$i);} return $buffer;}function comments_has_next15($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_has_next",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_next_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_word_next",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function comments_has_last16($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_has_last",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.=' <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_last_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_word_last",$args,$parent,$i));$buffer.='</a>';} return $buffer;}function comments_has_pagination10($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_has_pagination",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
        <p class="comments_nav"><small>';$buffer.=$this->comments_has_first12($args,$parent,$i);$buffer.=$this->comments_has_prev13($args,$parent,$i);$buffer.=$this->comments_pages14($args,$parent,$i);$buffer.=$this->comments_has_next15($args,$parent,$i);$buffer.=$this->comments_has_last16($args,$parent,$i);$buffer.='</small></p>
        ';} return $buffer;}function comments_reply_to20($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_reply_to",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){$buffer.='
                <em>';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_reply",$args,$parent,$i));$buffer.=': #';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_reply_to",$args,$parent,$i));$buffer.='</em>
                &mdash; <a href="';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comments_cancel_reply_url",$args,$parent,$i));$buffer.='">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_btn_cancel_reply",$args,$parent,$i));$buffer.='</a><br>
                ';} return $buffer;}function comments_logged_in22($args,$parent,$i){$buffer="";$resolved=$this->TemplateEngine->resolveValue("comments_logged_in",$args,$parent,$i);if(!$resolved){$buffer.='
                <label for="comment_name">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_name",$args,$parent,$i));$buffer.=': </label>
                <input type="text" name="name" id="comment_name" class="input"><br>
                <label for="comment_email">';$buffer.=htmlspecialchars((string)$this->TemplateEngine->resolveValue("comment_label_email",$args,$parent,$i));$buffer.=': </label>
                <input type="email" name="email" id="comment_email" class="input"><br>
                ';} return $buffer;}}