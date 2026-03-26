<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use PDO;

/**
 * Admin — Audit log viewer.
 *
 * Read-only display of admin_audit_log, newest first, with basic filtering.
 * Requires ADMIN_ACCESS permission.
 *
 * GET parameters:
 *   page=N        pagination
 *   user=<name>   filter by username
 *   action=<slug> filter by action prefix
 */
final class AdminAuditLogController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly Gate                  $gate,
        private readonly PDO                   $pdo,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            return $this->ok();
        }

        $page       = max(1, (int) ($this->request->query()->get('page')   ?? 1));
        $filterUser = trim((string) ($this->request->query()->get('user')   ?? ''));
        $filterAct  = trim((string) ($this->request->query()->get('action') ?? ''));

        [$rows, $total] = $this->fetchRows($page, $filterUser, $filterAct);

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($page, $pages);

        $selfUrl = $this->request->uri()->path();

        $this->ctx->set('audit_rows',    $rows);
        $this->ctx->set('audit_total',   $total);
        $this->ctx->set('audit_page',    $page);
        $this->ctx->set('audit_pages',   $pages);
        $this->ctx->set('audit_per_page',self::PER_PAGE);
        $this->ctx->set('filter_user',   $filterUser);
        $this->ctx->set('filter_action', $filterAct);
        $this->ctx->set('has_prev',      $page > 1);
        $this->ctx->set('has_next',      $page < $pages);
        $this->ctx->set('prev_url',      $selfUrl . '?' . http_build_query(array_filter(['page' => $page - 1, 'user' => $filterUser, 'action' => $filterAct])));
        $this->ctx->set('next_url',      $selfUrl . '?' . http_build_query(array_filter(['page' => $page + 1, 'user' => $filterUser, 'action' => $filterAct])));
        $this->ctx->set('filter_url',    $selfUrl);
        $this->setI18n();

        return $this->ok();
    }

    /** @return array{0: list<array<string,string>>, 1: int} */
    private function fetchRows(int $page, string $filterUser, string $filterAct): array
    {
        $where  = [];
        $params = [];

        if ($filterUser !== '') {
            $where[]          = '`username` = :username';
            $params[':username'] = $filterUser;
        }
        if ($filterAct !== '') {
            $where[]         = '`action` LIKE :action';
            $params[':action'] = $filterAct . '%';
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset      = ($page - 1) * self::PER_PAGE;

        try {
            $countStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM `admin_audit_log` {$whereClause}"
            );
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $rowStmt = $this->pdo->prepare(
                "SELECT `id`, LOWER(HEX(`user_id`)) AS `user_id`,
                        `username`, `action`, `resource`, `detail`, `ip`,
                        `created_at`
                 FROM `admin_audit_log` {$whereClause}
                 ORDER BY `id` DESC
                 LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
            );
            $rowStmt->execute($params);
            $rows = $rowStmt->fetchAll(\PDO::FETCH_ASSOC);
            return [$rows, $total];
        } catch (\Throwable) {
            return [[], 0];
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading',          $this->t->t('admin.audit.heading'));
        $this->ctx->set('label_user',       $this->t->t('admin.field.username'));
        $this->ctx->set('label_action',     $this->t->t('admin.audit.action'));
        $this->ctx->set('label_resource',   $this->t->t('admin.audit.resource'));
        $this->ctx->set('label_detail',     $this->t->t('admin.audit.detail'));
        $this->ctx->set('label_ip',         $this->t->t('admin.audit.ip'));
        $this->ctx->set('label_time',       $this->t->t('admin.audit.time'));
        $this->ctx->set('label_filter',     $this->t->t('admin.btn.filter'));
        $this->ctx->set('label_clear',      $this->t->t('admin.btn.cancel'));
        $this->ctx->set('label_total',      $this->t->t('admin.audit.total'));
        $this->ctx->set('label_prev',       $this->t->t('admin.btn.prev'));
        $this->ctx->set('label_next',       $this->t->t('admin.btn.next'));
        $this->ctx->set('label_no_entries', $this->t->t('admin.audit.no_entries'));
    }
}
