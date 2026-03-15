<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Read-only page listing for admins.
 * Write operations (add/edit pages) require direct DB access intentionally —
 * misconfigured pages can break routing.
 */
final class AdminPagesController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly PDO                   $pdo,
        private readonly Gate                  $gate,
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

        try {
            $stmt = $this->pdo->query(
                'SELECT p.id, p.url_id, p.file_name, p.i18n, p.template,
                        p.controller, p.hidden, p.comments,
                        pm.title, pm.description
                   FROM page p
                   LEFT JOIN page_meta pm ON pm.page_id = p.id
                   ORDER BY p.id'
            );
            $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            $pages = [];
        }

        $this->ctx->set('page_list', $pages);
        $this->ctx->set('admin_pages_heading', $this->t->t('admin.nav.pages'));
        $this->ctx->set('label_id',          $this->t->t('admin.field.id'));
        $this->ctx->set('label_url_id',      $this->t->t('admin.pages.url_id'));
        $this->ctx->set('label_file_name',   $this->t->t('admin.pages.file_name'));
        $this->ctx->set('label_title',       $this->t->t('admin.field.title'));
        $this->ctx->set('label_i18n',        $this->t->t('admin.pages.i18n'));
        $this->ctx->set('label_hidden',      $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_comments',    $this->t->t('admin.pages.comments'));
        $this->ctx->set('pages_note',        $this->t->t('admin.pages.note'));
        return $this->ok();
    }
}
