<?php

declare(strict_types = 1);

namespace AstrX\Template;

/**
 * Immutable snapshot of the primary controller's pagination state.
 * Registered by the primary page controller (e.g. MainController) so that
 * DefaultTemplateContext::finalise() can generate both SubPage pagination URLs
 * and comment URLs that correctly prefix the comment path segments after
 * the primary sub-page segments.
 * Query-mode key names are included because finalise() needs them to build
 * hidden form inputs that preserve news state through the comment filter form.
 */
final class SubPageState
{
    public function __construct(
        /** Already-translated URL identifier (e.g. 'main', 'news'). */
        public readonly string $resolvedUrlId,
        /** Current page number. */ public readonly int $page,
        /** Current order word as it appears in the URL (may be localised). */
        public readonly string $order,
        /** Current items-per-page. */ public readonly int $perPage,
        /** Default page number (almost always 1). */
        public readonly int $defaultPage,
        /** Default order word for this page type. */
        public readonly string $defaultOrder,
        /** Default items-per-page for this page type. */
        public readonly int $defaultPerPage,
        /** Query-mode key for page number (e.g. 'pn'). */
        public readonly string $pnKey = 'pn',
        /** Query-mode key for order (e.g. 'order'). */
        public readonly string $orderKey = 'order',
        /** Query-mode key for items-per-page (e.g. 'show'). */
        public readonly string $showKey = 'show',
    ) {
    }
}