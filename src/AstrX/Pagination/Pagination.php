<?php

declare(strict_types = 1);

namespace AstrX\Pagination;

use AstrX\Http\Request;
use AstrX\Routing\UrlGenerator;

/**
 * Immutable pagination value object.
 * Lifecycle:
 *   1. Build from the current request (parses page/show/desc query params).
 *   2. Use offset() and perPage to query the data layer.
 *   3. Call withTotal($count) once the total item count is known —
 *      returns a new instance with pageCount, hasPrev, hasNext computed.
 *   4. Call toTemplateVars() to get a flat array ready for the template engine.
 * Reusable for any paginated list (news, comments, admin tables, …).
 * Query parameters (all optional, all overridable via constructor defaults):
 *   ?page=<int>   1-based page number          (default: 1)
 *   ?show=<int>   items per page, 0 = all      (default: $defaultPerPage)
 *   ?desc=<bool>  newest-first when true        (default: $defaultDescending)
 */
final class Pagination
{
    public readonly int $page;
    public readonly int $perPage;
    public readonly bool $descending;
    public readonly int $total;
    public readonly int $pageCount;

    private function __construct(
        int $page,
        int $perPage,
        bool $descending,
        int $total,
        int $pageCount,
    ) {
        $this->page = $page;
        $this->perPage = $perPage;
        $this->descending = $descending;
        $this->total = $total;
        $this->pageCount = $pageCount;
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public static function fromRequest(
        Request $request,
        int $defaultPerPage = 20,
        bool $defaultDescending = true,
    )
    : self {
        // valueOr() instead of unwrap(): if the parameter exists but is not a
        // valid int/bool, the Result is err and unwrap() would throw. valueOr()
        // gracefully falls back to the default.
        $page = $request->query()->getInt('page', 1)->valueOr(1)??1;
        $page = max(1, $page);

        $perPage = $request->query()->getInt('show', $defaultPerPage)->valueOr(
            $defaultPerPage
        )??$defaultPerPage;
        $perPage = max(0, $perPage);

        $descending = $request->query()
                          ->getBool('desc', $defaultDescending)
                          ->valueOr($defaultDescending)??$defaultDescending;

        return new self($page, $perPage, $descending, 0, 0);
    }

    // -------------------------------------------------------------------------
    // Immutable update
    // -------------------------------------------------------------------------

    /**
     * Return a new Pagination with total and pageCount populated.
     * Call this after fetching the count from the data layer.
     */
    public function withTotal(int $total)
    : self {
        $pageCount = $this->isUnpaged() || $this->perPage === 0 ? 1 :
            (int)ceil($total / $this->perPage);

        return new self(
            $this->page,
            $this->perPage,
            $this->descending,
            $total,
            max(1, $pageCount)
        );
    }

    // -------------------------------------------------------------------------
    // Query helpers (used by the data layer before withTotal)
    // -------------------------------------------------------------------------

    /** SQL OFFSET value. */
    public function offset()
    : int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** True when all items should be returned without a LIMIT clause. */
    public function isUnpaged()
    : bool
    {
        return $this->perPage === 0;
    }

    // -------------------------------------------------------------------------
    // Navigation helpers (valid after withTotal)
    // -------------------------------------------------------------------------

    public function hasPrev()
    : bool
    {
        return $this->page > 1;
    }

    public function hasNext()
    : bool
    {
        return $this->page < $this->pageCount;
    }

    // -------------------------------------------------------------------------
    // Template integration
    // -------------------------------------------------------------------------

    /**
     * Flatten pagination state into a template-ready array.
     * The $resolvedPageUrlId must already be translated for the current locale
     * (e.g. 'principale' in IT, 'main' in EN). The caller resolves this via
     * the Translator before passing it here.
     * Produced keys (all available at the top level of the template context):
     *   page, page_count, has_prev, has_next, prev_url, next_url, per_page
     *
     * @param array<string, scalar> $extraParams Additional params preserved across pages
     *                                           (e.g. ['desc' => 0, 'show' => 10]).
     *
     * @return array<string, mixed>
     */
    public function toTemplateVars(
        UrlGenerator $urlGen,
        string $resolvedPageUrlId,
        array $extraParams = [],
    )
    : array {
        $prevUrl = $this->hasPrev() ?
            $urlGen->toPage(
                $resolvedPageUrlId,
                array_merge($extraParams, ['page' => $this->page - 1])
            ) : '';

        $nextUrl = $this->hasNext() ?
            $urlGen->toPage(
                $resolvedPageUrlId,
                array_merge($extraParams, ['page' => $this->page + 1])
            ) : '';

        return [
            'page' => $this->page,
            'page_count' => $this->pageCount,
            'per_page' => $this->perPage,
            'has_prev' => $this->hasPrev(),
            'has_next' => $this->hasNext(),
            'prev_url' => $prevUrl,
            'next_url' => $nextUrl,
        ];
    }
}