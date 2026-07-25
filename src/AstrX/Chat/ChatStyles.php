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
/* Colours here are SOLID and theme-driven: borders/rules use the theme's own
   text colour via currentColor (identical to the site's #fff rules on the dark
   default theme, and correct on light themes too) — never a translucent grey.
   No opacity, no border-radius, no em/rem sizes: text inherits the theme size so
   it is not shrunk, and spacing is plain px like the rest of the site. */
html, body { margin: 0; padding: 0; }
#wrap { border: 0; margin: 0; }
#header, #top_nav, #user_top_nav, #admin_top_nav, #footer, #message_bar, #go_top { display: none; }
#main { border: 0; margin: 0; padding: 4px 6px; overflow: visible; min-width: 0; }

/* ── message list (dense, newest first) ──────────────────────────────────── */
ul.chat-messages { list-style: none; margin: 0; padding: 0; line-height: 1.5; }
.chat-msg { padding: 2px 0; border-bottom: 1px solid currentColor; word-wrap: break-word; overflow-wrap: anywhere; }
.chat-time { margin-right: 3px; }
.chat-user { font-weight: bold; }
.chat-member { text-decoration: underline; }
.chat-body blockquote.chat-quote { margin: 2px 0 2px 12px; padding-left: 6px; border-left: 2px solid currentColor; }
.chat-body code.chat-code { border: 1px solid currentColor; padding: 0 3px; }
.chat-body img.chat-img { max-width: 100%; max-height: 40vh; height: auto; display: block; margin: 3px 0; border: 1px solid currentColor; }
.chat-attachment { margin: 3px 0; }
.chat-attachment img { max-width: 100%; max-height: 45vh; width: auto; height: auto; display: block; border: 1px solid currentColor; }
/* a11y (#110): a visible keyboard-focus ring in the stream */
.chat-messages a:focus-visible, .chat-del button:focus-visible, .chat-report button:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
.chat-del { display: inline; }
/* Inline action buttons (delete, report, mod): transparent fill, inherited text
   colour, a SOLID currentColor border, underline on hover — reads on every theme
   with no alpha and no colour wash. */
.chat-del button, .chat-report button, .chat-mod-actions button {
    font: inherit; line-height: 1.4; padding: 0 6px; cursor: pointer;
    background: transparent; color: inherit; border: 1px solid currentColor;
}
.chat-del button { margin-left: 4px; }
.chat-del button:hover, .chat-report button:hover, .chat-mod-actions button:hover { text-decoration: underline; }
.chat-empty { padding: 6px 3px; font-style: italic; }

/* admin public notes / greeting board pinned above the stream */
.chat-greeting { margin: 0 0 8px; padding: 6px 10px; border: 1px solid currentColor; border-left-width: 3px; line-height: 1.5; }
.chat-greeting a { word-break: break-all; }

/* /me emotes and system lines: italic instead of a faded colour */
.chat-msg.chat-action { font-style: italic; }
.chat-msg.chat-action .chat-sep { font-weight: bold; font-style: normal; }
.chat-msg.chat-system { font-style: italic; }
.chat-msg.chat-system .chat-sep { font-style: normal; }

/* moderator broadcast — bold + a solid left rule (no coloured wash) */
.chat-msg.chat-broadcast { border-left: 3px solid currentColor; padding-left: 6px; font-weight: bold; }
.chat-broadcast-tag { text-transform: uppercase; letter-spacing: 1px; margin-right: 4px; }

/* private messages shown inline in the stream */
.chat-msg.chat-pm { border-left: 2px solid currentColor; padding-left: 4px; font-style: italic; }
.chat-pm-tag { font-weight: bold; font-style: normal; margin-right: 3px; }

/* ── user roster ─────────────────────────────────────────────────────────── */
.chat-roster-head { font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 5px; }
ul.chat-users-list { list-style: none; margin: 0; padding: 0; line-height: 1.5; }
.chat-users-list li { padding: 2px 0; border-bottom: 1px solid currentColor; }
.chat-role { margin-left: 3px; }
.chat-mod-actions { display: block; margin: 2px 0 3px; }
.chat-mod-actions form { display: inline; margin-right: 4px; }
.chat-mod-actions input[type=text], .chat-mod-actions input[type=number] {
    width: 44px; font: inherit; padding: 0 3px;
    background: transparent; color: inherit; border: 1px solid currentColor;
}
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
/* Layout only (the shell already lives inside the themed #main). Solid colours
   (currentColor rules, no alpha), plain px spacing (no em/rem), and the panes
   size to the viewport (vh height, % width) so they auto-adjust. */
#chat .chat-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; margin: 3px 0 8px; padding-bottom: 5px; border-bottom: 1px solid currentColor; }
#chat .chat-controls form.chat-inline { display: inline; margin: 0; }
#chat .chat-controls a.input { display: inline-block; text-decoration: none; }
#chat .chat-controls .chat-pm-badge { margin-left: auto; }
#chat .chat-topic { font-style: italic; margin: 2px 0 6px; }
#chat .chat-mod-tools { margin: 2px 0 8px; }
#chat .chat-mod-tools form { display: inline; margin-right: 10px; }
#chat .chat-mod-tools .input { padding: 0 5px; }
#chat .chat-pm-badge { font-weight: bold; }
#chat .chat-panes { display: flex; gap: 8px; align-items: stretch; margin: 5px 0 8px; }
#chat .chat-stream { flex: 1 1 auto; height: 55vh; min-width: 0; box-sizing: border-box; border: 1px solid currentColor; background: transparent; }
#chat .chat-users  { flex: 0 0 25%; height: 55vh; box-sizing: border-box; border: 1px solid currentColor; background: transparent; }
#chat .chat-post .chat-postbox { width: 100%; box-sizing: border-box; }
#chat .chat-post, #chat .chat-pm-form { margin: 7px 0; }
#chat .chat-pm-form label { margin-right: 5px; }
#chat .chat-pm-form .input { margin-right: 6px; }
#chat .chat-send-to { margin: 4px 0; }
#chat .chat-send-to label { margin-right: 5px; }
#chat .chat-send-to .input { margin-right: 6px; }
#chat .chat-meta { margin: 2px 0; }
#chat .chat-hint { margin: 2px 0 5px; }
#chat form.chat-inline { display: inline; margin-right: 8px; }
#chat .chat-upload { margin: 5px 0; }
#chat .chat-upload label { margin-right: 5px; }
/* a11y (#110): keyboard-focus ring on shell controls */
#chat a:focus-visible, #chat button:focus-visible, #chat input:focus-visible, #chat select:focus-visible, #chat textarea:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
/* Rearrange: swap the messages / online panes left-to-right (per-session toggle). */
#chat.chat-alt .chat-panes { flex-direction: row-reverse; }
@media (max-width: 640px) {
    #chat .chat-panes, #chat.chat-alt .chat-panes { flex-direction: column; }
    #chat .chat-users { flex-basis: auto; height: 25vh; }
    #chat .chat-stream { height: 45vh; }
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
