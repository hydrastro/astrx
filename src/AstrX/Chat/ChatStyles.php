<?php
declare(strict_types=1);

namespace AstrX\Chat;

/**
 * The chat's CSS, in one place, so the shell and the auto-refreshing iframe
 * panes look identical and stay in sync.
 *
 * Everything here is THEME-AWARE: it never hard-codes a text or background
 * colour. Colours come from whatever theme is active — the frames inject the
 * theme stylesheet and wrap their body in the theme's own `#wrap`/`#main`
 * containers, and the shell already lives inside `#main`. Muted elements use
 * `opacity`, and separators/borders use a neutral translucent grey that reads
 * correctly on both dark and light themes. This is why chat text is now legible
 * on every theme instead of black-on-black.
 *
 * A user's chosen colour is applied inline (validated) on top of that.
 */
final class ChatStyles
{
    /**
     * The named-colour palette offered by the colour dropdowns (login +
     * settings). Users may also type a custom #hex. Kept deliberately broad so
     * that whatever theme is active, some options are legible.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function palette(): array
    {
        $names = [
            'red', 'orange', 'gold', 'yellow', 'lime', 'green', 'teal', 'cyan',
            'blue', 'navy', 'purple', 'magenta', 'pink', 'brown', 'gray',
            'silver', 'white', 'black',
        ];
        $out = [];
        foreach ($names as $n) {
            $out[] = ['value' => $n, 'label' => ucfirst($n)];
        }
        return $out;
    }

    /**
     * A random colour name drawn from {@see palette()}. Backs the "random"
     * option in the colour pickers: the choice is resolved to a concrete palette
     * colour server-side at submit time, so what gets stored (and later shown as
     * selected) is a real colour, never the literal "random". Returns '' only if
     * the palette is somehow empty.
     */
    public static function randomColor(): string
    {
        $palette = self::palette();
        if ($palette === []) {
            return '';
        }
        return $palette[random_int(0, count($palette) - 1)]['value'];
    }

    /**
     * The font-family choices offered on the profile page. Kept to safe, generic
     * families (no external fonts — this is a no-JS TOR-friendly chat). '' = the
     * theme's own font.
     *
     * @return list<array{value: string, label: string, css: string}>
     */
    public static function fontChoices(): array
    {
        return [
            ['value' => 'sans',  'label' => 'Sans-serif', 'css' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"],
            ['value' => 'serif', 'label' => 'Serif',      'css' => "Georgia, 'Times New Roman', serif"],
            ['value' => 'mono',  'label' => 'Monospace',  'css' => "ui-monospace, 'Courier New', monospace"],
        ];
    }

    /** The CSS font-family stack for a stored font key, or '' for the theme default. */
    public static function fontFamilyCss(string $key): string
    {
        foreach (self::fontChoices() as $f) {
            if ($f['value'] === $key) {
                return $f['css'];
            }
        }
        return '';
    }

    /**
     * A curated IANA timezone list for the profile page — timestamps render in
     * the viewer's zone. Kept short so the dropdown isn't 400 entries; '' means
     * the server's own timezone.
     *
     * @return list<string>
     */
    public static function timezones(): array
    {
        return [
            'UTC',
            'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Rome',
            'Europe/Madrid', 'Europe/Amsterdam', 'Europe/Moscow', 'Europe/Istanbul',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Sao_Paulo', 'America/Mexico_City', 'America/Toronto',
            'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Shanghai',
            'Asia/Tokyo', 'Asia/Seoul', 'Australia/Sydney', 'Pacific/Auckland',
        ];
    }

    /** True when $tz is one of the offered timezones (safe for DateTimeZone). */
    public static function isValidTimezone(string $tz): bool
    {
        return in_array($tz, self::timezones(), true);
    }

    /**
     * Per-viewer overrides applied to the message/roster frames on top of the
     * theme: font family, font size and a personal background colour. This is
     * where the stored font_size finally takes effect. Returned as a CSS snippet
     * to inline after frameCss(). All inputs are validated/whitelisted.
     */
    public static function personalFrameCss(string $fontKey, int $fontSize, string $bgColor): string
    {
        $rules = [];
        $ff = self::fontFamilyCss($fontKey);
        if ($ff !== '') {
            $rules[] = "#main{font-family:{$ff};}";
        }
        if ($fontSize >= 8 && $fontSize <= 28) {
            $rules[] = "#main{font-size:{$fontSize}px;}";
        }
        if ($bgColor !== '' && preg_match('/^#[0-9a-f]{3,6}$|^[a-z]{1,20}$/i', $bgColor) === 1) {
            $safe = htmlspecialchars($bgColor, ENT_QUOTES, 'UTF-8');
            $rules[] = "#wrap,#main{background:{$safe};}";
        }
        return implode("\n", $rules);
    }

    /**
     * CSS for the template=0 iframe panes (message stream, user roster). Emitted
     * AFTER the active theme stylesheet: it strips the page chrome so the pane is
     * compact, but keeps the theme's `#main` text colour and body background.
     */
    public static function frameCss(): string
    {
        return <<<'CSS'
/* ── compact the themed page down to just the pane content ───────────────── */
html, body { margin: 0; padding: 0; }
#wrap { border: 0; margin: 0; }
#header, #top_nav, #user_top_nav, #admin_top_nav, #footer, #message_bar, #go_top { display: none; }
#main { border: 0; margin: 0; padding: .35em .55em; overflow: visible; min-width: 0; }

/* ── message list (le-chat: dense, colour-coded, newest first) ───────────── */
ul.chat-messages { list-style: none; margin: 0; padding: 0; line-height: 1.5; }
.chat-msg { padding: 1px 0; border-bottom: 1px solid rgba(128,128,128,.22); word-wrap: break-word; overflow-wrap: anywhere; }
.chat-time { opacity: .55; font-size: .82em; margin-right: .15em; }
.chat-user { font-weight: bold; }
.chat-member { text-decoration: underline; }
.chat-sep { opacity: .5; }
.chat-body blockquote.chat-quote { margin: .2em 0 .2em .8em; padding-left: .5em; border-left: 3px solid rgba(128,128,128,.5); opacity: .9; }
.chat-body code.chat-code { background: rgba(128,128,128,.18); padding: 0 .25em; border-radius: 2px; }
.chat-body img.chat-img { max-width: 100%; max-height: 260px; height: auto; display: block; margin: .2em 0; border: 1px solid rgba(128,128,128,.3); }
.chat-attachment { margin: .25em 0; }
.chat-attachment img { max-width: 100%; max-height: 320px; width: auto; height: auto; display: block; border: 1px solid rgba(128,128,128,.35); border-radius: 3px; }
/* a11y (#110): a visible keyboard-focus ring in the stream */
.chat-messages a:focus-visible, .chat-del button:focus-visible, .chat-report button:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
.chat-del { display: inline; }
/* Inline action buttons (delete, report, mod tools): theme-aware, not native —
   transparent background, inherited text colour, a neutral translucent border,
   and em sizing (no hard-coded px) so they read on every theme and scale with
   the reader's font size instead of rendering as small light native buttons. */
.chat-del button, .chat-report button, .chat-mod-actions button {
    font: inherit; font-size: .82em; line-height: 1.4; padding: 0 .45em; cursor: pointer;
    background: transparent; color: inherit;
    border: 1px solid rgba(128,128,128,.5); border-radius: 2px;
}
.chat-del button { margin-left: .3em; }
.chat-del button:hover, .chat-report button:hover, .chat-mod-actions button:hover { background: rgba(128,128,128,.2); }
.chat-empty { opacity: .7; padding: .5em .2em; }

/* admin public notes / greeting board pinned above the stream (theme-neutral) */
.chat-greeting { margin: 0 0 .6em; padding: .4em .65em; border: 1px solid rgba(128,128,128,.35); border-left: 3px solid rgba(128,128,128,.6); background: rgba(128,128,128,.07); line-height: 1.5; }
.chat-greeting a { word-break: break-all; }

/* /me emotes: "* nick does something" */
.chat-msg.chat-action { font-style: italic; opacity: .95; }
.chat-msg.chat-action .chat-sep { font-weight: bold; opacity: .8; font-style: normal; }
.chat-msg.chat-system { font-style: italic; opacity: .68; }
.chat-msg.chat-system .chat-sep { font-style: normal; }

/* moderator broadcast — a prominent, un-ignorable announcement */
.chat-msg.chat-broadcast { background: rgba(200,160,40,.16); border-left: 3px solid rgba(200,160,40,.85); padding-left: .45em; font-weight: bold; }
.chat-broadcast-tag { text-transform: uppercase; font-size: .76em; letter-spacing: .05em; opacity: .85; margin-right: .35em; }

/* private messages shown inline in the stream */
.chat-msg.chat-pm { background: rgba(128,128,128,.10); font-style: italic; }
.chat-pm-tag { font-weight: bold; font-style: normal; opacity: .75; margin-right: .2em; }

/* ── user roster ─────────────────────────────────────────────────────────── */
.chat-roster-head { font-weight: bold; opacity: .75; font-size: .8em; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 .35em; }
ul.chat-users-list { list-style: none; margin: 0; padding: 0; line-height: 1.5; }
.chat-users-list li { padding: 1px 0; border-bottom: 1px solid rgba(128,128,128,.18); }
.chat-role { opacity: .6; font-size: .78em; margin-left: .2em; }
.chat-mod-actions { display: block; margin: .1em 0 .2em; }
.chat-mod-actions form { display: inline; margin-right: .25em; }
.chat-mod-actions input[type=text], .chat-mod-actions input[type=number] {
    width: 3.4em; font: inherit; font-size: .82em; padding: 0 .2em;
    background: transparent; color: inherit;
    border: 1px solid rgba(128,128,128,.5); border-radius: 2px;
}
.chat-personal-actions button { opacity: .85; }
CSS;
    }

    /**
     * CSS for the shell page (chat.html). The shell is already inside the themed
     * `#main`, so this is layout only — the two panes side by side, the forms,
     * and the sub-navigation — and it stacks on narrow screens.
     */
    public static function shellCss(): string
    {
        return <<<'CSS'
#chat .chat-controls { display: flex; flex-wrap: wrap; align-items: center; gap: .1em .5em; margin: .2em 0 .5em; padding-bottom: .3em; border-bottom: 1px solid rgba(128,128,128,.3); }
#chat .chat-controls form.chat-inline { display: inline; margin: 0; }
#chat .chat-controls a.input { display: inline-block; text-decoration: none; }
#chat .chat-controls .chat-pm-badge { margin-left: auto; }
#chat .chat-topic { opacity: .8; font-style: italic; margin: .1em 0 .4em; }
#chat .chat-mod-tools { margin: .1em 0 .5em; }
#chat .chat-mod-tools form { display: inline; margin-right: .6em; }
#chat .chat-mod-tools .input { padding: 0 .3em; }
#chat .chat-pm-badge { opacity: .85; font-weight: bold; }
#chat .chat-panes { display: flex; gap: .5em; align-items: stretch; margin: .3em 0 .5em; }
#chat .chat-stream { flex: 1 1 auto; height: 360px; min-width: 0; box-sizing: border-box; border: 1px solid rgba(128,128,128,.5); background: transparent; }
#chat .chat-users  { flex: 0 0 13em; height: 360px; box-sizing: border-box; border: 1px solid rgba(128,128,128,.5); background: transparent; }
#chat .chat-post .chat-postbox { width: 100%; box-sizing: border-box; }
#chat .chat-post, #chat .chat-pm-form { margin: .45em 0; }
#chat .chat-pm-form label { margin-right: .3em; }
#chat .chat-pm-form .input { margin-right: .4em; }
#chat .chat-send-to { margin: .25em 0; }
#chat .chat-send-to label { margin-right: .3em; }
#chat .chat-send-to .input { margin-right: .4em; }
#chat .chat-meta { opacity: .75; margin: .1em 0; }
#chat .chat-hint { opacity: .7; font-size: .85em; margin: .1em 0 .3em; }
#chat form.chat-inline { display: inline; margin-right: .5em; }
#chat .chat-upload { margin: .35em 0; }
#chat .chat-upload label { margin-right: .3em; }
/* a11y (#110): keyboard-focus ring on shell controls */
#chat a:focus-visible, #chat button:focus-visible, #chat input:focus-visible, #chat select:focus-visible, #chat textarea:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
/* Rearrange: swap the messages / online panes left-to-right (per-session toggle). */
#chat.chat-alt .chat-panes { flex-direction: row-reverse; }
@media (max-width: 640px) {
    #chat .chat-panes, #chat.chat-alt .chat-panes { flex-direction: column; }
    #chat .chat-users { flex-basis: auto; height: 140px; }
    #chat .chat-stream { height: 300px; }
}
CSS;
    }

    /**
     * A safe ` style="color:…"` attribute for a stored/validated colour, or ''.
     * Accepts a #hex or a plain CSS colour word (already the only things the
     * services persist).
     */
    public static function colorStyle(string $color): string
    {
        if ($color !== '' && preg_match('/^#[0-9a-f]{3,6}$|^[a-z]{1,20}$/i', $color) === 1) {
            return ' style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"';
        }
        return '';
    }
}
