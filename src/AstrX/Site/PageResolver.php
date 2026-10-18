<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\Http\HttpStatus;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Page\Page;
use AstrX\Page\PageHandler;
use AstrX\Routing\CurrentUrl;

/**
 * Turns the URL's page token into a Page from the page table.
 *
 * The token is matched against each page's TRANSLATED url id first (so
 * /it/utente finds the user page), then as a raw url id. A first tail
 * segment naming a direct child page is followed (/en/user/login → login).
 * Nothing found → the configured error page with a 404 status.
 */
final class PageResolver
{
    public function __construct(
        private readonly Injector   $injector,
        private readonly Config     $config,
        private readonly Translator $translator,
    ) {}

    public function resolve(PageHandler $pageHandler, string $pageToken): Page
    {
        $map = [];
        foreach ($pageHandler->getInternationalizedPageIds() as $row) {
            $urlId    = (string) $row['url_id'];
            $pid      = (int) $row['id'];
            $resolved = $this->translator->t($urlId);
            $map[$resolved] = $pid;
        }

        $page = null;

        if (isset($map[$pageToken])) {
            $page = $pageHandler->getPage($map[$pageToken]);
        }

        if ($page === null) {
            $id   = $pageHandler->getPageIdFromUrlId($pageToken);
            $page = $id !== null ? $pageHandler->getPage($id) : null;
        }

        // Sub-path routing: /en/user/login → pageToken='user', tail[0]='login'
        // If the primary page resolved successfully and there is a tail segment,
        // try to find a direct child page matching that slug. This lets /en/user/login
        // work as an alias for /en/login without any extra DB rows or config.
        if ($page !== null && !$page->hidden) {
            $current  = $this->injector->getClass(CurrentUrl::class);
            if ($current->isOk()) {
                /** @var CurrentUrl $currentUrl */
                $currentUrl = $current->unwrap();
                $tailSlug   = $currentUrl->tailSegment(0);
                if ($tailSlug !== null && $tailSlug !== '') {
                    // Resolve tail slug → candidate page, then confirm it is a
                    // direct child of the current page so /en/user/main cannot
                    // accidentally route to the unrelated main page.
                    $childPage = null;
                    $translatedTail = $map[$tailSlug] ?? null;
                    if ($translatedTail !== null) {
                        // Slug matched an i18n page — verify it is a child.
                        $candidate = $pageHandler->getPage($translatedTail);
                        if ($candidate !== null && !$candidate->hidden) {
                            $ancestorIds = array_column($candidate->ancestors, 'id');
                            if (in_array($page->id, $ancestorIds, true)) {
                                $childPage = $candidate;
                            }
                        }
                    }
                    if ($childPage === null) {
                        // Fallback: raw url_id match restricted to children by SQL.
                        $childPage = $pageHandler->getChildPageBySlug($page->id, $tailSlug);
                    }
                    if ($childPage !== null && !$childPage->hidden) {
                        $page = $childPage;
                        // Consume the tail segment so controllers don't see it.
                        $currentUrl->setTail(array_slice($currentUrl->tail(), 1));
                    }
                }
            }
        }

        if ($page === null) {
            http_response_code(HttpStatus::NOT_FOUND->value);

            // Default is 'error' — the url_id of the error page in the database.
            // Override via ContentManager.config.php: ['error_page_url_id' => 'my_error']
            $errorUrlId = $this->config->getConfig(
                'ContentManager',
                'error_page_url_id',
                'WORDING_ERROR',
            );
            assert(is_string($errorUrlId));

            $eid  = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = $eid !== null ? $pageHandler->getPage($eid) : null;

            if ($page === null) {
                $page = $pageHandler->getFallbackErrorPage($errorUrlId);
            }
        }

        return $page;
    }
}
