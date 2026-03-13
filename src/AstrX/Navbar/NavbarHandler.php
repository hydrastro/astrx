<?php

declare(strict_types = 1);

namespace AstrX\Navbar;

use AstrX\Config\Config;
use AstrX\I18n\Translator;
use AstrX\Routing\CurrentUrl;
use PDO;

/**
 * Loads and resolves navbar entries for a given navbar ID.
 * Returns a flat list of template-ready arrays compatible with the
 * {{#navbar}} section in the template engine:
 *   [
 *     ['name' => 'Home', 'url' => '/en/main', 'highlight' => true],
 *     ['name' => 'User', 'url' => '/en/user', 'highlight' => false],
 *     ...
 *   ]
 * Entries are grouped by pin (ordered by pin.sort_order), then sorted
 * within each pin according to its sort_mode:
 *   0 = alphabetical by resolved display name
 *   1 = custom, by entry.sort_order
 * Highlight: an entry is highlighted when the entry's internal page_id
 * appears in the current page's ancestor set (which includes the page
 * itself via the self-referencing closure row at depth=0).
 */
final class NavbarHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Translator $translator,
        private readonly Config $config,
        private readonly CurrentUrl $currentUrl,
    ) {
    }

    /**
     * @param int                                         $navbarId      row id from the `navbar` table
     * @param list<array{id:int,url_id:string,i18n:bool}> $pageAncestors current page + its closure ancestors
     *
     * @return list<array{name:string,url:string,highlight:bool}>
     */
    public function getNavbarEntries(int $navbarId, array $pageAncestors)
    : array {
        $rows = $this->fetchRows($navbarId);
        if ($rows === []) {
            return [];
        }

        $ancestorIds = array_column($pageAncestors, 'id');
        $pins = $this->groupAndSortByPin($rows);

        $entries = [];
        foreach ($pins as $pin) {
            foreach ($pin as $row) {
                $entries[] = $this->buildEntry($row, $ancestorIds);
            }
        }

        return $entries;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function fetchRows(int $navbarId)
    : array {
        $stmt = $this->pdo->prepare(
            'SELECT
                `id`,
                `internal`,
                `name`,
                `i18n`,
                `active`,
                `entry_sort_order`,
                `pin_id`,
                `pin_sort_order`,
                `pin_sort_mode`,
                `page_id`,
                `url`,
                `url_id`,
                `page_i18n`
             FROM `resolved_navbar`
             WHERE `navbar_id` = :navbar_id
               AND `active`    = 1'
        );
        $stmt->execute(['navbar_id' => $navbarId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Groups rows into pins ordered by pin_sort_order, then sorts entries
     * within each pin by its sort_mode.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<list<array<string,mixed>>>
     */
    private function groupAndSortByPin(array $rows)
    : array {
        /** @var array<int, array{sort_order:int, sort_mode:int, entries:list<array<string,mixed>>}> $pins */
        $pins = [];

        foreach ($rows as $row) {
            $pinId = (int)$row['pin_id'];
            if (!isset($pins[$pinId])) {
                $pins[$pinId] = [
                    'sort_order' => (int)$row['pin_sort_order'],
                    'sort_mode' => (int)$row['pin_sort_mode'],
                    'entries' => [],
                ];
            }
            $pins[$pinId]['entries'][] = $row;
        }

        // Sort pins by their own sort_order.
        uasort($pins, static fn(array $a, array $b)
        : int => $a['sort_order'] <=> $b['sort_order']);

        $result = [];
        foreach ($pins as $pin) {
            $entries = $pin['entries'];

            if ($pin['sort_mode'] === 0) {
                // Alphabetical: sort by resolved display name so that i18n
                // entries are ordered correctly for the current locale.
                usort(
                    $entries,
                    fn(array $a, array $b)
                    : int => strcmp(
                        $this->resolveName($a),
                        $this->resolveName($b),
                    ),
                );
            } else {
                // Custom: sort by the entry's explicit sort_order value.
                usort(
                    $entries,
                    static fn(array $a, array $b)
                    : int => (int)$a['entry_sort_order']
                             <=>
                             (int)$b['entry_sort_order'],
                );
            }

            $result[] = $entries;
        }

        return $result;
    }

    /** Resolves a row's display name through the Translator when i18n=1. */
    private function resolveName(array $row)
    : string {
        $name = (string)$row['name'];

        return ((bool)$row['i18n']) ?
            $this->translator->t($name, fallback: $name) : $name;
    }

    /**
     * Builds the template-ready entry array for one navbar row.
     *
     * @param array<string,mixed> $row
     * @param list<int>           $ancestorIds IDs of the current page + its ancestors
     *
     * @return array{name:string,url:string,highlight:bool}
     */
    private function buildEntry(array $row, array $ancestorIds)
    : array {
        $name = $this->resolveName($row);

        if ((bool)$row['internal']) {
            $urlId = (string)$row['url_id'];
            $i18n = (bool)$row['page_i18n'];
            $resolved = $i18n ? $this->translator->t($urlId, fallback: $urlId) :
                $urlId;

            $url = $this->buildInternalUrl($resolved);
            $highlight = in_array((int)$row['page_id'], $ancestorIds, true);
        } else {
            $url = (string)$row['url'];
            $highlight = false;
        }

        return ['name' => $name, 'url' => $url, 'highlight' => $highlight];
    }

    /**
     * Builds the URL for an internal page using the same routing rules
     * that the rest of the application follows.
     * URL-rewrite mode:  /{basePath}/{locale}/{resolvedUrlId}
     * Query-string mode: {entryPoint}?{localeKey}={locale}&{pageKey}={resolvedUrlId}
     */
    private function buildInternalUrl(string $resolvedUrlId)
    : string {
        $urlRewrite = (bool)$this->config->getConfig(
            'Routing',
            'url_rewrite',
            true
        );
        $basePath = (string)$this->config->getConfig(
            'Routing',
            'base_path',
            '/'
        );
        $localeKey = (string)$this->config->getConfig(
            'Routing',
            'locale_key',
            'lang'
        );
        $pageKey = (string)$this->config->getConfig(
            'Routing',
            'page_key',
            'page'
        );
        $entryPoint = (string)$this->config->getConfig(
            'Routing',
            'entry_point',
            'index.php'
        );

        $locale = (string)$this->currentUrl->get($localeKey, '');

        if ($urlRewrite) {
            $base = rtrim($basePath, '/');

            return $locale !== '' ?
                $base . '/' . $locale . '/' . $resolvedUrlId :
                $base . '/' . $resolvedUrlId;
        }

        // Query-string fallback.
        $query = $pageKey . '=' . rawurlencode($resolvedUrlId);
        if ($locale !== '') {
            $query = $localeKey . '=' . rawurlencode($locale) . '&' . $query;
        }

        return $entryPoint . '?' . $query;
    }
}