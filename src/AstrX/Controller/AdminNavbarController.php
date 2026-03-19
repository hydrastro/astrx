<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Full management for all three navbars (public=1, user=2, admin=3).
 *
 * Each navbar contains one or more PINS (groups). Each pin has:
 *   - sort_order: position of this group within the navbar
 *   - sort_mode:  0 = entries sorted alphabetically
 *                 1 = entries sorted by custom sort_order
 *
 * Each pin contains one or more ENTRIES (internal page links or external URLs).
 *
 * UI flow:
 *   /en/admin-navbar                → lists all three navbars, tabs
 *   /en/admin-navbar?nb=2           → user navbar tab active
 *   /en/admin-navbar?nb=2&pin=3     → pin 3 open for editing
 *   /en/admin-navbar?nb=2&pin=3&entry=5 → entry 5 in edit mode
 */
final class AdminNavbarController extends AbstractController
{
    private const FORM = 'admin_navbar';

    private const NAVBAR_NAMES = [
        1 => 'public',
        2 => 'user',
        3 => 'admin',
    ];

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly PDO                   $pdo,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    // =========================================================================
    // Handle
    // =========================================================================

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_NAVBAR)) {
            http_response_code(403);
            return $this->ok();
        }

        // PRG dispatch
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            // Preserve the active navbar tab on redirect
            $nb  = (int) ($this->request->query()->get('nb') ?? 1);
            $pin = (int) ($this->request->query()->get('pin') ?? 0);
            $qs  = '?nb=' . $nb . ($pin > 0 ? '&pin=' . $pin : '');
            Response::redirect($this->request->uri()->path() . $qs)
                ->send()->drainTo($this->collector);
            exit;
        }

        $activeNavbar = max(1, min(3, (int) ($this->request->query()->get('nb')  ?? 1)));
        $activePin    = (int) ($this->request->query()->get('pin')   ?? 0);
        $activeEntry  = (int) ($this->request->query()->get('entry') ?? 0);

        // Build full data tree for all three navbars
        $navbars = $this->loadAllNavbars();

        // Editing state — flatten to named vars so Mustache doesn't iterate
        $editingPin   = false;
        $editingEntry = false;
        if ($activePin > 0) {
            $pinRow = $this->findPin($navbars, $activeNavbar, $activePin);
            if ($pinRow !== null) {
                $editingPin = true;
                $this->ctx->set('ep_id',         $activePin);
                $this->ctx->set('ep_sort_order', $pinRow['sort_order']);
                $this->ctx->set('ep_sort_mode',  (int) $pinRow['sort_mode']);
                $this->ctx->set('ep_alpha',       $pinRow['sort_mode'] === 0);
                $this->ctx->set('ep_custom',      $pinRow['sort_mode'] === 1);
            }
        }
        if ($activeEntry > 0) {
            $entryRow = $this->loadEntry($activeEntry);
            if ($entryRow !== null) {
                $editingEntry = true;
                $this->ctx->set('ee_id',         $activeEntry);
                $this->ctx->set('ee_name',       $entryRow['name']);
                $this->ctx->set('ee_i18n',       (bool) $entryRow['i18n']);
                $this->ctx->set('ee_internal',   (bool) $entryRow['internal']);
                $this->ctx->set('ee_external',   !(bool) $entryRow['internal']);
                $this->ctx->set('ee_url',        $entryRow['url'] ?? '');
                $this->ctx->set('ee_page_id',    (int) ($entryRow['page_id'] ?? 0));
                $this->ctx->set('ee_sort_order', (int) $entryRow['sort_order']);
                $this->ctx->set('ee_active',     (bool) $entryRow['active']);
                $this->ctx->set('ee_pin_id',     (int) $entryRow['pin_id']);
            }
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());
        $pages     = $this->loadPages();


        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $this->ctx->set('active_navbar',  $activeNavbar);
        $this->ctx->set('active_pin',     $activePin);
        // Edit state is now inline per-row; top-level forms no longer needed
        $this->ctx->set('has_edit_pin',   false);
        $this->ctx->set('has_edit_entry', false);
        $this->ctx->set('available_pages', $pages);
        $this->ctx->set('base_url',       $this->request->uri()->path());

        // Decorate navbars: active flag, navbar_id, pin_id, editing flags
        foreach ($navbars as &$nb) {
            $nb['active'] = ($nb['id'] === $activeNavbar);
            foreach ($nb['pins'] as &$pin) {
                $pin['navbar_id'] = $nb['id'];
                $pinIsEditing = ($pin['id'] === $activePin && $nb['id'] === $activeNavbar);
                $pin['editing'] = $pinIsEditing ? $pin : false;
                foreach ($pin['entries'] as &$entry) {
                    $entry['navbar_id'] = $nb['id'];
                    $entry['pin_id']    = $pin['id'];
                    if ($entry['id'] === $activeEntry) {
                        $entryCtx = $entry;
                        // Mark the selected page option
                        $editingPageId = (int) ($entry['page_id'] ?? 0);
                        $pagesWithSel = [];
                        foreach ($this->loadPages() as $pg) {
                            $pg['selected'] = ((int) $pg['id'] === $editingPageId);
                            $pagesWithSel[] = $pg;
                        }
                        $entryCtx['available_pages'] = $pagesWithSel;
                        $entry['editing'] = $entryCtx;
                    } else {
                        $entry['editing'] = false;
                    }
                }
                unset($entry);
            }
            unset($pin);
        }
        unset($nb);
        $this->ctx->set('navbars', $navbars);

        // Navbar tab labels
        $this->ctx->set('nb_public', $this->t->t('admin.navbar.nb_public'));
        $this->ctx->set('nb_user',   $this->t->t('admin.navbar.nb_user'));
        $this->ctx->set('nb_admin',  $this->t->t('admin.navbar.nb_admin'));

        $this->setI18n();
        return $this->ok();
    }

    // =========================================================================
    // Form processing
    // =========================================================================

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action    = (string) ($posted['action']    ?? '');
        $navbarId  = max(1, min(3, (int) ($posted['navbar_id'] ?? 1)));
        $pinId     = (int) ($posted['pin_id']     ?? 0);
        $entryId   = (int) ($posted['entry_id']   ?? 0);

        switch ($action) {
            // ── Pin actions ───────────────────────────────────────────────────
            case 'add_pin':
                $sortOrder = (int) ($posted['sort_order'] ?? 0);
                $sortMode  = (int) ($posted['sort_mode']  ?? 0);
                $this->addPin($navbarId, $sortOrder, $sortMode);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_added'));
                break;

            case 'update_pin':
                $sortOrder = (int) ($posted['sort_order'] ?? 0);
                $sortMode  = (int) ($posted['sort_mode']  ?? 0);
                $this->updatePin($pinId, $sortOrder, $sortMode);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_updated'));
                break;

            case 'delete_pin':
                $this->deletePin($pinId);
                $this->flash->set('success', $this->t->t('admin.navbar.pin_deleted'));
                break;

            // ── Entry actions ─────────────────────────────────────────────────
            case 'add_entry':
                $name      = trim((string) ($posted['name']       ?? ''));
                $type      = (string) ($posted['type']            ?? 'external');
                $url       = trim((string) ($posted['url']        ?? ''));
                $pageId    = (int) ($posted['page_id']            ?? 0);
                $sortOrder = (int) ($posted['sort_order']         ?? 0);
                $active    = !empty($posted['active']) ? 1 : 0;
                $i18n      = !empty($posted['i18n'])   ? 1 : 0;
                if ($name !== '') {
                    $this->addEntry($pinId, $name, $i18n, $type, $url, $pageId, $sortOrder, $active);
                    $this->flash->set('success', $this->t->t('admin.navbar.added'));
                }
                break;

            case 'update_entry':
                $name      = trim((string) ($posted['name']       ?? ''));
                $type      = (string) ($posted['type']            ?? 'external');
                $url       = trim((string) ($posted['url']        ?? ''));
                $pageId    = (int) ($posted['page_id']            ?? 0);
                $sortOrder = (int) ($posted['sort_order']         ?? 0);
                $active    = !empty($posted['active']) ? 1 : 0;
                $i18n      = !empty($posted['i18n'])   ? 1 : 0;
                $this->updateEntry($entryId, $name, $i18n, $type, $url, $pageId, $sortOrder, $active);
                $this->flash->set('success', $this->t->t('admin.navbar.updated'));
                break;

            case 'toggle_entry':
                $this->toggleEntry($entryId);
                break;

            case 'delete_entry':
                $this->deleteEntry($entryId);
                $this->flash->set('success', $this->t->t('admin.navbar.deleted'));
                break;
        }
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    /**
     * Returns data structure for all navbars:
     * [ navbar_id => [ 'name' => '...', 'pins' => [ pin_id => ['sort_order'=>..., 'sort_mode'=>..., 'entries'=>[...]] ] ] ]
     */
    private function loadAllNavbars(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT n.id AS navbar_id, n.name AS navbar_name,
                        p.id AS pin_id, p.sort_order AS pin_sort_order, p.sort_mode,
                        e.id AS entry_id, e.name AS entry_name, e.i18n, e.internal,
                        e.active, e.sort_order AS entry_sort_order,
                        ni.page_id, ne.url, pg.url_id AS page_url_id
                   FROM navbar n
                   LEFT JOIN navbar_pin p       ON p.navbar_id = n.id
                   LEFT JOIN navbar_entry e      ON e.pin_id   = p.id
                   LEFT JOIN navbar_internal ni  ON ni.id      = e.id
                   LEFT JOIN navbar_external ne  ON ne.id      = e.id
                   LEFT JOIN page pg             ON pg.id      = ni.page_id
                   ORDER BY n.id, p.sort_order, e.sort_order, e.name'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
            return [];
        }

        $navbars = [];
        foreach ($rows as $row) {
            $nid = (int) $row['navbar_id'];
            $pid = $row['pin_id'] !== null ? (int) $row['pin_id'] : null;
            $eid = $row['entry_id'] !== null ? (int) $row['entry_id'] : null;

            if (!isset($navbars[$nid])) {
                $navbars[$nid] = [
                    'id'   => $nid,
                    'name' => $row['navbar_name'],
                    'pins' => [],
                ];
            }
            if ($pid !== null && !isset($navbars[$nid]['pins'][$pid])) {
                $navbars[$nid]['pins'][$pid] = [
                    'id'         => $pid,
                    'sort_order' => (int) $row['pin_sort_order'],
                    'sort_mode'  => (int) $row['sort_mode'],
                    'alpha'      => (int) $row['sort_mode'] === 0,
                    'custom'     => (int) $row['sort_mode'] === 1,
                    'entries'    => [],
                ];
            }
            if ($pid !== null && $eid !== null) {
                $navbars[$nid]['pins'][$pid]['entries'][] = [
                    'id'         => $eid,
                    'name'       => $row['entry_name'],
                    'i18n'       => (bool) $row['i18n'],
                    'internal'   => (bool) $row['internal'],
                    'external'   => !(bool) $row['internal'],
                    'active'     => (bool) $row['active'],
                    'sort_order' => (int) $row['entry_sort_order'],
                    'page_id'    => $row['page_id'] !== null ? (int) $row['page_id'] : null,
                    'page_url_id'=> $row['page_url_id'],
                    'url'        => $row['url'],
                ];
            }
        }

        // Convert pin maps to lists for Mustache iteration
        foreach ($navbars as &$nb) {
            $nb['pins'] = array_values($nb['pins']);
        }
        unset($nb);

        return array_values($navbars);
    }

    private function findPin(array $navbars, int $navbarId, int $pinId): ?array
    {
        foreach ($navbars as $nb) {
            if ($nb['id'] !== $navbarId) {
                continue;
            }
            foreach ($nb['pins'] as $pin) {
                if ($pin['id'] === $pinId) {
                    return $pin;
                }
            }
        }
        return null;
    }

    private function loadEntry(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT e.id, e.pin_id, e.name, e.i18n, e.internal, e.active, e.sort_order,
                        ni.page_id, ne.url
                   FROM navbar_entry e
                   LEFT JOIN navbar_internal ni ON ni.id = e.id
                   LEFT JOIN navbar_external ne ON ne.id = e.id
                  WHERE e.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\PDOException) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadPages(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT p.id, p.url_id, p.file_name, pm.title
                   FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                  WHERE p.hidden = 0
                  ORDER BY p.id'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    // =========================================================================
    // DB writes — pins
    // =========================================================================

    private function addPin(int $navbarId, int $sortOrder, int $sortMode): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO navbar_pin (navbar_id, sort_order, sort_mode)
                 VALUES (:nb, :so, :sm)'
            )->execute([':nb' => $navbarId, ':so' => $sortOrder, ':sm' => $sortMode]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function updatePin(int $id, int $sortOrder, int $sortMode): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE navbar_pin SET sort_order = :so, sort_mode = :sm WHERE id = :id'
            )->execute([':so' => $sortOrder, ':sm' => $sortMode, ':id' => $id]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function deletePin(int $id): void
    {
        try {
            // CASCADE deletes entries and their internal/external subtypes
            $this->pdo->prepare('DELETE FROM navbar_pin WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    // =========================================================================
    // DB writes — entries
    // =========================================================================

    private function addEntry(int $pinId, string $name, int $i18n, string $type,
        string $url, int $pageId, int $sortOrder, int $active): void
    {
        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('INSERT INTO navbar_entry_ids () VALUES ()');
            $newId      = (int) $this->pdo->lastInsertId();
            $isInternal = $type === 'internal' ? 1 : 0;

            $this->pdo->prepare(
                'INSERT INTO navbar_entry (id, pin_id, internal, name, i18n, active, sort_order)
                 VALUES (:id, :pin, :int, :name, :i18n, :active, :sort)'
            )->execute([':id' => $newId, ':pin' => $pinId, ':int' => $isInternal,
                        ':name' => $name, ':i18n' => $i18n,
                        ':active' => $active, ':sort' => $sortOrder]);

            if ($isInternal && $pageId > 0) {
                $this->pdo->prepare(
                    'INSERT INTO navbar_internal (id, page_id) VALUES (:id, :pid)'
                )->execute([':id' => $newId, ':pid' => $pageId]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO navbar_external (id, url) VALUES (:id, :url)'
                )->execute([':id' => $newId, ':url' => $url]);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            $this->emitDiag($e);
        }
    }

    private function updateEntry(int $id, string $name, int $i18n, string $type,
        string $url, int $pageId, int $sortOrder, int $active): void
    {
        try {
            $isInternal = $type === 'internal' ? 1 : 0;
            $this->pdo->prepare(
                'UPDATE navbar_entry
                    SET name = :name, i18n = :i18n, internal = :int,
                        active = :active, sort_order = :sort
                  WHERE id = :id'
            )->execute([':name' => $name, ':i18n' => $i18n, ':int' => $isInternal,
                        ':active' => $active, ':sort' => $sortOrder, ':id' => $id]);

            if ($isInternal) {
                $this->pdo->prepare('DELETE FROM navbar_external WHERE id = :id')
                    ->execute([':id' => $id]);
                $this->pdo->prepare(
                    'INSERT INTO navbar_internal (id, page_id) VALUES (:id, :pid)
                     ON DUPLICATE KEY UPDATE page_id = :pid2'
                )->execute([':id' => $id, ':pid' => $pageId, ':pid2' => $pageId]);
            } else {
                $this->pdo->prepare('DELETE FROM navbar_internal WHERE id = :id')
                    ->execute([':id' => $id]);
                $this->pdo->prepare(
                    'INSERT INTO navbar_external (id, url) VALUES (:id, :url)
                     ON DUPLICATE KEY UPDATE url = :url2'
                )->execute([':id' => $id, ':url' => $url, ':url2' => $url]);
            }
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function toggleEntry(int $id): void
    {
        try {
            $this->pdo->prepare('UPDATE navbar_entry SET active = 1 - active WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (\PDOException) {}
    }

    private function deleteEntry(int $id): void
    {
        try {
            $this->pdo->prepare('DELETE FROM navbar_entry_ids WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (\PDOException) {}
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function setI18n(): void
    {
        $this->ctx->set('admin_navbar_heading', $this->t->t('admin.nav.navbar'));
        $this->ctx->set('label_name',      $this->t->t('admin.field.name'));
        $this->ctx->set('label_type',      $this->t->t('admin.field.type'));
        $this->ctx->set('label_url',       $this->t->t('admin.navbar.url'));
        $this->ctx->set('label_page',      $this->t->t('admin.field.page'));
        $this->ctx->set('label_active',    $this->t->t('admin.field.active'));
        $this->ctx->set('label_sort',      $this->t->t('admin.navbar.sort'));
        $this->ctx->set('label_sort_mode', $this->t->t('admin.navbar.sort_mode'));
        $this->ctx->set('label_i18n',      $this->t->t('admin.navbar.i18n'));
        $this->ctx->set('label_actions',   $this->t->t('admin.field.actions'));
        $this->ctx->set('label_pins',      $this->t->t('admin.navbar.pins'));
        $this->ctx->set('label_entries',   $this->t->t('admin.navbar.entries'));
        $this->ctx->set('type_internal',   $this->t->t('admin.navbar.type_internal'));
        $this->ctx->set('type_external',   $this->t->t('admin.navbar.type_external'));
        $this->ctx->set('sort_alpha',      $this->t->t('admin.navbar.sort_alpha'));
        $this->ctx->set('sort_custom',     $this->t->t('admin.navbar.sort_custom'));
        $this->ctx->set('btn_add',         $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_update',      $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',      $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',        $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_cancel',      $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_toggle',      $this->t->t('admin.btn.toggle'));
        $this->ctx->set('btn_add_pin',     $this->t->t('admin.navbar.btn_add_pin'));
        $this->ctx->set('btn_add_entry',   $this->t->t('admin.navbar.btn_add_entry'));
    }

    private function emitDiag(\PDOException $e): void
    {
        $this->emit(new AdminDbDiagnostic(
                        AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                    ));
    }
}