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
 * Full CRUD for the PUBLIC navbar (id=1).
 * Supports: add (internal page or external URL), edit (name/url/sort/active), delete.
 */
final class AdminNavbarController extends AbstractController
{
    private const FORM      = 'admin_navbar';
    private const NAVBAR_ID = 1;

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

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_NAVBAR)) {
            http_response_code(403);
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($this->request->uri()->path())
                ->send()->drainTo($this->collector);
            exit;
        }

        // Edit mode: ?edit=<id>
        $editId  = (int) ($this->request->query()->get('edit') ?? 0);
        $editing = false;
        if ($editId > 0) {
            $row = $this->loadEntry($editId);
            if ($row !== null) {
                $editing = true;
                $this->ctx->set('editing_id',         $row['id']);
                $this->ctx->set('editing_name',       $row['name']);
                $this->ctx->set('editing_internal',   (bool) $row['internal']);
                $this->ctx->set('editing_url',        $row['url'] ?? '');
                $this->ctx->set('editing_page_id',    (int) ($row['page_id'] ?? 0));
                $this->ctx->set('editing_sort',       (int) $row['sort_order']);
                $this->ctx->set('editing_active',     (bool) $row['active']);
            }
        }

        $entries   = $this->loadEntries();
        $pages     = $this->loadPages();
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('has_editing',     $editing);
        $this->ctx->set('csrf_token',      $csrfToken);
        $this->ctx->set('prg_id',          $prgId);
        $this->ctx->set('navbar_entries',  $entries);
        $this->ctx->set('available_pages', $pages);
        $this->setI18n();
        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action    = (string) ($posted['action']     ?? '');
        $entryId   = (int)    ($posted['entry_id']   ?? 0);
        $name      = trim((string) ($posted['name']  ?? ''));
        $type      = (string) ($posted['type']       ?? 'external');
        $url       = trim((string) ($posted['url']   ?? ''));
        $pageId    = (int)    ($posted['page_id']    ?? 0);
        $sortOrder = (int)    ($posted['sort_order'] ?? 0);
        $active    = !empty($posted['active']) ? 1 : 0;

        switch ($action) {
            case 'add':
                if ($name === '') {
                    return;
                }
                $this->addEntry($name, $type, $url, $pageId, $sortOrder, $active);
                $this->flash->set('success', $this->t->t('admin.navbar.added'));
                break;
            case 'update':
                if ($entryId === 0) {
                    return;
                }
                $this->updateEntry($entryId, $name, $type, $url, $pageId, $sortOrder, $active);
                $this->flash->set('success', $this->t->t('admin.navbar.updated'));
                break;
            case 'toggle':
                $this->toggleActive($entryId);
                break;
            case 'delete':
                $this->deleteEntry($entryId);
                $this->flash->set('success', $this->t->t('admin.navbar.deleted'));
                break;
        }
    }

    // -------------------------------------------------------------------------

    private function addEntry(string $name, string $type, string $url, int $pageId,
        int $sortOrder, int $active): void
    {
        try {
            $this->pdo->beginTransaction();

            $pinStmt = $this->pdo->prepare(
                'SELECT id FROM navbar_pin WHERE navbar_id = :nid ORDER BY sort_order LIMIT 1'
            );
            $pinStmt->execute([':nid' => self::NAVBAR_ID]);
            $pin = $pinStmt->fetchColumn();
            if (!$pin) {
                $this->pdo->rollBack();
                return;
            }

            $this->pdo->exec('INSERT INTO navbar_entry_ids () VALUES ()');
            $newId      = (int) $this->pdo->lastInsertId();
            $isInternal = $type === 'internal' ? 1 : 0;

            $this->pdo->prepare(
                'INSERT INTO navbar_entry (id, pin_id, internal, name, i18n, active, sort_order)
                 VALUES (:id, :pin, :int, :name, 0, :active, :sort)'
            )->execute([':id' => $newId, ':pin' => $pin, ':int' => $isInternal,
                        ':name' => $name, ':active' => $active, ':sort' => $sortOrder]);

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

    private function updateEntry(int $id, string $name, string $type, string $url,
        int $pageId, int $sortOrder, int $active): void
    {
        try {
            $isInternal = $type === 'internal' ? 1 : 0;
            $this->pdo->prepare(
                'UPDATE navbar_entry
                    SET name = :name, internal = :int, active = :active, sort_order = :sort
                  WHERE id = :id'
            )->execute([':name' => $name, ':int' => $isInternal,
                        ':active' => $active, ':sort' => $sortOrder, ':id' => $id]);

            if ($isInternal) {
                // Upsert internal record, remove external if exists
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

    private function deleteEntry(int $id): void
    {
        try {
            $this->pdo->prepare('DELETE FROM navbar_entry_ids WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (\PDOException) {}
    }

    private function toggleActive(int $id): void
    {
        try {
            $this->pdo->prepare('UPDATE navbar_entry SET active = 1 - active WHERE id = :id')
                ->execute([':id' => $id]);
        } catch (\PDOException) {}
    }

    private function loadEntry(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT e.id, e.name, e.internal, e.active, e.sort_order,
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
    private function loadEntries(): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT e.id, e.name, e.internal, e.active, e.sort_order,
                        ni.page_id, ne.url
                   FROM navbar_entry e
                   JOIN navbar_pin np ON np.id = e.pin_id
                   LEFT JOIN navbar_internal ni ON ni.id = e.id
                   LEFT JOIN navbar_external ne ON ne.id = e.id
                  WHERE np.navbar_id = :nid
                  ORDER BY e.sort_order'
            );
            $stmt->execute([':nid' => self::NAVBAR_ID]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function loadPages(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, url_id, file_name FROM page WHERE hidden = 0 ORDER BY id'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_navbar_heading', $this->t->t('admin.nav.navbar'));
        $this->ctx->set('label_name',        $this->t->t('admin.field.name'));
        $this->ctx->set('label_type',        $this->t->t('admin.field.type'));
        $this->ctx->set('label_url',         $this->t->t('admin.navbar.url'));
        $this->ctx->set('label_page',        $this->t->t('admin.field.page'));
        $this->ctx->set('label_active',      $this->t->t('admin.field.active'));
        $this->ctx->set('label_sort',        $this->t->t('admin.navbar.sort'));
        $this->ctx->set('label_actions',     $this->t->t('admin.field.actions'));
        $this->ctx->set('type_internal',     $this->t->t('admin.navbar.type_internal'));
        $this->ctx->set('type_external',     $this->t->t('admin.navbar.type_external'));
        $this->ctx->set('btn_add',           $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_update',        $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',        $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_toggle',        $this->t->t('admin.btn.toggle'));
        $this->ctx->set('btn_edit',          $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_cancel',        $this->t->t('admin.btn.cancel'));
    }

    private function emitDiag(\PDOException $e): void
    {
        $this->emit(new AdminDbDiagnostic(
                        AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                    ));
    }
}