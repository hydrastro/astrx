<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\Template\TemplateEngine;
use AstrX\Theme\ThemeService;

/**
 * Admin — Theme manager.
 *
 * Lists every theme discovered in resources/template/themes/ and lets the
 * admin select which one is the global default.  Per-user theme overrides
 * are managed on the user settings page (UserSettingsController), not here.
 *
 * The active-theme setting is written to Theme.config.php. There is no
 * caching to invalidate — the next request reads the new value through the
 * standard config pipeline.
 */
final class AdminThemesController extends AbstractController
{
    private const FORM = 'admin_themes';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ConfigWriter           $writer,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly ThemeService           $themeService,
        private readonly TemplateEngine         $templates,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_SYSTEM)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        if ($this->handlePrgPost($this->request, $this->prg, $selfUrl,
                fn(string $token): string => $this->processForm($token))) {
            return $this->ok();
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        $section = self::mStr($posted, 'section', '');

        // Cache clear is a separate explicit action — useful when an admin
        // edits a template file on disk and wants the change to take effect
        // immediately. Normally the mtime check in TemplateEngine catches
        // changes automatically, but on some filesystems (Docker bind mounts
        // with strict caching) mtime detection can lag — this is the manual
        // override.
        if ($section === 'clear_cache') {
            $deleted = $this->templates->clearCache();
            $this->flash->set(
                'success',
                str_replace('{n}', (string) $deleted, $this->t->t('admin.themes.cache_cleared')),
            );
            return '';
        }

        if ($section === 'global') {
            $themeKey = self::mStr($posted, 'theme', '');
            if ($themeKey === '' || !$this->themeService->themeExists($themeKey)) {
                $this->flash->set('error', $this->t->t('admin.themes.invalid_theme'));
                return '';
            }
            $r = $this->writer->write('Theme', [
                // Section keyed by the consuming class short name so ThemeService's
                // #[InjectConfig] setters bind it on the next request (see
                // Theme.config.php). A 'Theme' key here would never take effect.
                'ThemeService' => [
                    'theme'               => $themeKey,
                    'allow_user_override' => self::mBool($posted, 'allow_user_override'),
                ],
            ]);
            $r->drainTo($this->collector);
            if ($r->isOk()) {
                $this->flash->set('success', $this->t->t('admin.themes.saved'));
            }
        }
        return '';
    }

    // -------------------------------------------------------------------------

    private function buildContext(string $selfUrl): void
    {
        $csrfToken    = $this->csrf->generate(self::FORM);
        $prgId        = $this->prg->createId($selfUrl);
        $globalTheme  = $this->themeService->globalTheme();
        $userOverride = $this->themeService->allowUserOverride();
        $themes       = $this->themeService->discoverThemes();

        // Mark the currently active one for the radio buttons.
        $decorated = [];
        foreach ($themes as $t) {
            $t['active']      = $t['key'] === $globalTheme;
            $decorated[]      = $t;
        }

        $this->ctx->set('csrf_token',          $csrfToken);
        $this->ctx->set('prg_id',              $prgId);
        $this->ctx->set('base_url',            $selfUrl);
        $this->ctx->set('themes',              $decorated);
        $this->ctx->set('has_themes',          $decorated !== []);
        $this->ctx->set('current_theme',       $globalTheme);
        $this->ctx->set('allow_user_override', $userOverride);

        $this->setI18n();
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_themes_heading',         $this->t->t('admin.nav.themes'));
        $this->ctx->set('admin_themes_intro',           $this->t->t('admin.themes.intro'));
        $this->ctx->set('label_theme',                  $this->t->t('admin.themes.label_theme'));
        $this->ctx->set('label_allow_user_override',    $this->t->t('admin.themes.allow_user_override'));
        $this->ctx->set('label_allow_user_override_hint', $this->t->t('admin.themes.allow_user_override_hint'));
        $this->ctx->set('label_author',                 $this->t->t('admin.themes.author'));
        $this->ctx->set('label_version',                $this->t->t('admin.themes.version'));
        $this->ctx->set('btn_save',                     $this->t->t('admin.btn.save'));
        $this->ctx->set('no_themes',                    $this->t->t('admin.themes.no_themes'));
        $this->ctx->set('cache_heading',                $this->t->t('admin.themes.cache_heading'));
        $this->ctx->set('cache_desc',                   $this->t->t('admin.themes.cache_desc'));
        $this->ctx->set('btn_clear_cache',              $this->t->t('admin.themes.btn_clear_cache'));
    }
}
