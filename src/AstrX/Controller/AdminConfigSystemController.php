<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use function AstrX\Support\configDir;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\ErrorHandler\EnvironmentType;
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

/**
 * Admin — System configuration editor.
 * Edits six config files:
 *   config.php          → Prelude, ModuleLoader, ErrorHandler, Injector
 *   Routing.config.php  → Routing
 *   Session.config.php  → Session
 *   TemplateEngine.config.php → TemplateEngine
 *   Translator.config.php     → Translator
 *   ContentManager.config.php → ContentManager
 * Each file is edited as its own sub-section.
 * All saves are atomic (write to .tmp then rename).
 */
final class AdminConfigSystemController extends AbstractController
{
    private const FORM = 'admin_config_system';

    public function __construct(
        DiagnosticsCollector $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request $request,
        private readonly Config $config,
        private readonly ConfigWriter $writer,
        private readonly Gate $gate,
        private readonly CsrfHandler $csrf,
        private readonly PrgHandler $prg,
        private readonly FlashBag $flash,
        private readonly Page $page,
        private readonly UrlGenerator $urlGen,
        private readonly Translator $t,
    ) {
        parent::__construct($collector);
    }

    public function handle()
    : Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_SYSTEM)) {
            http_response_code(403);

            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n ?
            $this->t->t($this->page->urlId, fallback: $this->page->urlId) :
            $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext();

        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken)
    : void {
        $posted = $this->prg->pull($prgToken)??[];
        $csrfResult = $this->csrf->verify(
            self::FORM,
            self::mStr($posted, '_csrf', '')
        );
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);

            return;
        }

        $section = self::mStr($posted, 'section', '');

        $result = match ($section) {
            'prelude' => $this->savePrelude($posted),
            'routing' => $this->saveRouting($posted),
            'session' => $this->saveSession($posted),
            'template' => $this->saveTemplate($posted),
            'translator' => $this->saveTranslator($posted),
            'contentmanager' => $this->saveContentManager($posted),
            'news'           => $this->saveNews($posted),
            default          => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
            }
        }
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function savePrelude(array $p)
    : Result {
        $envRaw = $p['environment'] ?? null;
        $env = is_int($envRaw) ? $envRaw : (is_numeric($envRaw) ? (int)$envRaw : EnvironmentType::DEVELOPMENT->value);
        $available = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        self::mStr($p, 'available_languages', 'en')
                    )
                ),
                fn($v) => $v !== ''
            )
        );
        $default = trim(self::mStr($p, 'default_language', 'en'));

        $current = $this->loadFile('config');
        $current['Prelude'] = [
            'environment' => $env,
            'available_languages' => $available,
            'default_language' => $default,
        ];

        // Preserve other domains in config.php (ModuleLoader, ErrorHandler, Injector)
        return $this->writer->writeMainConfig($current);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveRouting(array $p)
    : Result {
        return $this->writer->write('Routing', [
            'Routing' => [
                'url_rewrite' => self::mBool($p, 'url_rewrite'),
                'base_path' => trim(self::mStr($p, 'base_path', '/')),
                'entry_point' => trim(self::mStr($p, 'entry_point', 'index.php')),
                'locale_key' => trim(self::mStr($p, 'locale_key', 'lang')),
                'session_key' => trim(self::mStr($p, 'session_key', 'sid')),
                'page_key' => trim(self::mStr($p, 'page_key', 'page')),
                'default_page' => trim(
                    self::mStr($p, 'default_page', 'WORDING_MAIN')
                ),
                'default_keys' => ['locale_key', 'session_key', 'page_key'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveSession(array $p)
    : Result {
        return $this->writer->write('Session', [
            'Session' => [
                'use_cookies' => self::mBool($p, 'use_cookies'),
                'sid_bytes' => max(32, self::mInt($p, 'sid_bytes', 128)),
                'session_id_regex' => trim(
                    self::mStr($p, 'session_id_regex', '')
                ),
                'encrypt' => self::mBool($p, 'encrypt'),
                'cipher' => trim(self::mStr($p, 'cipher', 'aes-256-ctr')),
                'hmac_algo' => trim(self::mStr($p, 'hmac_algo', 'sha256')),
                'prg_token_key' => trim(self::mStr($p, 'prg_token_key', 'prg')),
                'prg_token_regex' => trim(self::mStr($p, 'prg_token_regex', '')),
                'max_sid_retries' => max(1, self::mInt($p, 'max_sid_retries', 8)),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveTemplate(array $p)
    : Result {
        return $this->writer->write('TemplateEngine', [
            'TemplateEngine' => [
                'template_dir' => trim(self::mStr($p, 'template_dir', '')),
                'template_extension' => trim(
                    self::mStr($p, 'template_extension', '.html')
                ),
                'template_cache_dir' => trim(
                    self::mStr($p, 'template_cache_dir', '')
                ),
                'cache_templates' => self::mBool($p, 'cache_templates'),
                'php_processing' => self::mBool($p, 'php_processing'),
                'parse_mode' => self::mInt($p, 'parse_mode', 1),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveTranslator(array $p)
    : Result {
        return $this->writer->write('Translator', [
            'Translator' => [
                'lang_dir' => trim(self::mStr($p, 'lang_dir', '')),
                'fallback_to_key' => self::mBool($p, 'fallback_to_key'),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveContentManager(array $p)
    : Result {
        $extraDomains = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(',', self::mStr($p, 'extra_lang_domains', ''))
                ),
                fn($v) => $v !== ''
            )
        );

        $levelClasses = [];
        foreach (
            [
                'DEBUG',
                'INFO',
                'NOTICE',
                'WARNING',
                'ERROR',
                'CRITICAL',
                'ALERT',
                'EMERGENCY'
            ] as $lvl
        ) {
            $key = 'level_class_' . strtolower($lvl);
            $levelClasses[$lvl] = trim(
                (is_scalar($p[$key] ?? null) ? (string)$p[$key] : ('diag-' . strtolower($lvl)))
            );
        }

        return $this->writer->write('ContentManager', [
            'ContentManager' => [
                'default_template' => trim(
                    self::mStr($p, 'default_template', 'default')
                ),
                'error_page_url_id' => trim(
                    self::mStr($p, 'error_page_url_id', 'WORDING_ERROR')
                ),
                'main_page_id' => trim(
                    self::mStr($p, 'main_page_id', 'WORDING_MAIN')
                ),
                'pages_lang_domain' => trim(
                    self::mStr($p, 'pages_lang_domain', 'pages')
                ),
                'navbar_lang_domain' => trim(
                    self::mStr($p, 'navbar_lang_domain', 'Navbar')
                ),
                'diagnostics_lang_domain' => trim(
                    self::mStr($p, 'diagnostics_lang_domain', 'Diagnostics')
                ),
                'public_navbar_id' => self::mInt($p, 'public_navbar_id', 1),
                'user_navbar_id' => self::mInt($p, 'user_navbar_id', 2),
                'admin_navbar_id' => self::mInt($p, 'admin_navbar_id', 3),
                'extra_lang_domains' => $extraDomains,
                'status_bar_min_level' => self::mInt($p, 'status_bar_min_level', 2),
                'status_bar_level_classes' => $levelClasses,
            ],
        ]);
    }

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveNews(array $p): Result
    {
        return $this->writer->write('News', [
            'News' => [
                'per_page'    => max(1, self::mInt($p, 'per_page', 20)),
                'descending'  => self::mBool($p, 'descending'),
                'pn_key'      => trim(self::mStr($p, 'pn_key', 'pn')),
                'show_key'    => trim(self::mStr($p, 'show_key', 'show')),
                'order_key'   => trim(self::mStr($p, 'order_key', 'order')),
                'page_window' => max(1, self::mInt($p, 'page_window', 3)),
            ],
        ]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext()
    : void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId = $this->prg->createId(
            $this->urlGen->toPage(
                $this->page->i18n ?
                    $this->t->t(
                                  $this->page->urlId,
                        fallback: $this->page->urlId
                    ) : $this->page->urlId
            )
        );

        // Prelude / ModuleLoader / ErrorHandler / Injector (from config.php)
        $this->ctx->set(
            'cfg_env',
            $this->config->getConfigInt(
                'Prelude',
                'environment',
                0
            )
        );
        $this->ctx->set('cfg_env_options', $this->envOptions());
        $this->ctx->set(
            'cfg_available_languages',
            implode(
                ', ',
                (array)$this->config->getConfig(
                    'Prelude',
                    'available_languages',
                    ['en']
                )
            )
        );
        $this->ctx->set(
            'cfg_default_language',
            $this->config->getConfigString(
                'Prelude',
                'default_language',
                'en'
            )
        );

        // Routing
        $this->ctx->set(
            'cfg_url_rewrite',
            $this->config->getConfigBool(
                'Routing',
                'url_rewrite',
                true
            )
        );
        $this->ctx->set(
            'cfg_base_path',
            $this->config->getConfigString(
                'Routing',
                'base_path',
                '/'
            )
        );
        $this->ctx->set(
            'cfg_entry_point',
            $this->config->getConfigString(
                'Routing',
                'entry_point',
                'index.php'
            )
        );
        $this->ctx->set(
            'cfg_locale_key',
            $this->config->getConfigString(
                'Routing',
                'locale_key',
                'lang'
            )
        );
        $this->ctx->set(
            'cfg_session_key',
            $this->config->getConfigString(
                'Routing',
                'session_key',
                'sid'
            )
        );
        $this->ctx->set(
            'cfg_page_key',
            $this->config->getConfigString(
                'Routing',
                'page_key',
                'page'
            )
        );
        $this->ctx->set(
            'cfg_default_page',
            $this->config->getConfigString(
                'Routing',
                'default_page',
                'WORDING_MAIN'
            )
        );

        // Session
        $this->ctx->set(
            'cfg_use_cookies',
            $this->config->getConfigBool(
                'Session',
                'use_cookies',
                true
            )
        );
        $this->ctx->set(
            'cfg_sid_bytes',
            $this->config->getConfigInt(
                'Session',
                'sid_bytes',
                128
            )
        );
        $this->ctx->set(
            'cfg_session_id_regex',
            $this->config->getConfigString(
                'Session',
                'session_id_regex',
                ''
            )
        );
        $this->ctx->set(
            'cfg_encrypt',
            $this->config->getConfigBool(
                'Session',
                'encrypt',
                true
            )
        );
        $this->ctx->set(
            'cfg_cipher',
            $this->config->getConfigString(
                'Session',
                'cipher',
                'aes-256-ctr'
            )
        );
        $this->ctx->set(
            'cfg_hmac_algo',
            $this->config->getConfigString(
                'Session',
                'hmac_algo',
                'sha256'
            )
        );
        $this->ctx->set(
            'cfg_prg_token_key',
            $this->config->getConfigString(
                'Session',
                'prg_token_key',
                'prg'
            )
        );
        $this->ctx->set(
            'cfg_prg_token_regex',
            $this->config->getConfigString(
                'Session',
                'prg_token_regex',
                ''
            )
        );
        $this->ctx->set(
            'cfg_max_sid_retries',
            $this->config->getConfigInt(
                'Session',
                'max_sid_retries',
                8
            )
        );

        // TemplateEngine
        $this->ctx->set(
            'cfg_template_dir',
            $this->config->getConfigString(
                'TemplateEngine',
                'template_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_template_extension',
            $this->config->getConfigString(
                'TemplateEngine',
                'template_extension',
                '.html'
            )
        );
        $this->ctx->set(
            'cfg_template_cache_dir',
            $this->config->getConfigString(
                'TemplateEngine',
                'template_cache_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_cache_templates',
            $this->config->getConfigBool(
                'TemplateEngine',
                'cache_templates',
                true
            )
        );
        $this->ctx->set(
            'cfg_php_processing',
            $this->config->getConfigBool(
                'TemplateEngine',
                'php_processing',
                false
            )
        );
        $this->ctx->set(
            'cfg_parse_mode',
            $this->config->getConfigInt(
                'TemplateEngine',
                'parse_mode',
                1
            )
        );

        // Translator
        $this->ctx->set(
            'cfg_lang_dir',
            $this->config->getConfigString(
                'Translator',
                'lang_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_fallback_to_key',
            $this->config->getConfigBool(
                'Translator',
                'fallback_to_key',
                true
            )
        );

        // ContentManager
        $this->ctx->set(
            'cfg_default_template',
            $this->config->getConfigString(
                'ContentManager',
                'default_template',
                'default'
            )
        );
        $this->ctx->set(
            'cfg_error_page_url_id',
            $this->config->getConfigString(
                'ContentManager',
                'error_page_url_id',
                'WORDING_ERROR'
            )
        );
        $this->ctx->set(
            'cfg_main_page_id',
            $this->config->getConfigString(
                'ContentManager',
                'main_page_id',
                'WORDING_MAIN'
            )
        );
        $this->ctx->set(
            'cfg_pages_lang_domain',
            $this->config->getConfigString(
                'ContentManager',
                'pages_lang_domain',
                'pages'
            )
        );
        $this->ctx->set(
            'cfg_navbar_lang_domain',
            $this->config->getConfigString(
                'ContentManager',
                'navbar_lang_domain',
                'Navbar'
            )
        );
        $this->ctx->set(
            'cfg_diagnostics_lang_domain',
            $this->config->getConfigString(
                'ContentManager',
                'diagnostics_lang_domain',
                'Diagnostics'
            )
        );
        $this->ctx->set(
            'cfg_public_navbar_id',
            $this->config->getConfigInt(
                'ContentManager',
                'public_navbar_id',
                1
            )
        );
        $this->ctx->set(
            'cfg_user_navbar_id',
            $this->config->getConfigInt(
                'ContentManager',
                'user_navbar_id',
                2
            )
        );
        $this->ctx->set(
            'cfg_admin_navbar_id',
            $this->config->getConfigInt(
                'ContentManager',
                'admin_navbar_id',
                3
            )
        );
        $this->ctx->set(
            'cfg_extra_lang_domains',
            implode(
                ', ',
                (array)$this->config->getConfig(
                    'ContentManager',
                    'extra_lang_domains',
                    []
                )
            )
        );
        $this->ctx->set(
            'cfg_status_bar_min_level',
            $this->config->getConfigInt(
                'ContentManager',
                'status_bar_min_level',
                2
            )
        );

        $levelClasses = (array)$this->config->getConfig(
            'ContentManager',
            'status_bar_level_classes',
            []
        );
        foreach (
            [
                'DEBUG',
                'INFO',
                'NOTICE',
                'WARNING',
                'ERROR',
                'CRITICAL',
                'ALERT',
                'EMERGENCY'
            ] as $lvl
        ) {
            $this->ctx->set(
                'cfg_level_class_' . strtolower($lvl),
                (is_scalar($levelClasses[$lvl] ?? null) ? (string)$levelClasses[$lvl] : ('diag-' . strtolower($lvl)))
            );
        }

        // ── News (News.config.php) ────────────────────────────────────────
        $this->ctx->set('cfg_per_page',    $this->config->getConfigInt('News', 'per_page',    20));
        $this->ctx->set('cfg_descending',  $this->config->getConfigBool('News', 'descending',  true));
        $this->ctx->set('cfg_pn_key',      $this->config->getConfigString('News', 'pn_key',    'pn'));
        $this->ctx->set('cfg_show_key',    $this->config->getConfigString('News', 'show_key',  'show'));
        $this->ctx->set('cfg_order_key',   $this->config->getConfigString('News', 'order_key', 'order'));
        $this->ctx->set('cfg_page_window', $this->config->getConfigInt('News', 'page_window', 3));

        $this->ctx->set('csrf_token', $csrfToken);
        $this->ctx->set('prg_id', $prgId);
        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Load the full array from an existing config file (all domains).
     * Falls back to empty array if file is missing/unreadable.
     * @return array<string, array<string, mixed>>
     */
    private function loadFile(string $baseName): array
    {
        // 'config' is the main config.php, not a module config file.
        $suffix = $baseName === 'config' ? '.php' : '.config.php';
        $path   = (configDir() . $baseName . $suffix);
        if (!is_file($path)) { return []; }
        $loaded = @include $path;
        if (!is_array($loaded)) { return []; }
        /** @var array<string,array<string,mixed>> $loaded */
        return $loaded;
    }

    /** @return list<array{value:int,label:string,selected:bool}> */
    private function envOptions()
    : array
    {
        $current = $this->config->getConfigInt('Prelude', 'environment', 0);
        $opts = [];
        foreach (EnvironmentType::cases() as $case) {
            $opts[] = [
                'value' => $case->value,
                'label' => $case->name,
                'selected' => $case->value === $current,
            ];
        }

        return $opts;
    }

    private function setI18n()
    : void
    {
        $this->ctx->set('heading', $this->t->t('admin.config.system.heading'));
        $this->ctx->set(
            'section_prelude',
            $this->t->t('admin.config.system.prelude')
        );
        $this->ctx->set(
            'section_routing',
            $this->t->t('admin.config.system.routing')
        );
        $this->ctx->set(
            'section_session',
            $this->t->t('admin.config.system.session')
        );
        $this->ctx->set(
            'section_template',
            $this->t->t('admin.config.system.template')
        );
        $this->ctx->set(
            'section_translator',
            $this->t->t('admin.config.system.translator')
        );
        $this->ctx->set(
            'section_contentmanager',
            $this->t->t('admin.config.system.contentmanager')
        );
        $this->ctx->set(
            'label_environment',
            $this->t->t('admin.config.field.environment')
        );
        $this->ctx->set(
            'label_available_languages',
            $this->t->t('admin.config.field.available_languages')
        );
        $this->ctx->set(
            'label_default_language',
            $this->t->t('admin.config.field.default_language')
        );
        $this->ctx->set(
            'label_url_rewrite',
            $this->t->t('admin.config.field.url_rewrite')
        );
        $this->ctx->set(
            'label_base_path',
            $this->t->t('admin.config.field.base_path')
        );
        $this->ctx->set(
            'label_entry_point',
            $this->t->t('admin.config.field.entry_point')
        );
        $this->ctx->set(
            'label_locale_key',
            $this->t->t('admin.config.field.locale_key')
        );
        $this->ctx->set(
            'label_session_key',
            $this->t->t('admin.config.field.session_key')
        );
        $this->ctx->set(
            'label_page_key',
            $this->t->t('admin.config.field.page_key')
        );
        $this->ctx->set(
            'label_default_page',
            $this->t->t('admin.config.field.default_page')
        );
        $this->ctx->set(
            'label_use_cookies',
            $this->t->t('admin.config.field.use_cookies')
        );
        $this->ctx->set(
            'label_sid_bytes',
            $this->t->t('admin.config.field.sid_bytes')
        );
        $this->ctx->set(
            'label_session_id_regex',
            $this->t->t('admin.config.field.session_id_regex')
        );
        $this->ctx->set(
            'label_encrypt',
            $this->t->t('admin.config.field.encrypt')
        );
        $this->ctx->set(
            'label_cipher',
            $this->t->t('admin.config.field.cipher')
        );
        $this->ctx->set(
            'label_hmac_algo',
            $this->t->t('admin.config.field.hmac_algo')
        );
        $this->ctx->set(
            'label_prg_token_key',
            $this->t->t('admin.config.field.prg_token_key')
        );
        $this->ctx->set(
            'label_prg_token_regex',
            $this->t->t('admin.config.field.prg_token_regex')
        );
        $this->ctx->set(
            'label_max_sid_retries',
            $this->t->t('admin.config.field.max_sid_retries')
        );
        $this->ctx->set(
            'label_template_dir',
            $this->t->t('admin.config.field.template_dir')
        );
        $this->ctx->set(
            'label_template_extension',
            $this->t->t('admin.config.field.template_extension')
        );
        $this->ctx->set(
            'label_template_cache_dir',
            $this->t->t('admin.config.field.template_cache_dir')
        );
        $this->ctx->set(
            'label_cache_templates',
            $this->t->t('admin.config.field.cache_templates')
        );
        $this->ctx->set(
            'label_php_processing',
            $this->t->t('admin.config.field.php_processing')
        );
        $this->ctx->set(
            'label_parse_mode',
            $this->t->t('admin.config.field.parse_mode')
        );
        $this->ctx->set(
            'label_lang_dir',
            $this->t->t('admin.config.field.lang_dir')
        );
        $this->ctx->set(
            'label_fallback_to_key',
            $this->t->t('admin.config.field.fallback_to_key')
        );
        $this->ctx->set(
            'label_default_template',
            $this->t->t('admin.config.field.default_template')
        );
        $this->ctx->set(
            'label_error_page_url_id',
            $this->t->t('admin.config.field.error_page_url_id')
        );
        $this->ctx->set(
            'label_main_page_id',
            $this->t->t('admin.config.field.main_page_id')
        );
        $this->ctx->set(
            'label_pages_lang_domain',
            $this->t->t('admin.config.field.pages_lang_domain')
        );
        $this->ctx->set(
            'label_navbar_lang_domain',
            $this->t->t('admin.config.field.navbar_lang_domain')
        );
        $this->ctx->set(
            'label_diagnostics_lang_domain',
            $this->t->t(
                'admin.config.field.diagnostics_lang_domain'
            )
        );
        $this->ctx->set(
            'label_public_navbar_id',
            $this->t->t('admin.config.field.public_navbar_id')
        );
        $this->ctx->set(
            'label_user_navbar_id',
            $this->t->t('admin.config.field.user_navbar_id')
        );
        $this->ctx->set(
            'label_admin_navbar_id',
            $this->t->t('admin.config.field.admin_navbar_id')
        );
        $this->ctx->set(
            'label_extra_lang_domains',
            $this->t->t('admin.config.field.extra_lang_domains')
        );
        $this->ctx->set(
            'label_status_bar_min_level',
            $this->t->t('admin.config.field.status_bar_min_level')
        );
        $this->ctx->set(
            'label_level_classes',
            $this->t->t('admin.config.field.level_classes')
        );
        // News section labels
        $this->ctx->set('section_news',      $this->t->t('admin.config.content.news'));
        $this->ctx->set('label_per_page',    $this->t->t('admin.config.field.per_page'));
        $this->ctx->set('label_descending',  $this->t->t('admin.config.field.descending'));
        $this->ctx->set('label_pn_key',      $this->t->t('admin.config.field.pn_key'));
        $this->ctx->set('label_show_key',    $this->t->t('admin.config.field.show_key'));
        $this->ctx->set('label_order_key',   $this->t->t('admin.config.field.order_key'));
        $this->ctx->set('label_page_window', $this->t->t('admin.config.field.page_window'));
        $this->ctx->set('btn_save', $this->t->t('admin.btn.save'));
    }
}
