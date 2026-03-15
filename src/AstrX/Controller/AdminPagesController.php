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
 * Page management — listing with edit/toggle for meta fields.
 *
 * Editable: title, description, hidden flag, comments flag.
 * NOT editable here: url_id, file_name, i18n, controller — these affect routing
 * and require care. They remain DB-only changes.
 */
final class AdminPagesController extends AbstractController
{
    private const FORM = 'admin_pages';

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
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
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
            $row = $this->loadPage($editId);
            if ($row !== null) {
                $editing = true;
                $this->ctx->set('editing_id',          $row['id']);
                $this->ctx->set('editing_url_id',      $row['url_id']);
                $this->ctx->set('editing_file_name',   $row['file_name']);
                $this->ctx->set('editing_title',       $row['title'] ?? '');
                $this->ctx->set('editing_description', $row['description'] ?? '');
                $this->ctx->set('editing_hidden',      (bool) $row['hidden']);
                $this->ctx->set('editing_comments',    (bool) $row['comments']);
            }
        }

        $pages     = $this->loadPages();
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('has_editing', $editing);
        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $this->ctx->set('page_list',   $pages);
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

        $action      = (string) ($posted['action']      ?? '');
        $id          = (int)    ($posted['page_id']     ?? 0);
        $title       = trim((string) ($posted['title']       ?? ''));
        $description = trim((string) ($posted['description'] ?? ''));
        $hidden      = !empty($posted['hidden'])   ? 1 : 0;
        $comments    = !empty($posted['comments']) ? 1 : 0;

        if ($id === 0) {
            return;
        }

        switch ($action) {
            case 'update':
                $this->updatePage($id, $title, $description, $hidden, $comments);
                $this->flash->set('success', $this->t->t('admin.pages.updated'));
                break;
            case 'toggle_hidden':
                $this->toggleFlag($id, 'hidden');
                break;
            case 'toggle_comments':
                $this->toggleFlag($id, 'comments');
                break;
        }
    }

    // -------------------------------------------------------------------------

    private function updatePage(int $id, string $title, string $description,
        int $hidden, int $comments): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE page SET hidden = :hidden, comments = :comments WHERE id = :id'
            )->execute([':hidden' => $hidden, ':comments' => $comments, ':id' => $id]);

            $this->pdo->prepare(
                'INSERT INTO page_meta (page_id, title, description)
                 VALUES (:id, :title, :desc)
                 ON DUPLICATE KEY UPDATE title = :title2, description = :desc2'
            )->execute([':id' => $id, ':title' => $title, ':desc' => $description,
                        ':title2' => $title, ':desc2' => $description]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function toggleFlag(int $id, string $column): void
    {
        // Allowlist — never let user-supplied column names near SQL
        if (!in_array($column, ['hidden', 'comments'], true)) {
            return;
        }
        try {
            $this->pdo->prepare(
                "UPDATE page SET {$column} = 1 - {$column} WHERE id = :id"
            )->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function loadPage(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.id, p.url_id, p.file_name, p.i18n, p.hidden, p.comments,
                        pm.title, pm.description
                   FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                  WHERE p.id = :id LIMIT 1'
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
                'SELECT p.id, p.url_id, p.file_name, p.i18n, p.hidden, p.comments,
                        pm.title, pm.description
                   FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                   ORDER BY p.id'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_pages_heading', $this->t->t('admin.nav.pages'));
        $this->ctx->set('label_id',           $this->t->t('admin.field.id'));
        $this->ctx->set('label_url_id',       $this->t->t('admin.pages.url_id'));
        $this->ctx->set('label_file_name',    $this->t->t('admin.pages.file_name'));
        $this->ctx->set('label_title',        $this->t->t('admin.field.title'));
        $this->ctx->set('label_description',  $this->t->t('admin.pages.description'));
        $this->ctx->set('label_i18n',         $this->t->t('admin.pages.i18n'));
        $this->ctx->set('label_hidden',       $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_comments',     $this->t->t('admin.pages.comments'));
        $this->ctx->set('label_actions',      $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_edit',           $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_update',         $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_cancel',         $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_toggle',         $this->t->t('admin.btn.toggle'));
        $this->ctx->set('pages_note',         $this->t->t('admin.pages.note'));
    }

    private function emitDiag(\PDOException $e): void
    {
        $this->emit(new AdminDbDiagnostic(
                        AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                    ));
    }
}