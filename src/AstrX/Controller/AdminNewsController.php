<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\News\NewsRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

final class AdminNewsController extends AbstractController
{
    private const FORM = 'admin_news';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly NewsRepository        $news,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_NEWS)) {
            http_response_code(403);
            return $this->ok();
        }

        // PRG: process submitted form
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($this->request->uri()->path())
                ->send()->drainTo($this->collector);
            exit;
        }

        // Render listing + edit form
        $listResult = $this->news->fetchAllAdmin();
        $listResult->drainTo($this->collector);

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $editId = (int) ($this->request->query()->get('edit') ?? 0);
        $rawList = $listResult->isOk() ? $listResult->unwrap() : [];
        $newsList = [];
        foreach ($rawList as $item) {
            $item['editing'] = ($editId > 0 && (int) $item['id'] === $editId);
            $newsList[] = $item;
        }
        $this->ctx->set('news_list', $newsList);
        $this->ctx->set('base_url',  $this->request->uri()->path());
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

        $action  = (string) ($posted['action']  ?? '');
        $id      = (int)    ($posted['id']      ?? 0);
        $title   = trim((string) ($posted['title']   ?? ''));
        $content = trim((string) ($posted['content'] ?? ''));
        $hidden  = !empty($posted['hidden']);

        switch ($action) {
            case 'create':
                if ($title === '' || $content === '') {
                    return;
                }
                $r = $this->news->create($title, $content, $hidden);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.news.created'));
                }
                break;
            case 'update':
                $r = $this->news->update($id, $title, $content, $hidden);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.news.updated'));
                }
                break;
            case 'delete':
                $r = $this->news->delete($id);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('admin.news.deleted'));
                }
                break;
        }
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_news_heading', $this->t->t('admin.nav.news'));
        $this->ctx->set('label_title',    $this->t->t('admin.field.title'));
        $this->ctx->set('label_content',  $this->t->t('admin.field.content'));
        $this->ctx->set('label_hidden',   $this->t->t('admin.field.hidden'));
        $this->ctx->set('label_id',       $this->t->t('admin.field.id'));
        $this->ctx->set('label_date',     $this->t->t('admin.field.date'));
        $this->ctx->set('label_actions',  $this->t->t('admin.field.actions'));
        $this->ctx->set('btn_create',     $this->t->t('admin.btn.create'));
        $this->ctx->set('btn_update',     $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_delete',     $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',       $this->t->t('admin.btn.edit'));
    }
}