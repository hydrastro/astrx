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
use AstrX\Module\ModuleRegistry;
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
 * Admin — Languages: a no-JavaScript translation editor.
 *
 * Two views on one page (PRG + CSRF, ADMIN_ACCESS gated):
 *   • index (no ?domain)  — every translation domain grouped into "Modules"
 *     (domains owned by an installed module) and "Core", plus a form to add a
 *     whole new language by cloning an existing locale.
 *   • editor (?domain=X)  — a table of that domain's keys with one editable
 *     column per installed locale, so a translator edits en / it / … side by
 *     side. Saving rewrites every locale's catalog file for the domain.
 *
 * Adding a language clones the source locale's entire catalog tree (so the site
 * renders immediately) and registers the new code in available_languages, after
 * which ContentManager accepts /<code>/… on the next request.
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
        private readonly ModuleRegistry         $registry,
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
            'save'         => $this->save($posted),
            'add_language' => $this->addLanguage($posted),
            default        => '',
        };
    }

    /** @param array<string,mixed> $posted */
    private function save(array $posted): string
    {
        $domain = self::mStr($posted, 'domain', '');
        if ($domain === '') {
            return '';
        }

        // v[<locale>][<key>] — PHP keeps the dotted key intact inside brackets.
        $raw      = self::mArray($posted, 'v');
        $byLocale = [];
        foreach ($this->catalog->locales() as $locale) {
            $localeRaw = $raw[$locale] ?? null;
            $pairs     = [];
            if (is_array($localeRaw)) {
                foreach ($localeRaw as $key => $value) {
                    if (is_string($key)) {
                        $pairs[$key] = self::str($value);
                    }
                }
            }
            $byLocale[$locale] = $pairs;
        }

        $r = $this->catalog->save($domain, $byLocale)->drainTo($this->collector);
        if ($r->isOk()) {
            $this->flash->set('success', $this->t->t('admin.lang.saved'));
            $this->audit->log('lang.save', $domain)->drainTo($this->collector);
        } else {
            $this->flash->set('error', $this->t->t('admin.lang.save_failed'));
        }
        return '?domain=' . rawurlencode($domain);
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

        // Register the new code in available_languages so ContentManager routes
        // /<code>/… . Files already exist, so a config hiccup leaves the site
        // intact — only the new locale stays unrouted until the admin adds it.
        $reg = $this->registerLocale($code);
        if ($reg) {
            $this->flash->set('success', $this->t->t('admin.lang.lang_added', ['code' => $code]));
            $this->audit->log('lang.add', $code)->drainTo($this->collector);
        } else {
            $this->flash->set('error', $this->t->t('admin.lang.lang_added_unregistered', ['code' => $code]));
        }
        return '?domain=' . rawurlencode($this->firstEditableDomain());
    }

    /** Append $code to Prelude.available_languages in config.php. */
    private function registerLocale(string $code): bool
    {
        $config  = $this->loadMainConfig();
        $prelude = isset($config['Prelude']) && is_array($config['Prelude']) ? $config['Prelude'] : [];

        $availRaw = $prelude['available_languages'] ?? ['en'];
        $avail    = [];
        if (is_array($availRaw)) {
            foreach ($availRaw as $v) {
                if (is_string($v) && $v !== '') {
                    $avail[] = $v;
                }
            }
        }
        if (!in_array($code, $avail, true)) {
            $avail[] = $code;
        }

        $prelude['available_languages'] = array_values(array_unique($avail));
        $config['Prelude']              = $prelude;

        return $this->writer->writeMainConfig($config)->drainTo($this->collector)->isOk();
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
        $domain = self::queryStr($this->request, 'domain', '');

        $this->ctx->set('form_action', $selfUrl);
        $this->ctx->set('index_url',   $selfUrl);
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));
        $this->ctx->set('locales_list', implode(', ', $this->catalog->locales()));

        if ($domain !== '' && in_array($domain, $this->catalog->domains(), true)) {
            $this->buildEditor($selfUrl, $domain);
        } else {
            $this->buildIndex($selfUrl);
        }

        $this->setLabels();
    }

    private function buildIndex(string $selfUrl): void
    {
        $moduleKeys = $this->registry->moduleKeys();
        $modules    = [];
        $core       = [];
        foreach ($this->catalog->domains() as $d) {
            $lc  = strtolower($d);
            $row = ['name' => $d, 'url' => $selfUrl . '?domain=' . rawurlencode($d)];
            if (in_array($lc, $moduleKeys, true)) {
                $row['disabled'] = !$this->registry->enabled($lc);
                $modules[] = $row;
            } else {
                $core[] = $row;
            }
        }

        $this->ctx->set('is_editor',          false);
        $this->ctx->set('module_domains',     $modules);
        $this->ctx->set('has_module_domains', $modules !== []);
        $this->ctx->set('core_domains',       $core);
        $this->ctx->set('has_core_domains',   $core !== []);

        // Add-language form: source locale options.
        $sources = [];
        foreach ($this->catalog->locales() as $l) {
            $sources[] = ['code' => $l, 'is_primary' => $l === $this->catalog->primary()];
        }
        $this->ctx->set('source_locales', $sources);
    }

    private function buildEditor(string $selfUrl, string $domain): void
    {
        $loaded  = $this->catalog->load($domain);
        $locales = $loaded['locales'];
        $values  = $loaded['values'];

        // Union of keys across locales, primary order first.
        $order = [];
        $seen  = [];
        foreach ($locales as $locale) {
            foreach (array_keys($values[$locale] ?? []) as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $order[]    = $key;
                }
            }
        }

        $rows = [];
        foreach ($order as $key) {
            $cells = [];
            foreach ($locales as $locale) {
                $cells[] = [
                    'locale'     => $locale,
                    'field_name' => 'v[' . $locale . '][' . $key . ']',
                    'value'      => $values[$locale][$key] ?? '',
                ];
            }
            $rows[] = ['key' => $key, 'cells' => $cells];
        }

        $this->ctx->set('is_editor',    true);
        $this->ctx->set('domain',       $domain);
        $this->ctx->set('editor_locales', array_map(static fn(string $l): array => ['code' => $l], $locales));
        $this->ctx->set('locale_span',  count($locales) + 1);
        $this->ctx->set('rows',         $rows);
        $this->ctx->set('has_rows',     $rows !== []);
        $this->ctx->set('editable',     $loaded['editable']);
        $this->ctx->set('readonly',     !$loaded['editable']);
    }

    private function firstEditableDomain(): string
    {
        $domains = $this->catalog->domains();
        return $domains[0] ?? '';
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'        => 'admin.lang.heading',
            'lbl_intro'          => 'admin.lang.intro',
            'lbl_modules'        => 'admin.lang.modules',
            'lbl_core'           => 'admin.lang.core',
            'lbl_installed'      => 'admin.lang.installed',
            'lbl_edit'           => 'admin.lang.edit',
            'lbl_disabled'       => 'admin.lang.disabled',
            'lbl_key'            => 'admin.lang.key',
            'lbl_save'           => 'admin.lang.save',
            'lbl_back'           => 'admin.lang.back',
            'lbl_readonly'       => 'admin.lang.readonly',
            'lbl_add_heading'    => 'admin.lang.add_heading',
            'lbl_add_intro'      => 'admin.lang.add_intro',
            'lbl_new_locale'     => 'admin.lang.new_locale',
            'lbl_new_locale_hint'=> 'admin.lang.new_locale_hint',
            'lbl_source_locale'  => 'admin.lang.source_locale',
            'lbl_add_btn'        => 'admin.lang.add_btn',
            'lbl_editing'        => 'admin.lang.editing',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
