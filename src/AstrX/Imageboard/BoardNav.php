<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\I18n\Translator;
use AstrX\Routing\UrlGenerator;

/**
 * Builds the imageboard-wide navbar shown on every board page: the boards home
 * ("Boards"), the Overboard, the cross-board Search, and one entry per active
 * board. Rendered in the site's own navbar markup/classes (.board_top_nav /
 * .board_nav), so it reads as an integrated section nav — the same style as the
 * site header nav — rather than a detached bar.
 *
 * The per-board action nav (Index / Catalog / Search / Feed / Manage) is built
 * in BoardController where the active view is known; this service is only the
 * cross-board bar, so overboard/search/mod pages can share it too. Its Search
 * entry points at the board search with no board filter (search every board);
 * the per-board nav's Search pre-selects that board.
 */
final class BoardNav
{
    public function __construct(
        private readonly BoardRepository $boards,
        private readonly UrlGenerator    $urlGen,
        private readonly Translator      $t,
    ) {}

    /**
     * @param string $active 'home' | 'overboard' | 'search' | a board slug | '' (none)
     * @return list<array{url:string,name:string,highlight:bool}>
     */
    public function topNav(string $active = ''): array
    {
        $base  = $this->urlGen->toPage($this->t->t('WORDING_BOARD'));
        $items = [
            [
                'url'       => $base,
                'name'      => $this->t->t('board.boards_heading'),
                'highlight' => $active === 'home',
            ],
            [
                'url'       => $this->urlGen->toPage($this->t->t('WORDING_BOARD_OVERBOARD')),
                'name'      => $this->t->t('board.overboard_heading'),
                'highlight' => $active === 'overboard',
            ],
            [
                'url'       => $this->urlGen->toPage($this->t->t('WORDING_BOARD_SEARCH')),
                'name'      => $this->t->t('board.search_heading'),
                'highlight' => $active === 'search',
            ],
        ];

        $lr   = $this->boards->listActive();
        $rows = $lr->isOk() ? $lr->unwrap() : [];
        foreach ($rows as $row) {
            $slugRaw = $row['slug'] ?? '';
            $slug    = is_scalar($slugRaw) ? (string) $slugRaw : '';
            if ($slug === '') {
                continue;
            }
            $items[] = [
                'url'       => $base . '/' . rawurlencode($slug),
                'name'      => '/' . $slug . '/',
                'highlight' => $active === $slug,
            ];
        }
        return $items;
    }
}
