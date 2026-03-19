<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Comment\CommentRepository;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
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
            // Preserve filters on redirect
            $qs = $this->buildFilterQs();
            Response::redirect($this->request->uri()->path() . $qs)
                ->send()->drainTo($this->collector);
            exit;
        }

        // Filters
        $qPageId  = $this->request->query()->get('page_id');
        $qFlagged = $this->request->query()->get('flagged');
        $qHidden  = $this->request->query()->get('show_hidden');
        $editId   = (int) ($this->request->query()->get('edit') ?? 0);

        $filters = [];
        if ($qPageId !== null) { $filters['page_id'] = (int) $qPageId; }
        if ($qFlagged === '1') { $filters['flagged'] = 1; }
        if ($qHidden  !== '1') { $filters['hidden']  = 0; }

        $listResult = $this->comments->fetchAll($filters);
        $listResult->drainTo($this->collector);

        $rawList     = $listResult->isOk() ? $listResult->unwrap() : [];
        $commentList = [];
        foreach ($rawList as $row) {
            if ($editId > 0 && (int) $row['id'] === $editId) {
                $row['editing'] = [$row]; // single-element list → count=1, context=row
            } else {
                $row['editing'] = false;
            }
            $commentList[] = $row;
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $muteResult = $this->comments->listMutes();
        $muteResult->drainTo($this->collector);
        $this->ctx->set('comment_list',   $commentList);
        $this->ctx->set('mute_list',      $muteResult->isOk() ? $muteResult->unwrap() : []);
        $this->ctx->set('filter_page_id', $qPageId ?? '');
        $this->ctx->set('filter_flagged', $qFlagged === '1');
        $this->ctx->set('base_url',       $this->request->uri()->path());
        $this->setI18n();
        return $this->ok();
    }

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
        $id     = (int)    ($posted['id']     ?? 0);

        switch ($action) {
            case 'update':
                $content = trim((string) ($posted['content'] ?? ''));
                $name    = trim((string) ($posted['name']    ?? ''));
                $email   = ($posted['email'] ?? '') !== '' ? trim((string) $posted['email']) : null;
                $hidden  = !empty($posted['hidden']);
                $flagged = !empty($posted['flagged']);
                if ($content !== '') {
                    $r = $this->comments->update($id, $content, $name, $email, $hidden, $flagged);
                    $r->drainTo($this->collector);
                    if ($r->isOk()) {
                        $this->flash->set('success', $this->t->t('admin.comments.updated'));
                    }
                }
                break;
            case 'hide':
                $this->comments->setHidden($id, true)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.hidden'));
                break;
            case 'unhide':
                $this->comments->setHidden($id, false)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.unhidden'));
                break;
            case 'flag':
                $this->comments->setFlagged($id, true)->drainTo($this->collector);
                break;
            case 'unflag':
                $this->comments->setFlagged($id, false)->drainTo($this->collector);
                break;
            case 'delete':
                $this->comments->delete($id)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.deleted'));
                break;
            case 'lift_mute':
                $muteId = (int) ($posted['mute_id'] ?? 0);
                if ($muteId > 0) {
                    $this->comments->deleteMute($muteId)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('admin.comments.mute_lifted'));
                }
                break;
        }
    }

    private function buildFilterQs(): string
    {
        $parts = [];
        $pageId  = $this->request->query()->get('page_id');
        $flagged = $this->request->query()->get('flagged');
        $hidden  = $this->request->query()->get('show_hidden');
        if ($pageId  !== null) { $parts[] = 'page_id='    . rawurlencode($pageId); }
        if ($flagged !== null) { $parts[] = 'flagged='    . rawurlencode($flagged); }
        if ($hidden  !== null) { $parts[] = 'show_hidden=' . rawurlencode($hidden); }
        return $parts !== [] ? '?' . implode('&', $parts) : '';
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_comments_heading', $this->t->t('admin.nav.comments'));
        $this->ctx->set('label_id',       $this->t->t('admin.field.id'));
        $this->ctx->set('label_page',     $this->t->t('admin.field.page'));
        $this->ctx->set('label_user',     $this->t->t('admin.field.user'));
        $this->ctx->set('label_name',     $this->t->t('admin.field.name'));
        $this->ctx->set('label_email',    $this->t->t('admin.comments.email'));
        $this->ctx->set('label_content',  $this->t->t('admin.field.content'));
        $this->ctx->set('label_date',     $this->t->t('admin.field.date'));
        $this->ctx->set('label_hidden',   $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_flagged',  $this->t->t('admin.field.flagged'));
        $this->ctx->set('label_reply_to', $this->t->t('admin.comments.reply_to'));
        $this->ctx->set('label_actions',  $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_edit',       $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_update',     $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_cancel',     $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_hide',       $this->t->t('admin.btn.hide'));
        $this->ctx->set('btn_unhide',     $this->t->t('admin.btn.unhide'));
        $this->ctx->set('btn_delete',     $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_flag',       $this->t->t('admin.btn.flag'));
        $this->ctx->set('btn_unflag',     $this->t->t('admin.btn.unflag'));
        $this->ctx->set('label_filter',   $this->t->t('admin.comments.filter'));
        $this->ctx->set('btn_filter',     $this->t->t('admin.btn.filter'));
        $this->ctx->set('label_show_hidden', $this->t->t('admin.comments.show_hidden'));
        $this->ctx->set('label_mutes',       $this->t->t('admin.comments.mutes'));
        $this->ctx->set('label_expires',     $this->t->t('admin.comments.mute_expires'));
        $this->ctx->set('btn_lift_mute',     $this->t->t('admin.comments.lift_mute'));
    }
}