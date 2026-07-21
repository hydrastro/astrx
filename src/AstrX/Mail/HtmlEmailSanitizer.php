<?php
declare(strict_types=1);

namespace AstrX\Mail;

/**
 * Sanitises an HTML email body for safe display inside a webmail UI.
 *
 * This is an ALLOWLIST sanitiser: anything not explicitly permitted is dropped.
 * A denylist (block known-bad) is insufficient for HTML email because the set
 * of remote-resource vectors keeps growing (srcset, <video>/<audio>/<source>
 * src, CSS image-set(), CSS-escaped \75rl(...), etc.). Allowlisting flips the
 * default to "deny" so novel vectors fail closed.
 *
 * Security policy (default — sender NOT trusted):
 *   - REMOVE_WITH_CONTENT tags (script/style/iframe/object/embed/form/input/
 *     button/select/textarea/meta/link/base/noscript) are removed with content.
 *   - Every remaining element whose tag is not in ALLOWED_TAGS is UNWRAPPED:
 *     replaced by its children so the text survives but the tag (and any of its
 *     resource-loading attributes) is gone.
 *   - Every attribute is dropped unless it is in the per-tag allowlist. `srcset`
 *     and all `on*` handlers are stripped unconditionally. `style` is dropped.
 *   - href/src schemes are normalised (ASCII whitespace + control chars removed,
 *     lowercased) before testing, then only http/https/mailto (href), #-fragment
 *     (href) and cid (img) survive.
 *   - <img> external src is blocked and replaced with a placeholder; no srcset
 *     or remote src reference is left behind.
 *   - <a> external links are hardened with rel="noopener noreferrer nofollow"
 *     and target="_blank".
 *
 * When the sender IS trusted:
 *   - <img> may load http/https (and cid) images.
 *   - `style` may be kept, but only after any value containing url, image-set,
 *     expression, or a backslash escape is dropped entirely.
 *   - Everything else (scripts, iframes, forms, event handlers, unknown tags)
 *     is still stripped/unwrapped.
 *
 * No JavaScript is used or generated at any point.
 * This class works entirely with DOMDocument + XPath.
 */
final class HtmlEmailSanitizer
{
    // Tags that are unconditionally removed along with their content.
    private const REMOVE_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed',
        'form',   'input', 'button', 'select', 'textarea',
        'meta',   'link',  'base',   'noscript',
    ];

    // The ONLY tags kept. Anything else is unwrapped (children preserved).
    // Stored as a set (tag => true) for O(1) membership tests.
    private const ALLOWED_TAGS = [
        'a' => true, 'p' => true, 'div' => true, 'span' => true, 'br' => true,
        'hr' => true, 'b' => true, 'strong' => true, 'i' => true, 'em' => true,
        'u' => true, 's' => true, 'ul' => true, 'ol' => true, 'li' => true,
        'blockquote' => true, 'pre' => true, 'code' => true, 'table' => true,
        'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true,
        'td' => true, 'th' => true, 'caption' => true, 'h1' => true, 'h2' => true,
        'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'img' => true,
        'figure' => true, 'figcaption' => true, 'small' => true, 'sub' => true,
        'sup' => true, 'dl' => true, 'dt' => true, 'dd' => true,
    ];

    // The ONLY attributes kept, per tag. Everything else is removed.
    // `style` is handled separately (trusted senders only). `target`/`rel` are
    // (re)applied by the link hardener after this allowlist runs.
    private const ALLOWED_ATTRS = [
        'a'   => ['href'],
        'img' => ['src', 'alt', 'width', 'height'],
        'td'  => ['colspan', 'rowspan'],
        'th'  => ['colspan', 'rowspan'],
    ];

    /**
     * Sanitise an HTML email body.
     *
     * @param string $html         Raw HTML from the email.
     * @param bool   $trusted      True if the sender is in the trusted list.
     * @return string              Safe HTML fragment (no <html>/<head>/<body> wrapper).
     */
    public function sanitise(string $html, bool $trusted = false): string
    {
        if (trim($html) === '') { return ''; }

        $doc   = $this->loadHtml($html);
        $xpath = new \DOMXPath($doc);

        // ── 1. Remove dangerous tags entirely (with their content) ───────────
        foreach (self::REMOVE_WITH_CONTENT as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}") ?: new \ArrayIterator()) as $node) {
                if ($node instanceof \DOMNode && $node->parentNode instanceof \DOMNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // ── 2. Allowlist walk: unwrap unknown tags, strip attributes, validate
        //       URL schemes, block remote images, harden links. Depth-first so
        //       an unwrapped element's already-sanitised children move up clean.
        $bodies = $doc->getElementsByTagName('body');
        if ($bodies->length > 0) {
            $body = $bodies->item(0);
            if ($body instanceof \DOMElement) {
                foreach (iterator_to_array($body->childNodes) as $child) {
                    if ($child instanceof \DOMElement) {
                        $this->sanitiseNode($child, $trusted);
                    }
                }
            }
        }

        return $this->extractBody($doc);
    }

    // =========================================================================

    /**
     * Recursively sanitise an element. Children are processed first (depth
     * first); a disallowed element is then unwrapped so its clean children
     * survive in its place.
     */
    private function sanitiseNode(\DOMElement $el, bool $trusted): void
    {
        // Process descendants first (snapshot: the tree is mutated below).
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $this->sanitiseNode($child, $trusted);
            }
        }

        $tag = strtolower($el->nodeName);

        if (!isset(self::ALLOWED_TAGS[$tag])) {
            $this->unwrap($el);
            return;
        }

        $this->sanitiseAttributes($el, $tag, $trusted);

        if ($tag === 'img') { $this->sanitiseImg($el, $trusted); }
        if ($tag === 'a')   { $this->hardenLink($el); }
    }

    /**
     * Drop every attribute not in the per-tag allowlist. `srcset` and every
     * `on*` handler are removed unconditionally. `style` is removed for
     * untrusted senders; for trusted senders it is kept only when it carries no
     * resource-loading construct.
     */
    private function sanitiseAttributes(\DOMElement $el, string $tag, bool $trusted): void
    {
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];
        $remove  = [];

        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->name);

            // Unconditional strips (defence in depth — also not in any allowlist).
            if ($name === 'srcset' || str_starts_with($name, 'on')) {
                $remove[] = $attr->name;
                continue;
            }

            if ($name === 'style') {
                // Trusted: keep for now, sanitise the value after the loop.
                // Untrusted: drop entirely.
                if (!$trusted) { $remove[] = $attr->name; }
                continue;
            }

            if (!in_array($name, $allowed, true)) {
                $remove[] = $attr->name;
            }
        }

        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }

        // Trusted-sender style: drop the whole attribute if it can load a
        // resource (url(...), CSS-escaped \75rl(...), image-set(...),
        // expression(...)). A backslash means a CSS escape is in play, which is
        // the \75rl(...) bypass — reject outright.
        if ($trusted && $el->hasAttribute('style')) {
            if ($this->styleIsUnsafe($el->getAttribute('style'))) {
                $el->removeAttribute('style');
            }
        }
    }

    private function styleIsUnsafe(string $style): bool
    {
        $lower = strtolower($style);
        return str_contains($lower, 'url')
            || str_contains($lower, 'image-set')
            || str_contains($lower, 'expression')
            || str_contains($style, '\\');
    }

    /**
     * Enforce the image src policy. srcset is already gone; here we validate the
     * src scheme and, for untrusted senders, block remote images entirely,
     * leaving no remote reference behind.
     */
    private function sanitiseImg(\DOMElement $img, bool $trusted): void
    {
        // Belt and suspenders: srcset must never survive on an <img>.
        $img->removeAttribute('srcset');

        $src = $img->getAttribute('src');
        if ($src === '') { return; }

        $scheme = $this->normaliseForScheme($src);

        $isHttp = str_starts_with($scheme, 'http:') || str_starts_with($scheme, 'https:');
        $isCid  = str_starts_with($scheme, 'cid:');

        if ($trusted) {
            // Trusted: allow remote http(s) and inline cid; drop anything else.
            if (!$isHttp && !$isCid) {
                $img->removeAttribute('src');
            }
            return;
        }

        // Untrusted: only inline cid: attachments are allowed. Block everything
        // else (remote http(s), data:, etc.) and show an inert placeholder.
        if ($isCid) { return; }

        $img->removeAttribute('src');
        $img->setAttribute('alt', '[image blocked]');
        $img->setAttribute('style', 'border:1px dashed #aaa;padding:2px;font-size:0.8em');
    }

    /**
     * Validate an <a> href scheme and harden external links. Disallowed schemes
     * (javascript:, vbscript:, data:, …) have the href removed; the link text
     * survives.
     */
    private function hardenLink(\DOMElement $a): void
    {
        $href = $a->getAttribute('href');
        if ($href === '') { return; }

        $scheme = $this->normaliseForScheme($href);

        $isFragment = str_starts_with($scheme, '#');
        $isMailto   = str_starts_with($scheme, 'mailto:');
        $isHttp     = str_starts_with($scheme, 'http:') || str_starts_with($scheme, 'https:');

        if (!$isFragment && !$isMailto && !$isHttp) {
            $a->removeAttribute('href');
            return;
        }

        // Force external links to open safely and not leak the referrer or
        // grant window.opener access.
        if ($isHttp) {
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    /**
     * Normalise a URL for scheme testing the way a browser does: strip ASCII
     * whitespace and control characters (\t \n \r \f \v \0 and space) that are
     * ignored when resolving the scheme, then lowercase. Only used to classify
     * the scheme — the stored attribute value is left untouched.
     */
    private function normaliseForScheme(string $url): string
    {
        $stripped = preg_replace('/[\x00-\x20]+/', '', $url);
        return strtolower($stripped ?? '');
    }

    /**
     * Replace an element with its children (unwrap), preserving text and any
     * already-sanitised descendants.
     */
    private function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (!$parent instanceof \DOMNode) { return; }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Suppress warnings from malformed HTML; always use UTF-8
        $wrapped = '<?xml encoding="UTF-8">'
                   . '<html><head><meta charset="UTF-8"></head><body>'
                   . $html
                   . '</body></html>';
        @$doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED);
        return $doc;
    }

    private function extractBody(\DOMDocument $doc): string
    {
        $bodies = $doc->getElementsByTagName('body');
        if ($bodies->length === 0) { return ''; }
        $body  = $bodies->item(0);
        if (!($body instanceof \DOMElement)) { return ''; }
        $out   = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }
}
