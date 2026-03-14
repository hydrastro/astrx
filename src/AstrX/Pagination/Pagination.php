<?php
declare(strict_types=1);

namespace AstrX\Pagination;

use AstrX\Http\Request;

/**
 * Immutable pagination value object.
 *
 * Lifecycle:
 *   1. Build from request via fromRequest() — reads pn / show / order params.
 *   2. Use offset() and perPage to query the data layer.
 *   3. Call withTotal($count) — returns new instance with pageCount computed.
 *   4. Call toTemplateVars($urlForPage, $pageWindow) — flat array ready for
 *      the template engine, including the windowed page-link list.
 *
 * Internal order is always canonical ('asc' / 'desc').
 * Locale-specific URL words are handled by the controller at the boundary.
 *
 * Reusable for any paginated list (news, comments, admin tables, …).
 */
final class Pagination
{
    public readonly int    $page;
    public readonly int    $perPage;
    public readonly bool   $descending;
    public readonly string $order;   // canonical: always 'asc' or 'desc'
    public readonly int    $total;
    public readonly int    $pageCount;

    private function __construct(
        int  $page,
        int  $perPage,
        bool $descending,
        int  $total,
        int  $pageCount,
    ) {
        $this->page       = $page;
        $this->perPage    = $perPage;
        $this->descending = $descending;
        $this->order      = $descending ? 'desc' : 'asc';
        $this->total      = $total;
        $this->pageCount  = $pageCount;
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public static function fromRequest(
        Request $request,
        int     $defaultPerPage    = 20,
        bool    $defaultDescending = true,
        string  $pnKey             = 'pn',
        string  $showKey           = 'show',
        string  $orderKey          = 'order',
    ): self {
        $page    = $request->query()->getInt($pnKey, 1)->valueOr(1) ?? 1;
        $page    = max(1, $page);
        $perPage = $request->query()->getInt($showKey, $defaultPerPage)
                       ->valueOr($defaultPerPage) ?? $defaultPerPage;
        $perPage = max(0, $perPage);

        $rawOrder   = $request->query()->get($orderKey);
        $descending = match (is_string($rawOrder) ? strtolower($rawOrder) : '') {
            'asc'  => false,
            'desc' => true,
            default => $defaultDescending,
        };

        return new self($page, $perPage, $descending, 0, 0);
    }

    // -------------------------------------------------------------------------
    // Immutable update
    // -------------------------------------------------------------------------

    public function withTotal(int $total): self
    {
        $pageCount = $this->isUnpaged()
            ? 1
            : (int) ceil($total / $this->perPage);

        return new self($this->page, $this->perPage, $this->descending, $total, max(1, $pageCount));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function offset(): int    { return ($this->page - 1) * $this->perPage; }
    public function isUnpaged(): bool { return $this->perPage === 0; }
    public function hasPrev(): bool  { return $this->page > 1; }
    public function hasNext(): bool  { return $this->page < $this->pageCount; }

    // -------------------------------------------------------------------------
    // Template vars
    // -------------------------------------------------------------------------

    /**
     * Flatten pagination state into a template-ready array.
     *
     * @param callable(int $p): string $urlForPage  Returns URL for page $p.
     * @param int                      $pageWindow  Pages shown either side of
     *                                              current. 0 = show all.
     *
     * Produced keys:
     *   page, page_count, per_page
     *   has_prev, prev_url, has_first, first_url
     *   has_next, next_url, has_last,  last_url
     *   pages — list<{number, url, is_current}>
     *
     * @return array<string, mixed>
     */
    public function toTemplateVars(callable $urlForPage, int $pageWindow = 3): array
    {
        $pages = [];
        if ($this->pageCount > 1) {
            $lo = $pageWindow > 0 ? max(1, $this->page - $pageWindow) : 1;
            $hi = $pageWindow > 0 ? min($this->pageCount, $this->page + $pageWindow) : $this->pageCount;

            for ($i = $lo; $i <= $hi; $i++) {
                $url  = $i !== $this->page ? $urlForPage($i) : '';
                // 'link' is the pre-built <a> tag, or '' for the current page.
                // Template renders {{&link}} (unescaped) for linked pages — no
                // nested section needed, so $parent is never clobbered.
                // For the current page (url=''), {{^url}}{{number}}{{/url}} renders
                // the plain number via the inverted section which does NOT reassign
                // $parent, so {{number}} resolves correctly from the loop context.
                $pages[] = [
                    'number' => $i,
                    'url'    => $url,
                    'link'   => $url !== '' ? '<a href="' . htmlspecialchars($url) . '">' . $i . '</a>' : '',
                ];
            }
        }

        return [
            'page'       => $this->page,
            'page_count' => $this->pageCount,
            'per_page'   => $this->perPage,
            'has_prev'   => $this->hasPrev(),
            'prev_url'   => $this->hasPrev() ? $urlForPage($this->page - 1) : '',
            'has_next'   => $this->hasNext(),
            'next_url'   => $this->hasNext() ? $urlForPage($this->page + 1) : '',
            'has_first'  => $this->page > 1,
            'first_url'  => $this->page > 1 ? $urlForPage(1) : '',
            'has_last'   => $this->page < $this->pageCount,
            'last_url'   => $this->page < $this->pageCount ? $urlForPage($this->pageCount) : '',
            'pages'      => $pages,
        ];
    }
}