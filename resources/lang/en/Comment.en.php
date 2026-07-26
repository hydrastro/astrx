<?php
declare(strict_types=1);

return [
    // ── Headings ─────────────────────────────────────────────────────────────
    'comment.heading'        => 'Comments',
    'comment.none'           => 'No comments yet. Be the first!',
    'comment.submit_heading' => 'Leave a comment',
    'comment.anonymous'      => 'Anonymous',

    // ── Form labels ──────────────────────────────────────────────────────────
    'comment.label.name'         => 'Name',
    'comment.label.email'        => 'Email',
    'comment.label.content'      => 'Comment',
    'comment.label.reply'        => 'Replying to',
    'comment.label.captcha'      => 'Enter the code shown',
    'comment.label.show'         => 'Show',
    'comment.label.order'        => 'Ordered by date',
    'comment.label.order_asc'    => 'Ascending',
    'comment.label.order_desc'   => 'Descending',
    'comment.label.indent'       => 'Grouping',
    'comment.label.indent_nest'  => 'Nested',
    'comment.label.indent_flat'  => 'Flat',

    // ── Buttons ───────────────────────────────────────────────────────────────
    'comment.btn.submit'        => 'Post comment',
    'comment.btn.filter'        => 'Apply',
    'comment.btn.reply'         => 'Reply',
    'comment.btn.cancel_reply'  => 'Cancel reply',
    'comment.btn.hide'          => 'Hide',
    'comment.btn.unhide'        => 'Show',
    'comment.btn.delete'        => 'Delete',

    // ── Words ─────────────────────────────────────────────────────────────────
    'comment.word.older'  => 'Comments:',
    'comment.word.first' => '<<',
    'comment.word.last'  => '>>',
    'comment.word.prev'  => '<',
    'comment.word.next'  => '>',

    // ── Post feedback (surfaced via a flash message after the PRG redirect) ────
    'comment.posted'                 => 'Comment posted.',
    'comment.error.csrf'             => 'Your session expired. Please try again.',
    'comment.error.captcha'          => 'The captcha was incorrect. Please try again.',
    'comment.error.generic'          => 'Your comment could not be posted.',
    'comment.error.flood'            => 'You are posting too fast — please wait a moment and try again.',
    'comment.error.antispam'         => 'Your comment was blocked by a spam filter.',
    'comment.error.muted'            => 'You are currently muted and cannot post.',
    'comment.error.not_allowed'      => 'You are not allowed to post comments here.',
    'comment.error.empty'            => 'Your comment is empty.',
    'comment.error.invalid_email'    => 'A valid email address is required.',
    'comment.error.reply_not_found'  => 'The comment you are replying to no longer exists.',
    'comment.error.reply_wrong_page' => 'That reply does not belong to this page.',
    'comment.error.gate_denied'      => 'You are not allowed to do that.',
    'comment.error.not_found'        => 'Comment not found.',

    // ── Antispam messages — pick one as a rule message in Admin → Comments ─────
    'comment.antispam.blocked'        => 'Your comment looks like spam and was blocked.',
    'comment.antispam.too_many_lines' => 'Your comment has too many line breaks.',
    'comment.antispam.too_long'       => 'Your comment is too long.',
    'comment.antispam.no_links'       => 'Links are not allowed in comments.',
];
