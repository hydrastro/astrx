<?php
declare(strict_types=1);

namespace AstrX\Navbar;

use AstrX\I18n\Translator;
use AstrX\Routing\UrlGenerator;
use PDO;

/**
 * Loads and resolves navbar entries for a given navbar ID.
 *
 * Returns a flat list of template-ready arrays:
 *   [['name' => 'Home', 'url' => '/en/main', 'highlight' => true], ...]
 *
 * Entries are grouped by pin (ordered by pin.sort_order), then sorted within
 * each pin according to its sort_mode:
 *   0 = alphabetical by resolved display name
 *   1 = custom, by entry.sort_order
 *
 * Highlight: an entry is highlighted when the entry's internal page_id
 * appears in the current page's ancestor set (which includes the page itself
 * via the self-referencing closure row at depth=0).
 */
final class NavbarHandler
{
    public function __construct(
        private readonly PDO          $pdo,
        private readonly Translator   $translator,
        private readonly UrlGenerator $urlGenerator,
    ) {}

    /**
     * @param int                                         $navbarId
     * @param list<array{id:int,url_id:string,i18n:bool}> $pageAncestors
     * @return list<array{name:string,url:string,highlight:bool}>
     */
    public function getNavbarEntries(int $navbarId, array $pageAncestors): array
    {
        $rows = $this->fetchRows($navbarId);
        if ($rows === []) {
            return [];
        }

        $ancestorIds = array_column($pageAncestors, 'id');
        $pins        = $this->groupAndSortByPin($rows);

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
    private function fetchRows(int $navbarId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `id`, `internal`, `name`, `i18n`, `active`,
                    `entry_sort_order`, `pin_id`, `pin_sort_order`, `pin_sort_mode`,
                    `page_id`, `url`, `url_id`, `page_i18n`
               FROM `resolved_navbar`
              WHERE `navbar_id` = :navbar_id
                AND `active`    = 1'
        );
        $stmt->execute(['navbar_id' => $navbarId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>               $rows
     * @return list<list<array<string,mixed>>>
     */
    private function groupAndSortByPin(array $rows): array
    {
        /** @var array<int, array{sort_order:int,sort_mode:int,entries:list<array<string,mixed>>}> */
        $pins = [];

        foreach ($rows as $row) {
            $pinId = (int) $row['pin_id'];
            if (!isset($pins[$pinId])) {
                $pins[$pinId] = [
                    'sort_order' => (int) $row['pin_sort_order'],
                    'sort_mode'  => (int) $row['pin_sort_mode'],
                    'entries'    => [],
                ];
            }
            $pins[$pinId]['entries'][] = $row;
        }

        uasort($pins, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        $result = [];
        foreach ($pins as $pin) {
            $entries = $pin['entries'];

            if ($pin['sort_mode'] === 0) {
                usort($entries, fn(array $a, array $b): int =>
                strcmp($this->resolveName($a), $this->resolveName($b))
                );
            } else {
                usort($entries, static fn(array $a, array $b): int =>
                    (int) $a['entry_sort_order'] <=> (int) $b['entry_sort_order']
                );
            }

            $result[] = $entries;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function resolveName(array $row): string
    {
        $name = (string) $row['name'];
        if (!(bool) $row['i18n']) {
            return $name;
        }
        // Try {name}.label first (display text, from Navbar lang file).
        // Fall back to the plain key so existing setups without .label keys still work.
        $label = $this->translator->t($name . '.label', fallback: '');
        return $label !== '' ? $label : $this->translator->t($name, fallback: $name);
    }

    /**
     * @param  array<string,mixed> $row
     * @param  list<int>           $ancestorIds
     * @return array{name:string,url:string,highlight:bool}
     */
    private function buildEntry(array $row, array $ancestorIds): array
    {
        $name = $this->resolveName($row);

        if ((bool) $row['internal']) {
            $urlId    = (string) $row['url_id'];
            $resolved = ((bool) $row['page_i18n'])
                ? $this->translator->t($urlId, fallback: $urlId)
                : $urlId;

            $url       = $this->urlGenerator->toPage($resolved);
            $highlight = in_array((int) $row['page_id'], $ancestorIds, true);
        } else {
            $url       = (string) $row['url'];
            $highlight = false;
        }

        return ['name' => $name, 'url' => $url, 'highlight' => $highlight];
    }
}
