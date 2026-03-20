<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\UrlGenerator;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Full page management — listing, editing, adding, deleting.
 *
 * All fields editable:
 *   - url_id, file_name      (routing-critical — shown with a warning)
 *   - title, description     (meta)
 *   - i18n, template, controller, hidden, comments (flags)
 *
 * Adding new pages also creates the required page_closure, page_meta,
 * and page_robots rows.
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
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
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

        // Self-URL: works in both rewrite (/en/admin-banlist) and query mode.
        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)
                ->send()->drainTo($this->collector);
            exit;
        }

        $editId    = (int) ($this->request->query()->get('edit') ?? 0);
        $pages     = $this->loadPages();
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        // Decorate each page row with editing context.
        // editing must be an ARRAY (not bool) so Mustache keeps the row's data as context.
        $pageList = [];
        foreach ($pages as $row) {
            if ($editId > 0 && (int) $row['id'] === $editId) {
                $row['editing'] = [$row]; // [$data] → Mustache iterates exactly once
            } else {
                $row['editing'] = false;
            }
            $pageList[] = $row;
        }

        $this->ctx->set('csrf_token', $csrfToken);
        $this->ctx->set('prg_id',     $prgId);
        $this->ctx->set('page_list',  $pageList);
        $this->ctx->set('base_url',   $selfUrl);
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

        $action = (string) ($posted['action'] ?? '');

        switch ($action) {
            case 'add':
                $this->addPage($posted);
                break;
            case 'update':
                $id = (int) ($posted['page_id'] ?? 0);
                if ($id > 0) {
                    $this->updatePage($id, $posted);
                }
                break;
            case 'delete':
                $id = (int) ($posted['page_id'] ?? 0);
                if ($id > 0) {
                    $this->deletePage($id);
                }
                break;
            case 'toggle_hidden':
                $this->toggleFlag((int) ($posted['page_id'] ?? 0), 'hidden');
                break;
            case 'toggle_comments':
                $this->toggleFlag((int) ($posted['page_id'] ?? 0), 'comments');
                break;
        }
    }

    // =========================================================================
    // DB operations
    // =========================================================================

    private function addPage(array $p): void
    {
        $urlId      = trim((string) ($p['url_id']    ?? ''));
        $fileName   = trim((string) ($p['file_name'] ?? ''));
        if ($urlId === '' || $fileName === '') {
            $this->flash->set('error', $this->t->t('admin.pages.url_file_required'));
            return;
        }
        $i18n       = !empty($p['i18n'])       ? 1 : 0;
        $template   = !empty($p['template'])   ? 1 : 0;
        $controller = !empty($p['controller']) ? 1 : 0;
        $hidden     = !empty($p['hidden'])     ? 1 : 0;
        $comments   = !empty($p['comments'])   ? 1 : 0;
        $title       = trim((string) ($p['title']       ?? ''));
        $description = trim((string) ($p['description'] ?? ''));
        $parentId    = (int) ($p['parent_id'] ?? 0);
        $indexFlag   = !empty($p['index_flag'])  ? 1 : 0;
        $followFlag  = !empty($p['follow_flag']) ? 1 : 0;

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'INSERT INTO page (url_id, i18n, file_name, template, controller, hidden, comments)
                 VALUES (:uid, :i18n, :fn, :tpl, :ctrl, :hidden, :comments)'
            )->execute([':uid' => $urlId, ':i18n' => $i18n, ':fn' => $fileName,
                        ':tpl' => $template, ':ctrl' => $controller,
                        ':hidden' => $hidden, ':comments' => $comments]);
            $newId = (int) $this->pdo->lastInsertId();

            // Closure: self-ref + inherit from parent
            $this->pdo->prepare(
                'INSERT INTO page_closure (ancestor, descendant)
                 SELECT ancestor, :new FROM page_closure WHERE descendant = :parent
                 UNION ALL SELECT :new2, :new3'
            )->execute([':new' => $newId, ':parent' => $parentId > 0 ? $parentId : $newId,
                        ':new2' => $newId, ':new3' => $newId]);

            // Meta
            $this->pdo->prepare(
                'INSERT INTO page_meta (page_id, title, description) VALUES (:id, :title, :desc)'
            )->execute([':id' => $newId, ':title' => $title, ':desc' => $description]);

            // Robots
            $this->pdo->prepare(
                'INSERT INTO page_robots (page_id, `index`, follow) VALUES (:id, :idx, :follow)'
            )->execute([':id' => $newId, ':idx' => $indexFlag, ':follow' => $followFlag]);

            $this->pdo->commit();
            $this->flash->set('success', $this->t->t('admin.pages.added'));
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            $this->emitDiag($e);
        }
    }

    private function updatePage(int $id, array $p): void
    {
        $urlId      = trim((string) ($p['url_id']    ?? ''));
        $fileName   = trim((string) ($p['file_name'] ?? ''));
        if ($urlId === '' || $fileName === '') {
            $this->flash->set('error', $this->t->t('admin.pages.url_file_required'));
            return;
        }
        $i18n       = !empty($p['i18n'])       ? 1 : 0;
        $template   = !empty($p['template'])   ? 1 : 0;
        $controller = !empty($p['controller']) ? 1 : 0;
        $hidden     = !empty($p['hidden'])     ? 1 : 0;
        $comments   = !empty($p['comments'])   ? 1 : 0;
        $title       = trim((string) ($p['title']       ?? ''));
        $description = trim((string) ($p['description'] ?? ''));
        $indexFlag   = !empty($p['index_flag'])  ? 1 : 0;
        $followFlag  = !empty($p['follow_flag']) ? 1 : 0;

        try {
            $this->pdo->prepare(
                'UPDATE page SET url_id=:uid, i18n=:i18n, file_name=:fn, template=:tpl,
                                 controller=:ctrl, hidden=:hidden, comments=:comments
                  WHERE id = :id'
            )->execute([':uid' => $urlId, ':i18n' => $i18n, ':fn' => $fileName,
                        ':tpl' => $template, ':ctrl' => $controller,
                        ':hidden' => $hidden, ':comments' => $comments, ':id' => $id]);

            $this->pdo->prepare(
                'INSERT INTO page_meta (page_id, title, description) VALUES (:id, :t, :d)
                 ON DUPLICATE KEY UPDATE title = :t2, description = :d2'
            )->execute([':id' => $id, ':t' => $title, ':d' => $description,
                        ':t2' => $title, ':d2' => $description]);

            $this->pdo->prepare(
                'INSERT INTO page_robots (page_id, `index`, follow) VALUES (:id, :idx, :follow)
                 ON DUPLICATE KEY UPDATE `index` = :idx2, follow = :follow2'
            )->execute([':id' => $id, ':idx' => $indexFlag, ':follow' => $followFlag,
                        ':idx2' => $indexFlag, ':follow2' => $followFlag]);

            $this->flash->set('success', $this->t->t('admin.pages.updated'));
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function deletePage(int $id): void
    {
        try {
            // CASCADE handles page_closure, page_meta, page_robots
            $this->pdo->prepare('DELETE FROM page WHERE id = :id')
                ->execute([':id' => $id]);
            $this->flash->set('success', $this->t->t('admin.pages.deleted'));
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    private function toggleFlag(int $id, string $column): void
    {
        if (!in_array($column, ['hidden', 'comments'], true)) {
            return;
        }
        try {
            $this->pdo->prepare("UPDATE page SET {$column} = 1 - {$column} WHERE id = :id")
                ->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    private function loadPage(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.id, p.url_id, p.file_name, p.i18n, p.template, p.controller,
                        p.hidden, p.comments, pm.title, pm.description,
                        pr.`index` AS index_flag, pr.follow AS follow_flag
                   FROM page p
                   LEFT JOIN page_meta   pm ON pm.page_id = p.id
                   LEFT JOIN page_robots pr ON pr.page_id = p.id
                  WHERE p.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\PDOException) {
            return null;
        }
    }

    private function loadPages(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT p.id, p.url_id, p.file_name, p.i18n, p.template, p.controller,
                        p.hidden, p.comments, pm.title, pm.description,
                        pr.`index` AS index_flag, pr.follow AS follow_flag
                   FROM page p
                   LEFT JOIN page_meta   pm ON pm.page_id = p.id
                   LEFT JOIN page_robots pr ON pr.page_id = p.id
                   ORDER BY p.id'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    /** Pages available as parent (excludes the page being edited) */
    private function loadAllPagesSimple(int $excludeId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.id, p.url_id, pm.title FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                  WHERE p.id != :ex ORDER BY p.id'
            );
            $stmt->execute([':ex' => $excludeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_pages_heading', $this->t->t('admin.nav.pages'));
        $this->ctx->set('label_id',          $this->t->t('admin.field.id'));
        $this->ctx->set('label_url_id',      $this->t->t('admin.pages.url_id'));
        $this->ctx->set('label_file_name',   $this->t->t('admin.pages.file_name'));
        $this->ctx->set('label_title',       $this->t->t('admin.field.title'));
        $this->ctx->set('label_description', $this->t->t('admin.pages.description'));
        $this->ctx->set('label_i18n',        $this->t->t('admin.pages.i18n'));
        $this->ctx->set('label_template',    $this->t->t('admin.pages.template'));
        $this->ctx->set('label_controller',  $this->t->t('admin.pages.controller'));
        $this->ctx->set('label_hidden',      $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_comments',    $this->t->t('admin.pages.comments'));
        $this->ctx->set('label_index',       $this->t->t('admin.pages.index'));
        $this->ctx->set('label_follow',      $this->t->t('admin.pages.follow'));
        $this->ctx->set('label_parent',      $this->t->t('admin.pages.parent'));
        $this->ctx->set('label_actions',     $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_edit',          $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_add',           $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_update',        $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',        $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_cancel',        $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_toggle',        $this->t->t('admin.btn.toggle'));
        $this->ctx->set('pages_routing_warning', $this->t->t('admin.pages.routing_warning'));
    }

    private function emitDiag(\PDOException $e): void
    {
        $this->emit(new AdminDbDiagnostic(
                        AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                    ));
    }
}