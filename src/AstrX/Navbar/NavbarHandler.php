<?php
declare(strict_types=1);

namespace AstrX\Navbar;

use AstrX\I18n\Translator;
use AstrX\Module\ModuleRegistry;
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
 * Highlight: an entry is highlighted when the entry's internal page_id appears
 * in the current page's ancestor set (which includes the page itself via the
 * self-referencing closure row at depth=0), OR when the current page is a
 * "section subpage" of the entry — its file_name is the entry page's file_name
 * followed by an underscore (e.g. current `chat_settings` / `board_mod`
 * highlights the `chat` / `board` entry). This keeps a section's own top-level
 * navbar entry lit while the visitor is anywhere inside that section, without
 * having to nest every subpage in the page-closure tree.
 */
final class NavbarHandler
{
    public function __construct(
        private readonly PDO            $pdo,
        private readonly Translator     $translator,
        private readonly UrlGenerator   $urlGenerator,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * @param int                                         $navbarId
     * @param list<array{id:int,url_id:string,i18n:bool}> $pageAncestors
     * @param string                                      $currentFileName the current page's file_name (for section-subpage highlighting)
     * @return list<array{name:string,url:string,highlight:bool}>
     */
    public function getNavbarEntries(int $navbarId, array $pageAncestors, string $currentFileName = ''): array
    {
        $rows = $this->fetchRows($navbarId);
        if ($rows === []) {
            return [];
        }

        $ancestorIds = array_column($pageAncestors, 'id');
        $pins        = $this->groupAndSortByPin($rows);

        // Drop links pointing at a disabled module's pages, so turning a module
        // off leaves no navbar entry that would 404 (the page gate hides the
        // target itself). Keyed on page.module, so core names no module here.
        $disabledPageIds = $this->disabledPageIds();

        $entries = [];
        foreach ($pins as $pin) {
            foreach ($pin as $row) {
                /** @var array<string,mixed> $row */
                if ($this->pointsAtDisabledModule($row, $disabledPageIds)) {
                    continue;
                }
                $entries[] = $this->buildEntry($row, $ancestorIds, $currentFileName);
            }
        }

        return $entries;
    }

    /**
     * Page ids owned by a currently-disabled module. Empty (no query) when every
     * module is on — the common case.
     *
     * @return array<int,true>
     */
    private function disabledPageIds(): array
    {
        $disabled = $this->registry->disabledModules();
        if ($disabled === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($disabled), '?'));
        $ids = [];
        try {
            $stmt = $this->pdo->prepare("SELECT `id` FROM `page` WHERE `module` IN ({$placeholders})");
            $stmt->execute(array_values($disabled));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (is_int($id)) {
                    $ids[$id] = true;
                }
            }
        } catch (\PDOException) {
            // page.module predates the ownership migration — no filtering yet.
            return [];
        }
        return $ids;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,true>     $disabledPageIds
     */
    private function pointsAtDisabledModule(array $row, array $disabledPageIds): bool
    {
        if ($disabledPageIds === [] || !(bool) $row['internal']) {
            return false;
        }
        $pageId = is_int($row['page_id'] ?? null) ? $row['page_id'] : 0;
        return isset($disabledPageIds[$pageId]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function fetchRows(int $navbarId): array
    {
        // Non-fatal: a partially-migrated `resolved_navbar` (e.g. missing a column
        // this SELECT names) must degrade to an empty navbar, not 500 the whole
        // page — mirrors disabledPageIds() and the contract documented in
        // ContentManager ("renders with an empty navbar rather than taking down
        // the whole page").
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `internal`, `name`, `i18n`, `active`,
                        `entry_sort_order`, `pin_id`, `pin_sort_order`, `pin_sort_mode`,
                        `page_id`, `url`, `url_id`, `page_file_name`, `page_i18n`
                   FROM `resolved_navbar`
                  WHERE `navbar_id` = :navbar_id
                    AND `active`    = 1'
            );
            $stmt->execute(['navbar_id' => $navbarId]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        } catch (\PDOException) {
            return [];
        }
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
            /** @var array<string,mixed> $row */
            $pinIdV = $row['pin_id'] ?? 0; $pinId = is_int($pinIdV) ? $pinIdV : 0;
            if (!isset($pins[$pinId])) {
                $pins[$pinId] = [
                    'sort_order' => (is_int($row['pin_sort_order'] ?? null) ? $row['pin_sort_order'] : 0),
                    'sort_mode'  => (is_int($row['pin_sort_mode'] ?? null) ? $row['pin_sort_mode'] : 0),
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
                    (is_int($a['entry_sort_order']) ? $a['entry_sort_order'] : 0) <=> (is_int($b['entry_sort_order']) ? $b['entry_sort_order'] : 0)
                );
            }

            $result[] = $entries;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function resolveName(array $row): string
    {
        $name = (is_scalar($row['name']) ? (string)$row['name'] : '');
        if (!(bool) $row['i18n']) {
            return $name;
        }
        // Try {name}.label first (display text, from the Navbar lang file). Probe
        // with has() rather than t()-with-fallback: a navbar entry that ships no
        // .label key is a supported setup (the plain key is used instead), so it
        // must NOT emit a missing-translation NOTICE on every render.
        if ($this->translator->has($name . '.label')) {
            $label = $this->translator->t($name . '.label');
            if ($label !== '') {
                return $label;
            }
        }
        return $this->translator->t($name, fallback: $name);
    }

    /**
     * @param  array<string,mixed> $row
     * @param  list<int>           $ancestorIds
     * @param  string              $currentFileName
     * @return array{name:string,url:string,highlight:bool}
     */
    private function buildEntry(array $row, array $ancestorIds, string $currentFileName): array
    {
        $name = $this->resolveName($row);

        if ((bool) $row['internal']) {
            $urlId    = (is_scalar($row['url_id']) ? (string)$row['url_id'] : '');
            $resolved = ((bool) $row['page_i18n'])
                ? $this->translator->t($urlId, fallback: $urlId)
                : $urlId;

            $url       = $this->urlGenerator->toPage($resolved);
            $pageIdV   = $row['page_id'] ?? 0;
            $highlight = in_array(is_int($pageIdV) ? $pageIdV : 0, $ancestorIds, true);

            // Section-subpage highlight: keep a section's entry lit anywhere
            // inside it (current `chat_settings` → entry `chat`). The trailing
            // underscore stops `board` matching an unrelated `boardgame`.
            if (!$highlight && $currentFileName !== '') {
                $entryFile = is_scalar($row['page_file_name'] ?? null) ? (string) $row['page_file_name'] : '';
                if ($entryFile !== '' && str_starts_with($currentFileName, $entryFile . '_')) {
                    $highlight = true;
                }
            }
        } else {
            $url       = (is_scalar($row['url']) ? (string)$row['url'] : '');
            $highlight = false;
        }

        return ['name' => $name, 'url' => $url, 'highlight' => $highlight];
    }
}
