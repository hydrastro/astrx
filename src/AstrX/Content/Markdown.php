<?php
declare(strict_types=1);

namespace AstrX\Content;

/**
 * A tiny, zero-dependency, escape-by-default Markdown renderer for content pages.
 *
 * Deliberately a SAFE SUBSET — no raw HTML pass-through (the whole input is
 * HTML-escaped before any formatting is applied, so a content author can never
 * inject markup or script, which matters on a hidden-service deployment). It
 * supports: ATX headings, bold/italic, inline code, fenced code blocks, links
 * and images (URL-scheme whitelisted), unordered/ordered lists, blockquotes,
 * horizontal rules, paragraphs, and AstrX's own `[[wiki]]` inter-page links.
 *
 * Wiki links resolve through an injected callback so the renderer stays free of
 * the database: it is given a slug and returns the target URL plus whether the
 * page exists, letting the caller mark broken links.
 */
final class Markdown
{
    /** URL schemes/prefixes allowed in links and images; everything else → '#'. */
    private const string SAFE_URL = '#^(https?://|/|\#|mailto:)#i';

    /**
     * @param (callable(string): array{url:string,exists:bool})|null $wikiResolver
     *        Resolve a `[[slug]]` target to a URL + existence flag. Null renders
     *        wiki links as plain text.
     * @param (callable(string): string)|null $externalLinkRewriter
     *        Given a raw (decoded) external http(s) URL that passed the safe-scheme
     *        whitelist, return a replacement href (e.g. a same-origin /exit?to=…
     *        interstitial URL). The result is HTML-escaped before output. Null
     *        leaves external links untouched.
     */
    public function render(string $markdown, ?callable $wikiResolver = null, ?callable $externalLinkRewriter = null): string
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $text);
        $n     = count($lines);

        $html = [];
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];

            // Fenced code block: ``` ... ```
            if (preg_match('/^```/', $line) === 1) {
                $buf = [];
                $i++;
                while ($i < $n && preg_match('/^```/', $lines[$i]) !== 1) {
                    $buf[] = $lines[$i];
                    $i++;
                }
                $html[] = '<pre><code>' . self::esc(implode("\n", $buf)) . '</code></pre>';
                continue;
            }

            // Blank line → block separator.
            if (trim($line) === '') {
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
                $html[] = '<hr>';
                continue;
            }

            // ATX heading.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
                $level  = strlen($m[1]);
                $html[] = "<h{$level}>" . $this->inline($m[2], $wikiResolver, $externalLinkRewriter) . "</h{$level}>";
                continue;
            }

            // Blockquote (one or more consecutive `>` lines).
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m) === 1) {
                $buf = [$m[1]];
                while ($i + 1 < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i + 1], $mm) === 1) {
                    $buf[] = $mm[1];
                    $i++;
                }
                $inner = array_map(fn(string $l): string => $this->inline($l, $wikiResolver, $externalLinkRewriter), $buf);
                $html[] = '<blockquote><p>' . implode('<br>', $inner) . '</p></blockquote>';
                continue;
            }

            // Lists (unordered `- * +` or ordered `1.`), consecutive items.
            if (preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $line, $m) === 1) {
                $ordered = ctype_digit($m[1][0]);
                $items   = [$m[2]];
                while ($i + 1 < $n && preg_match('/^\s*([-*+]|\d+\.)\s+(.*)$/', $lines[$i + 1], $mm) === 1) {
                    $items[] = $mm[2];
                    $i++;
                }
                $tag = $ordered ? 'ol' : 'ul';
                $li  = array_map(fn(string $it): string => '<li>' . $this->inline($it, $wikiResolver, $externalLinkRewriter) . '</li>', $items);
                $html[] = "<{$tag}>" . implode('', $li) . "</{$tag}>";
                continue;
            }

            // Paragraph: gather consecutive plain lines.
            $buf = [$line];
            while ($i + 1 < $n && trim($lines[$i + 1]) !== ''
                && preg_match('/^(```|#{1,6}\s|\s*>|\s*([-*+]|\d+\.)\s|\s*([-*_])(\s*\3){2,}\s*$)/', $lines[$i + 1]) !== 1) {
                $buf[] = $lines[$i + 1];
                $i++;
            }
            $para = array_map(fn(string $l): string => $this->inline($l, $wikiResolver, $externalLinkRewriter), $buf);
            $html[] = '<p>' . implode('<br>', $para) . '</p>';
        }

        return implode("\n", $html);
    }

    /**
     * Extract every `[[slug]]` / `[[slug|label]]` target from raw markdown.
     *
     * @return list<string> distinct slugs, in first-seen order
     */
    public static function wikiTargets(string $markdown): array
    {
        if (preg_match_all('/\[\[\s*([^\]|]+?)\s*(?:\|[^\]]*)?\]\]/', $markdown, $m) === false) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $slug) {
            $slug = trim($slug);
            if ($slug !== '' && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Inline
    // -------------------------------------------------------------------------

    /**
     * @param (callable(string): array{url:string,exists:bool})|null $wiki
     * @param (callable(string): string)|null $ext
     */
    private function inline(string $text, ?callable $wiki, ?callable $ext = null): string
    {
        // 1. Escape everything first — no raw HTML/script can survive.
        $text = self::esc($text);

        // 2. Protect inline code spans so their contents aren't formatted.
        /** @var list<string> $codes */
        $codes = [];
        $text  = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text) ?? $text;

        // 3. Images: ![alt](src) — block external srcs (off-site image = view beacon).
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', static function (array $m): string {
            return '<img src="' . self::safeUrl($m[2], null, true) . '" alt="' . $m[1] . '">';
        }, $text) ?? $text;

        // 4. Wiki links: [[slug]] or [[slug|label]]
        $text = preg_replace_callback('/\[\[\s*([^\]|]+?)\s*(?:\|\s*([^\]]+?)\s*)?\]\]/', function (array $m) use ($wiki): string {
            $slug  = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = ($m[2] ?? '') !== '' ? $m[2] : $m[1];
            if ($wiki === null) {
                return $label;
            }
            $r = $wiki($slug);
            $cls = $r['exists'] ? 'wikilink' : 'wikilink broken';
            return '<a href="' . self::safeUrl($r['url']) . '" class="' . $cls . '">' . $label . '</a>';
        }, $text) ?? $text;

        // 5. Links: [text](url)
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($ext): string {
            return '<a href="' . self::safeUrl($m[2], $ext) . '">' . $m[1] . '</a>';
        }, $text) ?? $text;

        // 6. Bold then italic.
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![a-zA-Z0-9])_([^_]+)_(?![a-zA-Z0-9])/', '<em>$1</em>', $text) ?? $text;

        // 7. Restore code spans.
        $text = preg_replace_callback('/\x01(\d+)\x01/', static function (array $m) use ($codes): string {
            $idx = (int) $m[1];
            return $codes[$idx] ?? '';
        }, $text) ?? $text;

        return $text;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Whitelist link/image URLs; anything not clearly safe becomes '#'.
     *
     * @param (callable(string): string)|null $ext optional external-link rewriter
     *        (applied only to whitelisted external http(s) targets — the caller
     *        routes them through the exit interstitial). Its result is re-escaped.
     * @param bool $blockExternal when true, an external (http(s)://, //host, or the
     *        backslash variants) target becomes '#'. Used for IMAGE srcs: an inline
     *        <img> auto-loads on view and can't be routed through the interstitial,
     *        so an off-site image is a zero-click deanonymisation beacon on a hidden
     *        service — refuse it rather than emit a direct external src.
     */
    private static function safeUrl(string $escapedUrl, ?callable $ext = null, bool $blockExternal = false): string
    {
        // The URL was HTML-escaped by esc(); decode for the scheme test, keep the
        // escaped form for output (safe in a double-quoted attribute). Backslashes
        // are normalised to '/' for the scheme test because browsers treat '\' as
        // '/' in http(s) URLs — so `/\evil.com` resolves to `//evil.com` and must be
        // classified the same way (else it would slip past the interstitial routing).
        $decoded = html_entity_decode($escapedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe   = str_replace('\\', '/', strtolower(trim($decoded)));
        if (preg_match(self::SAFE_URL, $probe) !== 1) {
            return '#';
        }
        // Matches http(s):// AND protocol-relative //host (incl. the backslash
        // variants normalised above).
        $isExternal = preg_match('#^(https?:)?//#i', $probe) === 1;
        if ($blockExternal && $isExternal) {
            return '#';
        }
        // External link target → optionally route through the caller's rewriter (the
        // exit interstitial) so `[x](//evil.tld)` / `[x](/\evil.tld)` can't slip past
        // it. The rewriter returns a raw URL which we escape.
        if ($ext !== null && $isExternal) {
            return self::esc($ext(trim($decoded)));
        }
        return $escapedUrl;
    }
}
