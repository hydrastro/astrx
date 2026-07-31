<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Content\ContentPageRepository;
use AstrX\Content\ContentService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Admin — content pages: a no-JavaScript editor (create / edit / delete Markdown
 * pages) plus the broken-link checker. PRG + CSRF like the other admin editors.
 * Gated on ADMIN_ACCESS.
 */
final class AdminContentController extends AbstractController
{
    private const string FORM = 'admin_content';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly ContentPageRepository  $repo,
        private readonly ContentService         $service,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Content');

        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $this->handlePrgPost($this->request, $this->prg, $selfUrl, fn(string $tok): string => $this->processForm($tok));
        $this->buildContext($selfUrl);
        return $this->ok();
    }

    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        return match (self::mStr($posted, 'action', '')) {
            'save'   => $this->save($posted),
            'delete' => $this->delete($posted),
            default  => '',
        };
    }

    /** @param array<string,mixed> $posted */
    private function save(array $posted): string
    {
        // Content pages are public site pages: entry is ADMIN_ACCESS (a MOD may
        // view), but mutating them requires ADMIN_PAGES. Re-gate here so a MOD
        // cannot deface or replace live content — mirrors board CRUD's
        // BOARD_ADMIN re-check under a weaker entry gate.
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $id      = self::mInt($posted, 'id', 0);
        $slug    = $this->slugify(self::mStr($posted, 'slug', ''));
        $title   = trim(self::mStr($posted, 'title', ''));
        $body    = self::mStr($posted, 'body', '');
        $visible = self::mBool($posted, 'visible');

        if ($slug === '') {
            $this->flash->set('error', $this->t->t('content.admin.slug_required'));
            return $id > 0 ? '?edit=' . $id : '';
        }

        $r = $this->repo->save($id, $slug, $title, $body, $visible)->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('content.admin.save_failed'));
            return $id > 0 ? '?edit=' . $id : '';
        }
        $this->flash->set('success', $this->t->t('content.admin.saved'));
        return '';
    }

    /** @param array<string,mixed> $posted */
    private function delete(array $posted): string
    {
        // Deleting a public content page is a page mutation: re-gate on
        // ADMIN_PAGES so a view-only MOD cannot remove live pages.
        if ($this->gate->cannot(Permission::ADMIN_PAGES)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $id = self::mInt($posted, 'id', 0);
        if ($id > 0) {
            $this->repo->delete($id)->drainTo($this->collector);
            $this->flash->set('success', $this->t->t('content.admin.deleted'));
        }
        return '';
    }

    private function buildContext(string $selfUrl): void
    {
        // Page list.
        $r    = $this->repo->all(visibleOnly: false)->drainTo($this->collector);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'         => $row['id'],
                'slug'       => $row['slug'],
                'title'      => $row['title'] !== '' ? $row['title'] : $row['slug'],
                'visible'    => $row['visible'],
                'view_url'   => $this->service->pageUrl($row['slug']),
                'edit_url'   => $selfUrl . '?edit=' . $row['id'],
                'updated_at' => $row['updated_at'],
            ];
        }
        $this->ctx->set('pages',     $list);
        $this->ctx->set('has_pages', $list !== []);

        // Broken-link report.
        $broken = $this->service->brokenLinks();
        $this->ctx->set('broken',     $broken);
        $this->ctx->set('has_broken', $broken !== []);

        // Editor: edit an existing page or a blank new one.
        $editId = self::queryInt($this->request, 'edit', 0);
        $edit   = null;
        if ($editId > 0) {
            $er   = $this->repo->byId($editId)->drainTo($this->collector);
            $edit = $er->isOk() ? $er->unwrap() : null;
        }
        $this->ctx->set('is_edit',   $edit !== null);
        $this->ctx->set('f_id',      $edit['id']      ?? 0);
        $this->ctx->set('f_slug',    $edit['slug']    ?? '');
        $this->ctx->set('f_title',   $edit['title']   ?? '');
        $this->ctx->set('f_body',    $edit['body']    ?? '');
        $this->ctx->set('f_visible', $edit === null ? true : $edit['visible']);
        $this->ctx->set('f_view_url', $edit !== null ? $this->service->pageUrl($edit['slug']) : '');

        $this->ctx->set('form_action', $selfUrl);
        $this->ctx->set('new_url',     $selfUrl);
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));

        $this->setLabels();
    }

    /** Normalise a title/slug into a URL-safe slug. */
    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'      => 'content.admin.heading',
            'lbl_intro'        => 'content.admin.intro',
            'lbl_new'          => 'content.admin.new',
            'lbl_edit'         => 'content.admin.edit',
            'lbl_slug'         => 'content.admin.slug',
            'lbl_slug_hint'    => 'content.admin.slug_hint',
            'lbl_title'        => 'content.admin.title',
            'lbl_body'         => 'content.admin.body',
            'lbl_body_hint'    => 'content.admin.body_hint',
            'lbl_visible'      => 'content.admin.visible',
            'lbl_save'         => 'content.admin.save',
            'lbl_delete'       => 'content.admin.delete',
            'lbl_view'         => 'content.admin.view',
            'lbl_pages'        => 'content.admin.pages',
            'lbl_none'         => 'content.admin.none',
            'lbl_unlisted'     => 'content.admin.unlisted',
            'lbl_broken'       => 'content.admin.broken',
            'lbl_broken_none'  => 'content.admin.broken_none',
            'lbl_broken_links_to' => 'content.admin.broken_links_to',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
