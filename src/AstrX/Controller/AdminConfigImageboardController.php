<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BoardRepository;
use AstrX\Imageboard\ImageboardConfig;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — Imageboard global configuration editor.
 *
 * Edits the single 'ImageboardConfig' section of Imageboard.config.php through
 * ConfigWriter, mirroring the other admin config editors (chat, captcha, mail):
 * gate on the page permission, PRG+CSRF on submit, then a self-redirect; on GET
 * the form is populated from the current configuration.
 *
 * These are the MODULE-WIDE defaults only — per-board settings (slug, title,
 * cooldown, limits) live on the `board` DB row. The two most security-relevant
 * knobs here are the pre-decode pixel budget (decompression-bomb guard) and the
 * anonymous-post captcha; the thread-size default bounds thread-view cost.
 *
 * The former standalone "Boards" admin page (a read-only board overview) has
 * been folded into this one, so the module has a single admin surface: global
 * settings on top, the per-board overview table below.
 */
final class AdminConfigImageboardController extends AbstractController
{
    private const FORM = 'admin_config_imageboard';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ImageboardConfig       $config,
        private readonly BoardRepository        $boards,
        private readonly ConfigWriter           $writer,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_IMAGEBOARD)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $result = $this->writer->write('Imageboard', ['ImageboardConfig' => $this->sectionFrom($posted)]);
        $result->drainTo($this->collector);
        if ($result->isOk()) {
            $this->flash->set('success', $this->t->t('admin.config.saved'));
            $this->audit->log('config.save', 'Imageboard.config.php')->drainTo($this->collector);
        }
    }

    /**
     * Build the full 'ImageboardConfig' section from the posted form. Every key
     * of the section is read here so the write replaces the file completely.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function sectionFrom(array $p): array
    {
        // Numeric values are clamped on the WRITE path (not only in the load
        // setters) so the persisted config file can never hold an out-of-range
        // value — e.g. upload_max_pixels can't be driven to 0 to disable the
        // decompression-bomb guard.
        return [
            'enabled'             => self::mBool($p, 'enabled'),
            'upload_dir'          => self::mStr($p, 'upload_dir', ''),
            'upload_max_kb'       => max(1,       self::mInt($p, 'upload_max_kb', 4096)),
            'upload_max_pixels'   => max(1000000, self::mInt($p, 'upload_max_pixels', 16000000)),
            'full_max_dimension'  => max(64,      self::mInt($p, 'full_max_dimension', 1600)),
            'thumb_max_dimension' => max(32,      self::mInt($p, 'thumb_max_dimension', 250)),
            'upload_types'        => self::mStr($p, 'upload_types', 'jpg,jpeg,png,gif,webp'),
            'anon_name'           => self::mStr($p, 'anon_name', 'Anonymous'),
            'guest_captcha'       => self::mBool($p, 'guest_captcha'),
            'allow_authenticated_posts' => self::mBool($p, 'allow_authenticated_posts'),
            'store_poster_ip'     => self::mBool($p, 'store_poster_ip'),
            'default_max_replies' => max(0,       self::mInt($p, 'default_max_replies', 500)),
            'flag_base_path'      => self::mStr($p, 'flag_base_path', '/flags'),
            'threads_per_page'    => max(1,       self::mInt($p, 'threads_per_page', 10)),
            'preview_replies'     => max(0,       self::mInt($p, 'preview_replies', 5)),
        ];
    }

    // ── Context builder ─────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $c = $this->config;

        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));

        $this->ctx->set('cfg_enabled',             $c->enabled());
        $this->ctx->set('cfg_upload_dir',          $c->uploadDir());
        $this->ctx->set('cfg_upload_max_kb',       $c->uploadMaxKb());
        $this->ctx->set('cfg_upload_max_pixels',   $c->uploadMaxPixels());
        $this->ctx->set('cfg_full_max_dimension',  $c->fullMaxDimension());
        $this->ctx->set('cfg_thumb_max_dimension', $c->thumbMaxDimension());
        $this->ctx->set('cfg_upload_types',        implode(',', $c->uploadTypes()));
        $this->ctx->set('cfg_anon_name',           $c->anonName());
        $this->ctx->set('cfg_guest_captcha',       $c->guestCaptcha());
        $this->ctx->set('cfg_allow_auth_posts',    $c->allowAuthenticatedPosts());
        $this->ctx->set('cfg_store_poster_ip',     $c->storePosterIp());
        $this->ctx->set('cfg_default_max_replies', $c->defaultMaxReplies());
        $this->ctx->set('cfg_flag_base_path',      $c->flagBasePath());
        $this->ctx->set('cfg_threads_per_page',    $c->threadsPerPage());
        $this->ctx->set('cfg_preview_replies',     $c->previewReplies());

        $this->setBoardList();
        $this->setI18n();
    }

    /**
     * Read-only per-board overview, folded in from the former standalone Boards
     * admin page. Shows each board's effective flood/size limits at a glance.
     */
    private function setBoardList(): void
    {
        $listR   = $this->boards->listActive();
        $rows    = $listR->isOk() ? $listR->unwrap() : [];
        $default = $this->t->t('admin.boards.default');

        $boards = [];
        foreach ($rows as $b) {
            $maxReplies = self::mInt($b, 'max_replies');
            $boards[]   = [
                'slug'         => self::mStr($b, 'slug'),
                'title'        => self::mStr($b, 'title'),
                'active'       => self::mBool($b, 'active') ? '✓' : '—',
                'cooldown'     => self::mInt($b, 'cooldown_secs'),
                'max_replies'  => $maxReplies > 0 ? (string) $maxReplies : $default,
                'thread_limit' => self::mInt($b, 'thread_limit'),
            ];
        }
        $this->ctx->set('boards',     $boards);
        $this->ctx->set('has_boards', $boards !== []);
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading', $this->t->t('admin.config.imageboard.heading'));

        $this->ctx->set('section_general', $this->t->t('admin.config.imageboard.section_general'));
        $this->ctx->set('section_uploads', $this->t->t('admin.config.imageboard.section_uploads'));
        $this->ctx->set('section_posting', $this->t->t('admin.config.imageboard.section_posting'));
        $this->ctx->set('section_display', $this->t->t('admin.config.imageboard.section_display'));

        $this->ctx->set('label_enabled',             $this->t->t('admin.config.imageboard.field.enabled'));
        $this->ctx->set('label_upload_dir',          $this->t->t('admin.config.imageboard.field.upload_dir'));
        $this->ctx->set('label_upload_max_kb',       $this->t->t('admin.config.imageboard.field.upload_max_kb'));
        $this->ctx->set('label_upload_max_pixels',   $this->t->t('admin.config.imageboard.field.upload_max_pixels'));
        $this->ctx->set('label_full_max_dimension',  $this->t->t('admin.config.imageboard.field.full_max_dimension'));
        $this->ctx->set('label_thumb_max_dimension', $this->t->t('admin.config.imageboard.field.thumb_max_dimension'));
        $this->ctx->set('label_upload_types',        $this->t->t('admin.config.imageboard.field.upload_types'));
        $this->ctx->set('label_anon_name',           $this->t->t('admin.config.imageboard.field.anon_name'));
        $this->ctx->set('label_guest_captcha',       $this->t->t('admin.config.imageboard.field.guest_captcha'));
        $this->ctx->set('label_allow_auth_posts',    $this->t->t('admin.config.imageboard.field.allow_authenticated_posts'));
        $this->ctx->set('label_store_poster_ip',     $this->t->t('admin.config.imageboard.field.store_poster_ip'));
        $this->ctx->set('label_default_max_replies', $this->t->t('admin.config.imageboard.field.default_max_replies'));
        $this->ctx->set('label_flag_base_path',      $this->t->t('admin.config.imageboard.field.flag_base_path'));
        $this->ctx->set('label_threads_per_page',    $this->t->t('admin.config.imageboard.field.threads_per_page'));
        $this->ctx->set('label_preview_replies',     $this->t->t('admin.config.imageboard.field.preview_replies'));

        $this->ctx->set('hint_upload_max_pixels',   $this->t->t('admin.config.imageboard.hint.upload_max_pixels'));
        $this->ctx->set('hint_allow_auth_posts',    $this->t->t('admin.config.imageboard.hint.allow_authenticated_posts'));
        $this->ctx->set('hint_store_poster_ip',     $this->t->t('admin.config.imageboard.hint.store_poster_ip'));
        $this->ctx->set('hint_default_max_replies', $this->t->t('admin.config.imageboard.hint.default_max_replies'));

        $this->ctx->set('btn_save', $this->t->t('admin.btn.save'));

        // Board overview table (merged from the former standalone Boards page).
        $this->ctx->set('boards_heading',  $this->t->t('admin.boards.heading'));
        $this->ctx->set('boards_intro',    $this->t->t('admin.boards.intro'));
        $this->ctx->set('col_slug',        $this->t->t('admin.boards.col_slug'));
        $this->ctx->set('col_title',       $this->t->t('admin.boards.col_title'));
        $this->ctx->set('col_active',      $this->t->t('admin.boards.col_active'));
        $this->ctx->set('col_cooldown',    $this->t->t('admin.boards.col_cooldown'));
        $this->ctx->set('col_max_replies', $this->t->t('admin.boards.col_max_replies'));
        $this->ctx->set('col_threads',     $this->t->t('admin.boards.col_threads'));
        $this->ctx->set('boards_none',     $this->t->t('admin.boards.none'));
    }
}
