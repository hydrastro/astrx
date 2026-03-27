<?php
declare(strict_types=1);

namespace AstrX\Mail;

/**
 * Sanitises an HTML email body for safe display inside a webmail UI.
 *
 * Security policy (default — sender NOT trusted):
 *   - All <script>, <style>, <iframe>, <object>, <embed>, <form>, <input>,
 *     <button>, <select>, <textarea>, <meta>, <link>, <base> tags → stripped.
 *   - All event handler attributes (on*) → stripped.
 *   - All src/href/action/background attributes on ANY tag that reference
 *     external resources → replaced with a data-blocked attribute so the
 *     browser never fires a request.
 *   - <img> tags → src removed, placeholder inserted so layout is preserved.
 *   - CSS url() references inside style attributes → stripped.
 *   - <a> href values → kept but rel="noopener noreferrer nofollow" is forced
 *     and target="_blank" is forced so links open outside the current page.
 *
 * When the sender IS trusted:
 *   - External images are allowed (src kept on <img>).
 *   - Links still get rel/target hardening.
 *   - Everything else (scripts, iframes, forms, event handlers) still stripped.
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

    // Attributes that carry resource references (checked on every remaining tag).
    private const RESOURCE_ATTRS = [
        'src', 'href', 'action', 'background',
        'poster', 'data', 'code', 'codebase',
        'usemap', 'longdesc', 'dynsrc', 'lowsrc',
    ];

    // Event-handler attribute prefix.
    private const EVENT_PREFIX = 'on';

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

        $doc = $this->loadHtml($html);
        $xpath = new \DOMXPath($doc);

        // ── 1. Remove dangerous tags entirely ────────────────────────────────
        foreach (self::REMOVE_WITH_CONTENT as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}") ?: new \ArrayIterator()) as $node) {
                if ($node instanceof \DOMNode && $node->parentNode instanceof \DOMNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // ── 2. Strip all event handlers from every remaining element ─────────
        foreach (iterator_to_array($xpath->query('//*[@*]') ?: new \ArrayIterator()) as $element) {
            if (!($element instanceof \DOMElement)) { continue; }
            $toRemove = [];
            foreach ($element->attributes as $attr) {
                if (str_starts_with(strtolower($attr->name), self::EVENT_PREFIX)) {
                    $toRemove[] = $attr->name;
                }
                // Also strip javascript: protocol in any attribute
                if (str_starts_with(strtolower(trim($attr->value)), 'javascript:')) {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $name) {
                $element->removeAttribute($name);
            }
        }

        // ── 3. Strip inline style url() references ───────────────────────────
        foreach (iterator_to_array($xpath->query('//*[@style]') ?: new \ArrayIterator()) as $element) {
            if (!($element instanceof \DOMElement)) { continue; }
            $style = $element->getAttribute('style');
            // Remove url(...) occurrences
            $clean = preg_replace('/url\s*\([^)]*\)/i', 'url()', $style);
            $element->setAttribute('style', (string) $clean);
        }

        // ── 4. Handle images ─────────────────────────────────────────────────
        foreach (iterator_to_array($xpath->query('//img') ?: new \ArrayIterator()) as $img) {
            if (!($img instanceof \DOMElement)) { continue; }
            if ($trusted) {
                // Allow src but block non-http(s) schemes
                $src = $img->getAttribute('src');
                if (!preg_match('/^https?:\/\//i', $src) && !str_starts_with($src, 'cid:')) {
                    $img->removeAttribute('src');
                }
            } else {
                // Block all external images; preserve cid: (inline attachments)
                $src = $img->getAttribute('src');
                if (!str_starts_with($src, 'cid:')) {
                    $img->setAttribute('data-blocked-src', $src);
                    $img->removeAttribute('src');
                    // Replace with a visible placeholder
                    $img->setAttribute('alt', '[image blocked]');
                    $img->setAttribute('style', 'border:1px dashed #aaa;padding:2px;font-size:0.8em');
                }
            }
        }

        // ── 5. Process all remaining resource attributes ──────────────────────
        foreach (self::RESOURCE_ATTRS as $attr) {
            if ($attr === 'src') { continue; } // handled above for img
            if ($attr === 'href') { continue; } // handled below for <a>
            foreach (iterator_to_array(($xpath->query("//*[@{$attr}]") ?: new \ArrayIterator())) as $element) {
                if (!($element instanceof \DOMElement)) { continue; }
                $val = $element->getAttribute($attr);
                // Allow cid: and data: URIs on non-src attributes
                if (preg_match('/^(cid:|data:|#)/i', $val)) { continue; }
                // Block everything else
                $element->setAttribute("data-blocked-{$attr}", $val);
                $element->removeAttribute($attr);
            }
        }

        // ── 6. Harden all links ───────────────────────────────────────────────
        foreach (iterator_to_array(($xpath->query('//a[@href]') ?: new \ArrayIterator())) as $link) {
            if (!($link instanceof \DOMElement)) { continue; }
            $href = $link->getAttribute('href');
            // Block javascript: and vbscript: links
            if (preg_match('/^(javascript|vbscript):/i', trim($href))) {
                $link->removeAttribute('href');
                continue;
            }
            // Allow anchor links (#fragment) and mailto: through unchanged
            // Force external links to open safely
            if (!str_starts_with($href, '#') && !str_starts_with(strtolower($href), 'mailto:')) {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        return $this->extractBody($doc);
    }

    // =========================================================================

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
