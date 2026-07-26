<?php
declare(strict_types=1);

namespace AstrX\Content;

use AstrX\I18n\Translator;
use AstrX\Routing\UrlGenerator;

/**
 * Content-module logic on top of {@see ContentPageRepository}: it turns a page's
 * Markdown into safe HTML with resolved `[[wiki]]` links, gathers backlinks, and
 * draws the page graph as a static inline SVG (no JavaScript). URL building goes
 * through {@see UrlGenerator} so links stay locale-correct and host-relative
 * (Tor-safe).
 */
final class ContentService
{
    public function __construct(
        private readonly ContentPageRepository $repo,
        private readonly Markdown              $markdown,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {}

    /** The /<locale>/pages base URL that content pages hang off. */
    private function base(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CONTENT'));
    }

    /** Public URL of a content page by slug. */
    public function pageUrl(string $slug): string
    {
        return $this->base() . '/' . rawurlencode($slug);
    }

    /** The content index URL (/<locale>/pages). */
    public function indexUrl(): string
    {
        return $this->base();
    }

    /** The page-graph URL (/<locale>/pages?view=graph). */
    public function graphUrl(): string
    {
        return $this->base() . '?view=graph';
    }

    /**
     * Render a page body to HTML, resolving `[[wiki]]` links against the set of
     * existing slugs (fetched once) so broken targets get the `broken` class.
     */
    public function renderBody(string $body): string
    {
        $existing = $this->existingSlugs();
        $base     = $this->base();

        $resolver = function (string $slug) use ($existing, $base): array {
            return [
                'url'    => $base . '/' . rawurlencode($slug),
                'exists' => isset($existing[$slug]),
            ];
        };

        return $this->markdown->render($body, $resolver);
    }

    /**
     * Visible pages for the index, each with a ready URL.
     *
     * @return list<array{slug:string,title:string,url:string,updated_at:string}>
     */
    public function index(): array
    {
        $r    = $this->repo->all(visibleOnly: true);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'slug'       => $row['slug'],
                'title'      => $row['title'] !== '' ? $row['title'] : $row['slug'],
                'url'        => $this->pageUrl($row['slug']),
                'updated_at' => $row['updated_at'],
            ];
        }
        return $out;
    }

    /**
     * Backlinks ("what links here") for a page, with URLs.
     *
     * @return list<array{title:string,url:string}>
     */
    public function backlinks(int $pageId): array
    {
        $r    = $this->repo->backlinks($pageId);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'title' => $row['title'] !== '' ? $row['title'] : $row['slug'],
                'url'   => $this->pageUrl($row['slug']),
            ];
        }
        return $out;
    }

    /**
     * The page graph as a self-contained inline SVG (nodes on a circle, resolved
     * links as edges). Deterministic layout, no script. Returns ['svg'=>…, 'count'=>N].
     *
     * @return array{svg:string,count:int}
     */
    public function graphSvg(): array
    {
        $g     = $this->repo->graph();
        $data  = $g->isOk() ? $g->unwrap() : ['nodes' => [], 'edges' => []];
        $nodes = $data['nodes'];
        $edges = $data['edges'];
        $n     = count($nodes);

        if ($n === 0) {
            return ['svg' => '', 'count' => 0];
        }

        // Circular layout. Radius grows with node count so labels don't collide.
        $radius = max(110, (int) round($n * 16));
        $margin = 140; // room for labels around the ring
        $size   = 2 * ($radius + $margin);
        $cx     = $size / 2;
        $cy     = $size / 2;

        /** @var array<int,array{x:float,y:float,slug:string,title:string}> $pos */
        $pos = [];
        foreach (array_values($nodes) as $i => $node) {
            $angle = ($n > 1) ? (2 * M_PI * $i / $n) - M_PI / 2 : 0.0;
            $pos[$node['id']] = [
                'x'     => $cx + $radius * cos($angle),
                'y'     => $cy + $radius * sin($angle),
                'slug'  => $node['slug'],
                'title' => $node['title'] !== '' ? $node['title'] : $node['slug'],
            ];
        }

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size
              . '" class="content-graph" role="img" style="max-width:100%;height:auto">';

        // Edges first (under the nodes).
        foreach ($edges as $e) {
            if (!isset($pos[$e['from']], $pos[$e['to']])) {
                continue;
            }
            $a = $pos[$e['from']];
            $b = $pos[$e['to']];
            $svg .= '<line x1="' . $this->c($a['x']) . '" y1="' . $this->c($a['y'])
                  . '" x2="' . $this->c($b['x']) . '" y2="' . $this->c($b['y'])
                  . '" stroke="currentColor" stroke-width="1" opacity="0.5"/>';
        }

        // Nodes: a linked circle + label.
        foreach ($pos as $p) {
            $url   = $this->esc($this->pageUrl($p['slug']));
            $label = $this->esc(mb_strimwidth($p['title'], 0, 28, '…'));
            $right = $p['x'] >= $cx;
            $tx    = $this->c($p['x'] + ($right ? 10 : -10));
            $anchor = $right ? 'start' : 'end';
            $svg  .= '<a href="' . $url . '">'
                   . '<circle cx="' . $this->c($p['x']) . '" cy="' . $this->c($p['y'])
                   . '" r="6" fill="currentColor"/>'
                   . '<text x="' . $tx . '" y="' . $this->c($p['y'] + 4)
                   . '" text-anchor="' . $anchor . '" font-size="13" fill="currentColor">' . $label . '</text>'
                   . '</a>';
        }

        $svg .= '</svg>';
        return ['svg' => $svg, 'count' => $n];
    }

    /**
     * The broken-link report, with URLs to the offending source pages.
     *
     * @return list<array{from_title:string,from_url:string,to_slug:string}>
     */
    public function brokenLinks(): array
    {
        $r    = $this->repo->brokenLinks();
        $rows = $r->isOk() ? $r->unwrap() : [];
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'from_title' => $row['from_title'] !== '' ? $row['from_title'] : $row['from_slug'],
                'from_url'   => $this->pageUrl($row['from_slug']),
                'to_slug'    => $row['to_slug'],
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------

    /** @return array<string,true> */
    private function existingSlugs(): array
    {
        $r    = $this->repo->all(visibleOnly: false);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $set  = [];
        foreach ($rows as $row) {
            $set[$row['slug']] = true;
        }
        return $set;
    }

    /** Format a coordinate compactly for SVG output. */
    private function c(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
