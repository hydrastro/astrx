<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\I18n\LangCatalog;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\I18n\Translator;

use function AstrX\Support\configDir;
use function AstrX\Support\langDir;

/**
 * Admin — Languages: a no-JavaScript translation console.
 *
 * Two navbars drive it, so you navigate rather than drill in and back out:
 *   • a persistent DOMAIN navbar (left sidebar) — a "Manage languages" link plus
 *     the translation domains grouped Module / Core;
 *   • a LANGUAGE navbar (tabs) shown above the editor for the current domain.
 *
 * The main pane is either the editor — the default language alone on its own
 * tab, or the default shown read-only beside the editable current language — or
 * the manage view (add / delete languages, installed list). The language you are
 * working in follows you as you switch domains.
 *
 * Adding a language clones an existing locale's whole catalog tree and registers
 * the code in available_languages; deleting removes the tree and unregisters it
 * (never the primary or the configured default). PRG + CSRF, ADMIN_ACCESS.
 */
final class AdminLanguageController extends AbstractController
{
    private const string FORM = 'admin_language';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly LangCatalog            $catalog,
        private readonly ConfigWriter           $writer,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Admin');

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
            'save'            => $this->save($posted),
            'add_language'    => $this->addLanguage($posted),
            'delete_language' => $this->deleteLanguage($posted),
            default           => '',
        };
    }

    /** @param array<string,mixed> $posted */
    private function save(array $posted): string
    {
        $domain = self::mStr($posted, 'domain', '');
        $lang   = self::mStr($posted, 'lang', '');
        if ($domain === '' || $lang === '' || !$this->catalog->localeExists($lang)) {
            return '';
        }

        // v[<lang>][<key>] — only the one edited locale is posted; the others
        // fall back to their on-disk values inside save().
        $raw     = self::mArray($posted, 'v');
        $langRaw = $raw[$lang] ?? null;
        $pairs   = [];
        if (is_array($langRaw)) {
            foreach ($langRaw as $key => $value) {
                if (is_string($key)) {
                    $pairs[$key] = self::str($value);
                }
            }
        }

        $r = $this->catalog->save($domain, [$lang => $pairs])->drainTo($this->collector);
        if ($r->isOk()) {
            $this->flash->set('success', $this->t->t('admin.lang.saved'));
            $this->audit->log('lang.save', $domain . '/' . $lang)->drainTo($this->collector);
        } else {
            $this->flash->set('error', $this->t->t('admin.lang.save_failed'));
        }
        return '?domain=' . rawurlencode($domain) . '&lang=' . rawurlencode($lang);
    }

    /** @param array<string,mixed> $posted */
    private function addLanguage(array $posted): string
    {
        $code   = strtolower(trim(self::mStr($posted, 'new_locale', '')));
        $source = strtolower(trim(self::mStr($posted, 'source_locale', $this->catalog->primary())));

        $r = $this->catalog->addLanguage($code, $source)->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('admin.lang.add_failed'));
            return '';
        }

        if ($this->registerLocale($code)) {
            $this->flash->set('success', $this->t->t('admin.lang.lang_added', ['code' => $code]));
            $this->audit->log('lang.add', $code)->drainTo($this->collector);
        } else {
            $this->flash->set('error', $this->t->t('admin.lang.lang_added_unregistered', ['code' => $code]));
        }
        return '';
    }

    /** @param array<string,mixed> $posted */
    private function deleteLanguage(array $posted): string
    {
        $code = strtolower(trim(self::mStr($posted, 'locale', '')));

        // Never delete the primary (reference/fallback) or the configured default.
        if ($code === '' || $code === $this->catalog->primary() || $code === $this->defaultLocale()) {
            $this->flash->set('error', $this->t->t('admin.lang.cannot_delete'));
            return '';
        }

        $r = $this->catalog->deleteLanguage($code)->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('admin.lang.delete_failed'));
            return '';
        }

        $this->unregisterLocale($code);
        $this->flash->set('success', $this->t->t('admin.lang.deleted', ['code' => $code]));
        $this->audit->log('lang.delete', $code)->drainTo($this->collector);
        return '';
    }

    // ── Config registration ───────────────────────────────────────────────────

    /** Append $code to Prelude.available_languages. */
    private function registerLocale(string $code): bool
    {
        $config = $this->loadMainConfig();
        $avail  = $this->availableFrom($config);
        if (!in_array($code, $avail, true)) {
            $avail[] = $code;
        }
        return $this->writeAvailable($config, $avail);
    }

    /** Remove $code from Prelude.available_languages. */
    private function unregisterLocale(string $code): bool
    {
        $config = $this->loadMainConfig();
        $avail  = array_values(array_filter($this->availableFrom($config), static fn(string $l): bool => $l !== $code));
        return $this->writeAvailable($config, $avail);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @param list<string>                         $avail
     */
    private function writeAvailable(array $config, array $avail): bool
    {
        $prelude = isset($config['Prelude']) && is_array($config['Prelude']) ? $config['Prelude'] : [];
        $prelude['available_languages'] = array_values(array_unique($avail));
        $config['Prelude']              = $prelude;
        return $this->writer->writeMainConfig($config)->drainTo($this->collector)->isOk();
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @return list<string>
     */
    private function availableFrom(array $config): array
    {
        $prelude  = isset($config['Prelude']) && is_array($config['Prelude']) ? $config['Prelude'] : [];
        $availRaw = $prelude['available_languages'] ?? ['en'];
        $out      = [];
        if (is_array($availRaw)) {
            foreach ($availRaw as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }
        }
        return $out;
    }

    private function defaultLocale(): string
    {
        $prelude = $this->loadMainConfig()['Prelude'] ?? [];
        $d       = is_array($prelude) ? ($prelude['default_language'] ?? 'en') : 'en';
        return is_string($d) && $d !== '' ? $d : 'en';
    }

    /**
     * Load config.php as a full nested array (all domains preserved).
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadMainConfig(): array
    {
        $path = configDir() . 'config.php';
        if (!is_file($path)) {
            return [];
        }
        /** @var mixed $loaded */
        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }
        /** @var array<string, array<string, mixed>> $loaded */
        return $loaded;
    }

    // ── Context ───────────────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $primary = $this->catalog->primary();
        $domain  = self::queryStr($this->request, 'domain', '');
        $lang    = self::queryStr($this->request, 'lang', '');

        $validDomain = $domain !== '' && in_array($domain, $this->catalog->domains(), true);
        // The language you're working in follows you across domains.
        $currentLang = ($lang !== '' && $this->catalog->localeExists($lang)) ? $lang : $primary;

        $this->ctx->set('form_action', $selfUrl);
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));
        $this->ctx->set('locales_list', implode(', ', $this->catalog->locales()));

        // Render the domain + language sub-navbars in the site header nav stack
        // (like the board / chat contextual navbars) rather than detached in the
        // page body: {{> lang_nav}} resolves this partial-path var and shows only
        // when lang_nav_show is set — absent on every other page, so it self-hides.
        $this->ctx->set('lang_nav',      'partials/lang_nav');
        $this->ctx->set('lang_nav_show', true);

        // The domain sub-navbar is always present.
        $this->buildDomainNav($selfUrl, $validDomain ? $domain : '', $currentLang);

        if ($validDomain) {
            $this->buildEditor($selfUrl, $domain, $currentLang);
        } else {
            $this->buildManage();
        }

        $this->setLabels();
    }

    /**
     * Domain sub-navbar (rendered in the site's secondary-nav style): a Manage
     * link plus every translation domain, active one flagged. Display names are
     * ucfirst()'d so the lowercase 'pages' catalog shows as "Pages".
     *
     * The active flag holds ONLY the static ` class="active"` (like the site's
     * own navbars: {{name}}/{{url}} sit OUTSIDE the {{#active}} section), so the
     * engine's context-loss on entering a scalar section is a non-issue. Flag
     * names are distinct per navbar (d_active vs the tabs' l_active) because the
     * template compiler names section helpers <name><counter> and reuses the
     * counter across sibling loops — same name in two loops would collide.
     */
    private function buildDomainNav(string $selfUrl, string $currentDomain, string $currentLang): void
    {
        $suffix  = '&lang=' . rawurlencode($currentLang);
        $domains = $this->catalog->domains();
        usort($domains, static fn(string $a, string $b): int => strcmp(strtolower($a), strtolower($b)));

        $nav = [];
        foreach ($domains as $d) {
            $nav[] = [
                'label'    => ucfirst($d),
                'url'      => $selfUrl . '?domain=' . rawurlencode($d) . $suffix,
                'd_active' => $d === $currentDomain,
            ];
        }

        $this->ctx->set('domain_nav',    $nav);
        $this->ctx->set('manage_url',    $selfUrl);
        $this->ctx->set('manage_active', $currentDomain === '');
    }

    /** Manage view: add / delete languages + installed list. */
    private function buildManage(): void
    {
        $primary = $this->catalog->primary();
        $default = $this->defaultLocale();
        $langs   = [];
        $sources = [];
        foreach ($this->catalog->locales() as $l) {
            $protected = ($l === $primary || $l === $default);
            $langs[] = [
                'code'          => $l,
                'is_default'    => $protected,
                'deletable'     => !$protected,
                // Delete form renders via an inverted section so value="{{code}}"
                // keeps the item in scope (a normal {{#bool}} loses it here).
                'not_deletable' => $protected,
            ];
            $sources[] = ['code' => $l, 'is_primary' => $l === $primary];
        }

        $this->ctx->set('is_editor',      false);
        $this->ctx->set('languages',      $langs);
        $this->ctx->set('source_locales', $sources);
    }

    /** Editor for one domain, with a language sub-navbar (en / it / …). */
    private function buildEditor(string $selfUrl, string $domain, string $lang): void
    {
        $loaded  = $this->catalog->load($domain);
        $values  = $loaded['values'];
        $primary = $this->catalog->primary();
        $isDefaultPage = ($lang === $primary);

        // Language sub-navbar. l_active holds only ` class="active"`; {{code}}
        // sits outside it, so no context issue.
        $tabs = [];
        foreach ($this->catalog->locales() as $l) {
            $tabs[] = [
                'code'     => $l,
                'url'      => $selfUrl . '?domain=' . rawurlencode($domain) . '&lang=' . rawurlencode($l),
                'l_active' => $l === $lang,
            ];
        }

        // Reference key set: primary first, then any current-language extras.
        $primaryVals = $values[$primary] ?? [];
        $langVals    = $values[$lang] ?? [];
        $order = array_keys($primaryVals);
        foreach (array_keys($langVals) as $k) {
            if (!array_key_exists($k, $primaryVals)) {
                $order[] = $k;
            }
        }

        // hide_ref drives the reference cell through an INVERTED section so
        // {{reference_value}} stays in scope. ro toggles the read-only attribute.
        $ro   = !$loaded['editable'];
        $rows = [];
        foreach ($order as $key) {
            $rows[] = [
                'key'             => $key,
                'reference_value' => $primaryVals[$key] ?? '',
                'field_name'      => 'v[' . $lang . '][' . $key . ']',
                'value'           => $langVals[$key] ?? '',
                'hide_ref'        => $isDefaultPage,
                'ro'              => $ro,
            ];
        }

        $this->ctx->set('is_editor',       true);
        $this->ctx->set('domain',          $domain);
        $this->ctx->set('domain_label',    ucfirst($domain));
        $this->ctx->set('edit_lang',       $lang);
        $this->ctx->set('primary_code',    $primary);
        $this->ctx->set('is_default_page', $isDefaultPage);
        $this->ctx->set('lang_tabs',       $tabs);
        $this->ctx->set('rows',            $rows);
        $this->ctx->set('has_rows',        $rows !== []);
        $this->ctx->set('editable',        $loaded['editable']);
        $this->ctx->set('readonly',        !$loaded['editable']);
        $this->ctx->set('col_span',        $isDefaultPage ? 2 : 3);
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'         => 'admin.lang.heading',
            'lbl_intro'           => 'admin.lang.intro',
            'lbl_installed'       => 'admin.lang.installed',
            'lbl_key'             => 'admin.lang.key',
            'lbl_save'            => 'admin.lang.save',
            'lbl_readonly'        => 'admin.lang.readonly',
            'lbl_add_heading'     => 'admin.lang.add_heading',
            'lbl_add_intro'       => 'admin.lang.add_intro',
            'lbl_new_locale'      => 'admin.lang.new_locale',
            'lbl_new_locale_hint' => 'admin.lang.new_locale_hint',
            'lbl_source_locale'   => 'admin.lang.source_locale',
            'lbl_add_btn'         => 'admin.lang.add_btn',
            'lbl_editing'         => 'admin.lang.editing',
            'lbl_default_badge'   => 'admin.lang.default_badge',
            'lbl_reference'       => 'admin.lang.reference',
            'lbl_manage_langs'    => 'admin.lang.manage_langs',
            'lbl_delete'          => 'admin.lang.delete',
            'lbl_protected'       => 'admin.lang.protected',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
