<?php
declare(strict_types=1);

namespace AstrX\Pagination;

use AstrX\Http\Request;

/**
 * Immutable pagination value object.
 *
 * Lifecycle:
 *   1. Build from request via fromRequest() — reads pn/order/show params.
 *   2. Use offset() and perPage to query the data layer.
 *   3. Call withTotal($count) — returns new instance with pageCount computed.
 *   4. Call toTemplateVars($urlForPage) — flat array for the template engine.
 *
 * Query parameter keys (all optional):
 *   pn    — 1-based page number         (int,    default: 1)
 *   show  — items per page; 0 = all     (int,    default: $defaultPerPage)
 *   order — 'asc' or 'desc'             (string, default: 'desc')
 *
 * Note: 'pn' is used instead of 'page' to avoid conflicting with the routing
 * key that ContentManager writes for the page slug (e.g. page=main).
 * 'order' is used instead of 'desc' for readability in URLs.
 *
 * Controllers populate Request::query() with values parsed from path segments
 * (in rewrite mode) before calling fromRequest(), so this class doesn't need
 * to know whether the URL is rewrite or query mode.
 *
 * Reusable for any paginated list (news, comments, admin tables, …).
 */
final class Pagination
{
    public readonly int    $page;
    public readonly int    $perPage;
    public readonly bool   $descending;
    public readonly string $order;   // 'asc' or 'desc' — canonical string form
    public readonly int    $total;
    public readonly int    $pageCount;

    private function __construct(
        int    $page,
        int    $perPage,
        bool   $descending,
        int    $total,
        int    $pageCount,
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
    ): self {
        // valueOr() instead of unwrap(): if the param exists but can't be
        // converted, fall back to the default rather than throwing.
        $page = $request->query()->getInt('pn', 1)->valueOr(1) ?? 1;
        $page = max(1, $page);

        $perPage = $request->query()->getInt('show', $defaultPerPage)
                       ->valueOr($defaultPerPage) ?? $defaultPerPage;
        $perPage = max(0, $perPage);

        // 'order' is a string: 'asc' or 'desc'.
        // Any unrecognised value falls back to the configured default.
        $rawOrder   = $request->query()->get('order');
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

    /**
     * Return a new Pagination with total and pageCount populated.
     * Call after fetching the count from the data layer.
     */
    public function withTotal(int $total): self
    {
        $pageCount = $this->isUnpaged()
            ? 1
            : (int) ceil($total / $this->perPage);

        return new self(
            $this->page,
            $this->perPage,
            $this->descending,
            $total,
            max(1, $pageCount),
        );
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /** SQL OFFSET value. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** True when all items should be returned without a LIMIT clause. */
    public function isUnpaged(): bool
    {
        return $this->perPage === 0;
    }

    // -------------------------------------------------------------------------
    // Navigation helpers (valid after withTotal)
    // -------------------------------------------------------------------------

    public function hasPrev(): bool { return $this->page > 1; }
    public function hasNext(): bool { return $this->page < $this->pageCount; }

    // -------------------------------------------------------------------------
    // Template integration
    // -------------------------------------------------------------------------

    /**
     * Flatten pagination state into a template-ready array.
     *
     * @param callable(int $pageNumber): string $urlForPage
     *        Returns the URL for a given page number. The controller builds
     *        this closure using UrlGenerator::toSubPage so that the URL
     *        respects the current routing mode.
     *
     * Produced keys:
     *   page, page_count, per_page, has_prev, has_next, prev_url, next_url
     *
     * @return array<string, mixed>
     */
    public function toTemplateVars(callable $urlForPage): array
    {
        return [
            'page'       => $this->page,
            'page_count' => $this->pageCount,
            'per_page'   => $this->perPage,
            'has_prev'   => $this->hasPrev(),
            'has_next'   => $this->hasNext(),
            'prev_url'   => $this->hasPrev() ? $urlForPage($this->page - 1) : '',
            'next_url'   => $this->hasNext() ? $urlForPage($this->page + 1) : '',
        ];
    }
}