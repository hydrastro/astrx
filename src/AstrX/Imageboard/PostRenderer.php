<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

/**
 * Imageboard post markup → safe HTML.
 *
 * Same invariant as Chat\BbcodeRenderer: the ENTIRE input is HTML-escaped up
 * front, so no user text can ever inject markup; only the fixed transforms
 * below add elements. Extended with the imageboard idioms — greentext,
 * `>>`post quote-links, `[spoiler]` (CSS-hidden), and `[code]` (verbatim).
 *
 * Quote anchors are emitted as href="#p<no>"; per-viewer "(You)" tagging and
 * reply backlinks are added by the thread view at display time, not stored.
 */
final class PostRenderer
{
    private const FLAGS = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;

    public function render(string $raw, bool $bbcode = true): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // 1. Extract [code] blocks so their contents are shown verbatim and are
        //    never greentexted/linkified. Re-inserted at the end.
        $codes = [];
        $raw = preg_replace_callback(
            '~\[code\](.*?)\[/code\]~is',
            function (array $m) use (&$codes): string {
                $i         = count($codes);
                $codes[$i] = htmlspecialchars($m[1], self::FLAGS);
                return "\x00CODE{$i}\x00";
            },
            $raw
        ) ?? $raw;

        // 2. Escape everything that remains.
        $esc = htmlspecialchars($raw, self::FLAGS);

        // 3. Per line: quote-links, then greentext (a line beginning with a
        //    single escaped '>' that is not already a quote link).
        $lines = explode("\n", $esc);
        foreach ($lines as $i => $line) {
            $line = $this->quoteLinks($line);
            if (str_starts_with($line, '&gt;') && !str_starts_with($line, '<a')) {
                $line = '<span class="greentext">' . $line . '</span>';
            }
            $lines[$i] = $line;
        }
        $html = implode("<br>\n", $lines);

        // 4. Inline tags when the board enables BBCode; then bare-URL linkify.
        //    Greentext, >>quotes, [code] and links are always on (core idioms).
        if ($bbcode) {
            $html = $this->inlineTags($html);
        }
        $html = $this->linkify($html);

        // 5. Re-insert the verbatim code blocks.
        return preg_replace_callback(
            '~\x00CODE(\d+)\x00~',
            function (array $m) use ($codes): string {
                $idx = (int) $m[1];
                return '<code class="post-code">' . ($codes[$idx] ?? '') . '</code>';
            },
            $html
        ) ?? $html;
    }

    /** `&gt;&gt;123` → an intra-thread quote link. */
    private function quoteLinks(string $line): string
    {
        return preg_replace(
            '~&gt;&gt;(\d+)~',
            '<a class="quotelink" href="#p$1" data-no="$1">&gt;&gt;$1</a>',
            $line
        ) ?? $line;
    }

    private function inlineTags(string $html): string
    {
        $map = [
            '~\[b\](.*?)\[/b\]~is'             => '<strong>$1</strong>',
            '~\[i\](.*?)\[/i\]~is'             => '<em>$1</em>',
            '~\[u\](.*?)\[/u\]~is'             => '<u>$1</u>',
            '~\[s\](.*?)\[/s\]~is'             => '<s>$1</s>',
            '~\[spoiler\](.*?)\[/spoiler\]~is' => '<span class="spoiler">$1</span>',
        ];
        foreach ($map as $re => $rep) {
            $html = preg_replace($re, $rep, $html) ?? $html;
        }
        return $html;
    }

    private function linkify(string $html): string
    {
        return preg_replace_callback(
            '~(?<![">])\bhttps?://[^\s<>"\']+~i',
            function (array $m): string {
                $url = rtrim($m[0], '.,;:!?)');
                return '<a class="postlink" rel="noopener noreferrer nofollow" href="' . $url . '">' . $url . '</a>';
            },
            $html
        ) ?? $html;
    }
}
