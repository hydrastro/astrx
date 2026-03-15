<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Comment\CommentRepository;
use AstrX\Comment\CommentService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

final class AdminCommentsController extends AbstractController
{
    private const FORM = 'admin_comments';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly CommentRepository     $comments,
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
        if ($this->gate->cannot(Permission::ADMIN_COMMENTS)) {
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

        // Filters from query string
        $filters = [];
        $qPageId  = $this->request->query()->get('page_id');
        $qFlagged = $this->request->query()->get('flagged');
        $qHidden  = $this->request->query()->get('show_hidden');
        if ($qPageId !== null)  { $filters['page_id'] = (int) $qPageId; }
        if ($qFlagged === '1')  { $filters['flagged'] = 1; }
        if ($qHidden !== '1')   { $filters['hidden']  = 0; }

        $listResult = $this->comments->fetchAll($filters);
        $listResult->drainTo($this->collector);

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',    $csrfToken);
        $this->ctx->set('prg_id',        $prgId);
        $this->ctx->set('comment_list',  $listResult->isOk() ? $listResult->unwrap() : []);
        $this->ctx->set('filter_page_id', $qPageId ?? '');
        $this->ctx->set('filter_flagged', $qFlagged === '1');
        $this->setI18n();
        return $this->ok();
    }

    private function processForm(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = (string) ($posted['action'] ?? '');
        $id     = (int)    ($posted['id']     ?? 0);

        switch ($action) {
            case 'hide':
                $this->comments->setHidden($id, true)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.hidden'));
                break;
            case 'unhide':
                $this->comments->setHidden($id, false)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.unhidden'));
                break;
            case 'delete':
                $this->comments->delete($id)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.deleted'));
                break;
            case 'flag':
                $this->comments->setFlagged($id, true)->drainTo($this->collector);
                break;
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_comments_heading', $this->t->t('admin.nav.comments'));
        $this->ctx->set('label_id',        $this->t->t('admin.field.id'));
        $this->ctx->set('label_page',      $this->t->t('admin.field.page'));
        $this->ctx->set('label_user',      $this->t->t('admin.field.user'));
        $this->ctx->set('label_name',      $this->t->t('admin.field.name'));
        $this->ctx->set('label_content',   $this->t->t('admin.field.content'));
        $this->ctx->set('label_date',      $this->t->t('admin.field.date'));
        $this->ctx->set('label_hidden',    $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_flagged',   $this->t->t('admin.field.flagged'));
        $this->ctx->set('label_actions',   $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_hide',        $this->t->t('admin.btn.hide'));
        $this->ctx->set('btn_unhide',      $this->t->t('admin.btn.unhide'));
        $this->ctx->set('btn_delete',      $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_flag',        $this->t->t('admin.btn.flag'));
        $this->ctx->set('label_filter',    $this->t->t('admin.comments.filter'));
        $this->ctx->set('btn_filter',      $this->t->t('admin.btn.filter'));
    }
}
