<?php
declare(strict_types=1);

namespace AstrX\Chat;

/**
 * Renders a chat message's raw text into a SAFE HTML fragment.
 *
 * Security model — "escape first, then tokenise":
 *   1. The ENTIRE input is htmlspecialchars-escaped up front, so any `<`, `>`,
 *      `"`, `'`, `&` become inert text and NOTHING the user types can ever
 *      become live HTML. This is the whole ballgame: the steps below only ever
 *      ADD a fixed, hard-coded allowlist of tags around already-escaped text.
 *   2. An allowlist of BBCode tags ([b] [i] [u] [s] [quote] [code] [color]) is
 *      converted to a fixed set of HTML tags via a STACK parser, so output is
 *      always balanced and a stray/rogue close tag is dropped, never emitted.
 *   3. [color=X] only emits `style="color:X"` when X matches a strict allowlist
 *      (a named colour or #hex) — no arbitrary CSS, no url(), no expression().
 *   4. Bare http(s) URLs in text are linked with rel="noopener noreferrer
 *      nofollow"; the URL is already escaped so it cannot break out of the
 *      attribute. No other scheme is ever linked.
 *   5. Newlines become <br>.
 *
 * Zero dependencies. No DOM, no regex-on-HTML: we only pattern-match the
 * bracket tokens, which survive htmlspecialchars unchanged.
 */
final class BbcodeRenderer
{
    /** tag token → HTML (open, close). 'color' is handled specially. */
    private const TAGS = [
        'b'     => ['<strong>', '</strong>'],
        'i'     => ['<em>',     '</em>'],
        'u'     => ['<u>',      '</u>'],
        's'     => ['<s>',      '</s>'],
        'quote' => ['<blockquote class="chat-quote">', '</blockquote>'],
        'code'  => ['<code class="chat-code">',        '</code>'],
    ];

    /** Named CSS colours accepted in [color=…]. Anything else must be #hex. */
    private const NAMED_COLORS = [
        'black', 'silver', 'gray', 'grey', 'white', 'maroon', 'red', 'purple',
        'fuchsia', 'green', 'lime', 'olive', 'yellow', 'navy', 'blue', 'teal',
        'aqua', 'orange', 'pink', 'brown', 'gold', 'cyan', 'magenta',
    ];

    public function render(string $raw, bool $bbcode = true, bool $linkify = true, bool $embedImages = false): string
    {
        // 1. Escape everything. From here on the string contains no live HTML.
        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        // When BBCode is disabled (admin config) the message is inert escaped
        // text — still optionally linkified and line-broken, but no tags are
        // ever interpreted.
        if (!$bbcode) {
            return $this->format($escaped, $linkify, $embedImages);
        }

        // 2. Split into text and allowlisted-tag tokens. htmlspecialchars leaves
        //    '[', ']', '=', '/', letters and '#' untouched, so tags survive.
        $pattern = '/(\[\/?(?:b|i|u|s|quote|code|color)(?:=[^\]]{1,32})?\])/i';
        $parts   = preg_split($pattern, $escaped, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $this->format($escaped, $linkify, $embedImages);
        }

        $out   = '';
        $stack = []; // list<string> of open tag names, innermost last

        foreach ($parts as $i => $part) {
            // Even indices are text, odd indices are captured tag tokens.
            if ($i % 2 === 0) {
                $out .= $this->format($part, $linkify, $embedImages);
                continue;
            }

            $token = strtolower($part);
            if ($token[1] === '/') {
                // Closing tag: only honour it if it matches the innermost open tag.
                $name = substr($token, 2, -1);
                if ($stack !== [] && end($stack) === $name) {
                    array_pop($stack);
                    $out .= $name === 'color' ? '</span>' : self::TAGS[$name][1];
                } // else: unmatched close — drop it (never emit stray HTML)
                continue;
            }

            // Opening tag.
            if (str_starts_with($token, '[color=')) {
                $value = substr($part, 7, -1); // preserve original case of the value
                $css   = $this->safeColor($value);
                if ($css === null) {
                    // Invalid colour → render the tag as literal (already-escaped) text.
                    $out .= $part;
                    continue;
                }
                $stack[] = 'color';
                $out    .= '<span style="color:' . $css . '">';
                continue;
            }

            $name = substr($token, 1, -1);
            if (!isset(self::TAGS[$name])) {
                $out .= $part; // not really an allowlisted tag — leave as text
                continue;
            }
            $stack[] = $name;
            $out    .= self::TAGS[$name][0];
        }

        // 3. Close any tags left open so output is always balanced.
        while ($stack !== []) {
            $name = array_pop($stack);
            $out .= $name === 'color' ? '</span>' : self::TAGS[$name][1];
        }

        return $out;
    }

    /**
     * Validate a [color=…] value. Returns a safe CSS colour string or null.
     * Only a named colour or a #RGB / #RRGGBB hex value is ever accepted.
     * Public so message/nick colours can be validated at post time.
     */
    public function safeColor(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        if (in_array($v, self::NAMED_COLORS, true)) {
            return $v;
        }
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $v) === 1) {
            return $v;
        }
        return null;
    }

    /**
     * Wrap bare http(s) URLs in the already-escaped text with a hardened link.
     * The URL substring is already HTML-escaped, so it cannot break the href
     * attribute or the surrounding markup. Only http/https are ever linked.
     * Also converts newlines to <br>.
     */
    private function format(string $escapedText, bool $linkify = true, bool $embedImages = false): string
    {
        if ($escapedText === '') {
            return '';
        }
        // Link conversion off (admin config): only convert newlines to <br>.
        if (!$linkify) {
            return str_replace("\n", "<br>\n", $escapedText);
        }
        $linked = preg_replace_callback(
            '~\bhttps?://[^\s\[\]<>"\']+~i',
            function (array $m) use ($embedImages): string {
                $url = $m[0];
                // Trim trailing sentence punctuation so "see http://x/." doesn't eat the dot.
                $trail = '';
                while ($url !== '' && str_contains('.,;:!?)', substr($url, -1))) {
                    $trail = substr($url, -1) . $trail;
                    $url   = substr($url, 0, -1);
                }
                if ($url === '') {
                    return $m[0];
                }
                // Image embedding (admin config, off by default): render an image
                // URL inline. The URL is already HTML-escaped; no-referrer keeps a
                // TOR client from leaking the referrer when it loads the image.
                if ($embedImages && preg_match('~\.(?:jpe?g|png|gif|webp|bmp|svg)$~i', $url) === 1) {
                    return '<img src="' . $url . '" alt="" class="chat-img" referrerpolicy="no-referrer" loading="lazy">' . $trail;
                }
                return '<a href="' . $url . '" rel="noopener noreferrer nofollow">' . $url . '</a>' . $trail;
            },
            $escapedText,
        );
        $linked ??= $escapedText;

        return str_replace("\n", "<br>\n", $linked);
    }
}
