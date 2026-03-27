<?php

declare(strict_types = 1);

namespace AstrX\Template;

/**
 * Immutable snapshot of the comment pagination state.
 * Registered by CommentController so that DefaultTemplateContext::finalise()
 * can generate all comment pagination URLs with the correct URL prefix
 * (from SubPageState if available, or bare page URL otherwise).
 * toPathSegments() and toQueryParams() produce the comment-specific URL
 * parameters using right-to-left trailing-default stripping.
 */
final class CommentState
{
    /** Default order for comments. */
    public const string DEFAULT_ORDER = 'asc';
    /** Default indent mode (1 = nested). */
    public const int    DEFAULT_INDENT = 1;

    public function __construct(
        /** Translated page URL identifier — used for bare-URL generation on non-SubPage pages. */
        public readonly string $resolvedPageUrlId,
        /** Current comment page number (cp). */ public readonly int $page,
        /** Current comment order: 'asc' or 'desc' (co). */
        public readonly string $order,
        /** Current comment items-per-page; 0 = show all (cs). */
        public readonly int $perPage,
        /** Current comment indent mode: 1 = nested, 0 = flat (ci). */
        public readonly int $indent,
        /** Default items-per-page (from CommentService config). */
        public readonly int $defaultPerPage,
        /** Total comment pages — used by finalise() to generate prev/next/first/last. */
        public readonly int $pageCount,
        /** Window size for windowed page list (pages shown either side of current). */
        public readonly int $pageWindow = 3,
    ) {
    }

    // -------------------------------------------------------------------------

    /**
     * Build rewrite-mode path segments for this comment state with
     * right-to-left trailing-default stripping.
     * Segment order: cp / co / cs / ci
     * All defaults:  1  / asc / defaultPerPage / 1  → []  (bare page URL)
     * @return list<string>
     */
    public function toPathSegments()
    : array
    {
        $segs = [];
        if ($this->indent !== self::DEFAULT_INDENT) {
            array_unshift($segs, (string)$this->indent);
        }
        if ($this->perPage !== $this->defaultPerPage || $segs !== []) {
            array_unshift($segs, (string)$this->perPage);
        }
        if ($this->order !== self::DEFAULT_ORDER || $segs !== []) {
            array_unshift($segs, $this->order);
        }
        if ($this->page !== 1 || $segs !== []) {
            array_unshift($segs, (string)$this->page);
        }

        return $segs;
    }

    /**
     * Build query-mode key=val pairs for non-default comment params.
     * Used for:
     *   - extraQuery on SubPage links (query mode)
     *   - hidden inputs in the news filter form
     * @return array<string, scalar>
     */
    public function toQueryParams()
    : array
    {
        $q = [];
        if ($this->page !== 1) {
            $q['cp'] = $this->page;
        }
        if ($this->order !== self::DEFAULT_ORDER) {
            $q['co'] = $this->order;
        }
        if ($this->perPage !== $this->defaultPerPage) {
            $q['cs'] = $this->perPage;
        }
        if ($this->indent !== self::DEFAULT_INDENT) {
            $q['ci'] = $this->indent;
        }

        return $q;
    }

    /**
     * Return a new instance with a different page number (all other fields unchanged).
     * Used by finalise() to generate per-page URLs without mutating the original state.
     */
    public function withPage(int $page)
    : self {
        return new self(
            $this->resolvedPageUrlId,
            $page,
            $this->order,
            $this->perPage,
            $this->indent,
            $this->defaultPerPage,
            $this->pageCount,
            $this->pageWindow,
        );
    }

    /** Return a new instance with a different order. */
    public function withOrder(string $order)
    : self {
        return new self(
            $this->resolvedPageUrlId,
            $this->page,
            $order,
            $this->perPage,
            $this->indent,
            $this->defaultPerPage,
            $this->pageCount,
            $this->pageWindow,
        );
    }

    /** Return a new instance with a different per-page value. */
    public function withPerPage(int $perPage)
    : self {
        return new self(
            $this->resolvedPageUrlId,
            $this->page,
            $this->order,
            $perPage,
            $this->indent,
            $this->defaultPerPage,
            $this->pageCount,
            $this->pageWindow,
        );
    }

    /** Return a new instance with a different indent mode. */
    public function withIndent(int $indent)
    : self {
        return new self(
            $this->resolvedPageUrlId,
            $this->page,
            $this->order,
            $this->perPage,
            $indent,
            $this->defaultPerPage,
            $this->pageCount,
            $this->pageWindow,
        );
    }
}