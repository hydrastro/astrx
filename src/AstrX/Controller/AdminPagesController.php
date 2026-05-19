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
use AstrX\Result\DiagnosticLevel;

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

    // Fix 5.2: file_name maps to controller class names and template files.
    // Restrict to safe identifiers only.
    private const FILE_NAME_REGEX = '/\\A[a-z][a-z0-9_]*\\z/';
    private const URL_ID_REGEX    = '/\\A[A-Z][A-Z0-9_]*\\z|\\A[a-z][a-z0-9_-]*\\z/';

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

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
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

        $editId    = self::queryInt($this->request, 'edit', 0);
        $pages     = $this->loadPages();
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        // Decorate each page row with editing context.
        // editing must be an ARRAY (not bool) so Mustache keeps the row's data as context.
        $pageList = [];
        foreach ($pages as $row) {
            if ($editId > 0 && (is_int($row['id']) ? $row['id'] : 0) === $editId) {
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
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = self::mStr($posted, 'action', '');

        switch ($action) {
            case 'add':
                $this->addPage($posted);
                break;
            case 'update':
                $id = self::mInt($posted, 'page_id', 0);
                if ($id > 0) {
                    $this->updatePage($id, $posted);
                }
                break;
            case 'delete':
                $id = self::mInt($posted, 'page_id', 0);
                if ($id > 0) {
                    $this->deletePage($id);
                }
                break;
            case 'toggle_hidden':
                $this->toggleFlag(self::mInt($posted, 'page_id', 0), 'hidden');
                break;
            case 'toggle_comments':
                $this->toggleFlag(self::mInt($posted, 'page_id', 0), 'comments');
                break;
            case 'toggle_api_enabled':
                $this->toggleFlag(self::mInt($posted, 'page_id', 0), 'api_enabled');
                break;
        }
    }

    // =========================================================================
    // DB operations
    // =========================================================================

    /** @param array<string,mixed> $p */
    private function addPage(array $p): void
    {
        $urlId      = trim(self::mStr($p, 'url_id', ''));
        $fileName   = trim(self::mStr($p, 'file_name', ''));
        if ($urlId === '' || $fileName === '') {
            $this->flash->set('error', $this->t->t('admin.pages.url_file_required'));
            return;
        }
        // Fix 5.2: file_name maps to PHP class and template filename — restrict.
        if (!preg_match(self::FILE_NAME_REGEX, $fileName)) {
            $this->flash->set('error',
                $this->t->t('admin.pages.invalid_file_name'));
            return;
        }
        if (!preg_match(self::URL_ID_REGEX, $urlId)) {
            $this->flash->set('error',
                $this->t->t('admin.pages.invalid_url_id'));
            return;
        }
        $i18n       = self::mBool($p, 'i18n')       ? 1 : 0;
        $template   = self::mBool($p, 'template')   ? 1 : 0;
        $controller = self::mBool($p, 'controller') ? 1 : 0;
        $hidden     = self::mBool($p, 'hidden')     ? 1 : 0;
        $comments   = self::mBool($p, 'comments')   ? 1 : 0;
        $apiEnabled = self::mBool($p, 'api_enabled') ? 1 : 0;
        $title       = trim(self::mStr($p, 'title', ''));
        $description = trim(self::mStr($p, 'description', ''));
        $parentId    = self::mInt($p, 'parent_id', 0);
        $indexFlag   = self::mBool($p, 'index_flag')  ? 1 : 0;
        $followFlag  = self::mBool($p, 'follow_flag') ? 1 : 0;

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'INSERT INTO page (url_id, i18n, file_name, template, controller, hidden, comments, api_enabled)
                 VALUES (:uid, :i18n, :fn, :tpl, :ctrl, :hidden, :comments, :apienabled)'
            )->execute([':uid' => $urlId, ':i18n' => $i18n, ':fn' => $fileName,
                        ':tpl' => $template, ':ctrl' => $controller,
                        ':hidden' => $hidden, ':comments' => $comments,
                        ':apienabled' => $apiEnabled]);
            $newId = (int) $this->pdo->lastInsertId();

            // Fix 5.4: explicit closure-table logic — easier to audit than the
            // previous self-referencing SELECT.  Two cases:
            //   1. parent > 0 → inherit all ancestors of parent + add self-ref
            //   2. parent = 0 → root page, only the self-ref is needed
            if ($parentId > 0) {
                $this->pdo->prepare(
                    'INSERT INTO page_closure (ancestor, descendant)
                     SELECT ancestor, :new FROM page_closure WHERE descendant = :parent'
                )->execute([':new' => $newId, ':parent' => $parentId]);
            }
            $this->pdo->prepare(
                'INSERT INTO page_closure (ancestor, descendant) VALUES (:id, :id2)'
            )->execute([':id' => $newId, ':id2' => $newId]);

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
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            // Fix 5.3: friendly message for duplicate-key violations.
            if ((string) $e->getCode() === '23000') {
                $this->flash->set('error',
                    $this->t->t('admin.pages.url_id_exists'));
            } else {
                $this->emitDiag($e);
            }
        }
    }

    /** @param array<string,mixed> $p */
    private function updatePage(int $id, array $p): void
    {
        $urlId      = trim(self::mStr($p, 'url_id', ''));
        $fileName   = trim(self::mStr($p, 'file_name', ''));
        if ($urlId === '' || $fileName === '') {
            $this->flash->set('error', $this->t->t('admin.pages.url_file_required'));
            return;
        }
        if (!preg_match(self::FILE_NAME_REGEX, $fileName)) {
            $this->flash->set('error', $this->t->t('admin.pages.invalid_file_name'));
            return;
        }
        if (!preg_match(self::URL_ID_REGEX, $urlId)) {
            $this->flash->set('error', $this->t->t('admin.pages.invalid_url_id'));
            return;
        }
        $i18n       = self::mBool($p, 'i18n')       ? 1 : 0;
        $template   = self::mBool($p, 'template')   ? 1 : 0;
        $controller = self::mBool($p, 'controller') ? 1 : 0;
        $hidden     = self::mBool($p, 'hidden')     ? 1 : 0;
        $comments   = self::mBool($p, 'comments')   ? 1 : 0;
        $apiEnabled = self::mBool($p, 'api_enabled') ? 1 : 0;
        $title       = trim(self::mStr($p, 'title', ''));
        $description = trim(self::mStr($p, 'description', ''));
        $indexFlag   = self::mBool($p, 'index_flag')  ? 1 : 0;
        $followFlag  = self::mBool($p, 'follow_flag') ? 1 : 0;

        try {
            $this->pdo->prepare(
                'UPDATE page SET url_id=:uid, i18n=:i18n, file_name=:fn, template=:tpl,
                                 controller=:ctrl, hidden=:hidden, comments=:comments, api_enabled=:apienabled WHERE id = :id'
            )->execute([':uid' => $urlId, ':i18n' => $i18n, ':fn' => $fileName,
                        ':tpl' => $template, ':ctrl' => $controller,
                        ':hidden' => $hidden, ':comments' => $comments, ':apienabled' => $apiEnabled, ':id' => $id]);

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
            // Fix 5.3: friendly duplicate-key message on update too.
            if ((string) $e->getCode() === '23000') {
                $this->flash->set('error', $this->t->t('admin.pages.url_id_exists'));
            } else {
                $this->emitDiag($e);
            }
        }
    }

    private function deletePage(int $id): void
    {
        try {
            // Fix 5.1 (CRITICAL): refuse to delete a page with descendants.
            // The closure-table CASCADE would orphan the children — they'd
            // survive in `page` but lose all their closure rows, becoming
            // unreachable through nav, breadcrumbs, and the admin tree.
            // Reparenting would be too clever; explicit refusal is safest.
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM page_closure
                  WHERE ancestor = :id AND descendant != :id2'
            );
            $stmt->execute([':id' => $id, ':id2' => $id]);
            $childCount = (int) $stmt->fetchColumn();
            if ($childCount > 0) {
                $this->flash->set('error',
                    $this->t->t('admin.pages.has_children'));
                return;
            }

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
        // Fix 5.5: explicit match expression — no string interpolation.
        // Self-documenting and prevents future copy-paste mistakes that omit
        // the allowlist check.
        $sql = match ($column) {
            'hidden'      => 'UPDATE page SET hidden = 1 - hidden WHERE id = :id',
            'comments'    => 'UPDATE page SET comments = 1 - comments WHERE id = :id',
            'api_enabled' => 'UPDATE page SET api_enabled = 1 - api_enabled WHERE id = :id',
            default       => null,
        };
        if ($sql === null) { return; }
        try {
            $this->pdo->prepare($sql)->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->emitDiag($e);
        }
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    /** @return list<array<string,mixed>> */
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
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
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
                        'astrx.admin/db_error', DiagnosticLevel::ERROR, $e->getMessage()
                    ));
    }
}
