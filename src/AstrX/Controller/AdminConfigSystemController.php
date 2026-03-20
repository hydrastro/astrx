<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
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
            (string)($posted['_csrf']??'')
        );
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);

            return;
        }

        $section = (string)($posted['section']??'');

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

    /** @param array<string, mixed> $p */
    private function savePrelude(array $p)
    : Result {
        $env = (int)($p['environment']??EnvironmentType::DEVELOPMENT->value);
        $available = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string)($p['available_languages']??'en')
                    )
                ),
                fn($v) => $v !== ''
            )
        );
        $default = trim((string)($p['default_language']??'en'));

        $current = $this->loadFile('config');
        $current['Prelude'] = [
            'environment' => $env,
            'available_languages' => $available,
            'default_language' => $default,
        ];

        // Preserve other domains in config.php (ModuleLoader, ErrorHandler, Injector)
        return $this->writer->write('config', $current);
    }

    /** @param array<string, mixed> $p */
    private function saveRouting(array $p)
    : Result {
        return $this->writer->write('Routing', [
            'Routing' => [
                'url_rewrite' => !empty($p['url_rewrite']),
                'base_path' => trim((string)($p['base_path']??'/')),
                'entry_point' => trim((string)($p['entry_point']??'index.php')),
                'locale_key' => trim((string)($p['locale_key']??'lang')),
                'session_key' => trim((string)($p['session_key']??'sid')),
                'page_key' => trim((string)($p['page_key']??'page')),
                'default_page' => trim(
                    (string)($p['default_page']??'WORDING_MAIN')
                ),
                'default_keys' => ['locale_key', 'session_key', 'page_key'],
            ],
        ]);
    }

    /** @param array<string, mixed> $p */
    private function saveSession(array $p)
    : Result {
        return $this->writer->write('Session', [
            'Session' => [
                'use_cookies' => !empty($p['use_cookies']),
                'sid_bytes' => max(32, (int)($p['sid_bytes']??128)),
                'session_id_regex' => trim(
                    (string)($p['session_id_regex']??'')
                ),
                'encrypt' => !empty($p['encrypt']),
                'cipher' => trim((string)($p['cipher']??'aes-256-ctr')),
                'hmac_algo' => trim((string)($p['hmac_algo']??'sha256')),
                'prg_token_key' => trim((string)($p['prg_token_key']??'prg')),
                'prg_token_regex' => trim((string)($p['prg_token_regex']??'')),
                'max_sid_retries' => max(1, (int)($p['max_sid_retries']??8)),
            ],
        ]);
    }

    /** @param array<string, mixed> $p */
    private function saveTemplate(array $p)
    : Result {
        return $this->writer->write('TemplateEngine', [
            'TemplateEngine' => [
                'template_dir' => trim((string)($p['template_dir']??'')),
                'template_extension' => trim(
                    (string)($p['template_extension']??'.html')
                ),
                'template_cache_dir' => trim(
                    (string)($p['template_cache_dir']??'')
                ),
                'cache_templates' => !empty($p['cache_templates']),
                'php_processing' => !empty($p['php_processing']),
                'parse_mode' => (int)($p['parse_mode']??1),
            ],
        ]);
    }

    /** @param array<string, mixed> $p */
    private function saveTranslator(array $p)
    : Result {
        return $this->writer->write('Translator', [
            'Translator' => [
                'lang_dir' => trim((string)($p['lang_dir']??'')),
                'fallback_to_key' => !empty($p['fallback_to_key']),
            ],
        ]);
    }

    /** @param array<string, mixed> $p */
    private function saveContentManager(array $p)
    : Result {
        $extraDomains = array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(',', (string)($p['extra_lang_domains']??''))
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
                (string)($p[$key]??'diag-' . strtolower($lvl))
            );
        }

        return $this->writer->write('ContentManager', [
            'ContentManager' => [
                'default_template' => trim(
                    (string)($p['default_template']??'default')
                ),
                'error_page_url_id' => trim(
                    (string)($p['error_page_url_id']??'WORDING_ERROR')
                ),
                'main_page_id' => trim(
                    (string)($p['main_page_id']??'WORDING_MAIN')
                ),
                'pages_lang_domain' => trim(
                    (string)($p['pages_lang_domain']??'pages')
                ),
                'navbar_lang_domain' => trim(
                    (string)($p['navbar_lang_domain']??'Navbar')
                ),
                'diagnostics_lang_domain' => trim(
                    (string)($p['diagnostics_lang_domain']??'Diagnostics')
                ),
                'public_navbar_id' => (int)($p['public_navbar_id']??1),
                'user_navbar_id' => (int)($p['user_navbar_id']??2),
                'admin_navbar_id' => (int)($p['admin_navbar_id']??3),
                'extra_lang_domains' => $extraDomains,
                'status_bar_min_level' => (int)($p['status_bar_min_level']??2),
                'status_bar_level_classes' => $levelClasses,
            ],
        ]);
    }

    /** @param array<string, mixed> $p */
    private function saveNews(array $p): Result
    {
        return $this->writer->write('News', [
            'News' => [
                'per_page'    => max(1, (int) ($p['per_page']   ?? 20)),
                'descending'  => !empty($p['descending']),
                'pn_key'      => trim((string) ($p['pn_key']    ?? 'pn')),
                'show_key'    => trim((string) ($p['show_key']  ?? 'show')),
                'order_key'   => trim((string) ($p['order_key'] ?? 'order')),
                'page_window' => max(1, (int) ($p['page_window'] ?? 3)),
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
            (int)$this->config->getConfig(
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
            (string)$this->config->getConfig(
                'Prelude',
                'default_language',
                'en'
            )
        );

        // Routing
        $this->ctx->set(
            'cfg_url_rewrite',
            (bool)$this->config->getConfig(
                'Routing',
                'url_rewrite',
                true
            )
        );
        $this->ctx->set(
            'cfg_base_path',
            (string)$this->config->getConfig(
                'Routing',
                'base_path',
                '/'
            )
        );
        $this->ctx->set(
            'cfg_entry_point',
            (string)$this->config->getConfig(
                'Routing',
                'entry_point',
                'index.php'
            )
        );
        $this->ctx->set(
            'cfg_locale_key',
            (string)$this->config->getConfig(
                'Routing',
                'locale_key',
                'lang'
            )
        );
        $this->ctx->set(
            'cfg_session_key',
            (string)$this->config->getConfig(
                'Routing',
                'session_key',
                'sid'
            )
        );
        $this->ctx->set(
            'cfg_page_key',
            (string)$this->config->getConfig(
                'Routing',
                'page_key',
                'page'
            )
        );
        $this->ctx->set(
            'cfg_default_page',
            (string)$this->config->getConfig(
                'Routing',
                'default_page',
                'WORDING_MAIN'
            )
        );

        // Session
        $this->ctx->set(
            'cfg_use_cookies',
            (bool)$this->config->getConfig(
                'Session',
                'use_cookies',
                true
            )
        );
        $this->ctx->set(
            'cfg_sid_bytes',
            (int)$this->config->getConfig(
                'Session',
                'sid_bytes',
                128
            )
        );
        $this->ctx->set(
            'cfg_session_id_regex',
            (string)$this->config->getConfig(
                'Session',
                'session_id_regex',
                ''
            )
        );
        $this->ctx->set(
            'cfg_encrypt',
            (bool)$this->config->getConfig(
                'Session',
                'encrypt',
                true
            )
        );
        $this->ctx->set(
            'cfg_cipher',
            (string)$this->config->getConfig(
                'Session',
                'cipher',
                'aes-256-ctr'
            )
        );
        $this->ctx->set(
            'cfg_hmac_algo',
            (string)$this->config->getConfig(
                'Session',
                'hmac_algo',
                'sha256'
            )
        );
        $this->ctx->set(
            'cfg_prg_token_key',
            (string)$this->config->getConfig(
                'Session',
                'prg_token_key',
                'prg'
            )
        );
        $this->ctx->set(
            'cfg_prg_token_regex',
            (string)$this->config->getConfig(
                'Session',
                'prg_token_regex',
                ''
            )
        );
        $this->ctx->set(
            'cfg_max_sid_retries',
            (int)$this->config->getConfig(
                'Session',
                'max_sid_retries',
                8
            )
        );

        // TemplateEngine
        $this->ctx->set(
            'cfg_template_dir',
            (string)$this->config->getConfig(
                'TemplateEngine',
                'template_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_template_extension',
            (string)$this->config->getConfig(
                'TemplateEngine',
                'template_extension',
                '.html'
            )
        );
        $this->ctx->set(
            'cfg_template_cache_dir',
            (string)$this->config->getConfig(
                'TemplateEngine',
                'template_cache_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_cache_templates',
            (bool)$this->config->getConfig(
                'TemplateEngine',
                'cache_templates',
                true
            )
        );
        $this->ctx->set(
            'cfg_php_processing',
            (bool)$this->config->getConfig(
                'TemplateEngine',
                'php_processing',
                false
            )
        );
        $this->ctx->set(
            'cfg_parse_mode',
            (int)$this->config->getConfig(
                'TemplateEngine',
                'parse_mode',
                1
            )
        );

        // Translator
        $this->ctx->set(
            'cfg_lang_dir',
            (string)$this->config->getConfig(
                'Translator',
                'lang_dir',
                ''
            )
        );
        $this->ctx->set(
            'cfg_fallback_to_key',
            (bool)$this->config->getConfig(
                'Translator',
                'fallback_to_key',
                true
            )
        );

        // ContentManager
        $this->ctx->set(
            'cfg_default_template',
            (string)$this->config->getConfig(
                'ContentManager',
                'default_template',
                'default'
            )
        );
        $this->ctx->set(
            'cfg_error_page_url_id',
            (string)$this->config->getConfig(
                'ContentManager',
                'error_page_url_id',
                'WORDING_ERROR'
            )
        );
        $this->ctx->set(
            'cfg_main_page_id',
            (string)$this->config->getConfig(
                'ContentManager',
                'main_page_id',
                'WORDING_MAIN'
            )
        );
        $this->ctx->set(
            'cfg_pages_lang_domain',
            (string)$this->config->getConfig(
                'ContentManager',
                'pages_lang_domain',
                'pages'
            )
        );
        $this->ctx->set(
            'cfg_navbar_lang_domain',
            (string)$this->config->getConfig(
                'ContentManager',
                'navbar_lang_domain',
                'Navbar'
            )
        );
        $this->ctx->set(
            'cfg_diagnostics_lang_domain',
            (string)$this->config->getConfig(
                'ContentManager',
                'diagnostics_lang_domain',
                'Diagnostics'
            )
        );
        $this->ctx->set(
            'cfg_public_navbar_id',
            (int)$this->config->getConfig(
                'ContentManager',
                'public_navbar_id',
                1
            )
        );
        $this->ctx->set(
            'cfg_user_navbar_id',
            (int)$this->config->getConfig(
                'ContentManager',
                'user_navbar_id',
                2
            )
        );
        $this->ctx->set(
            'cfg_admin_navbar_id',
            (int)$this->config->getConfig(
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
            (int)$this->config->getConfig(
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
                (string)($levelClasses[$lvl]??'diag-' . strtolower($lvl))
            );
        }

        // ── News (News.config.php) ────────────────────────────────────────
        $this->ctx->set('cfg_per_page',    (int)  $this->config->getConfig('News', 'per_page',    20));
        $this->ctx->set('cfg_descending',  (bool) $this->config->getConfig('News', 'descending',  true));
        $this->ctx->set('cfg_pn_key',      (string) $this->config->getConfig('News', 'pn_key',    'pn'));
        $this->ctx->set('cfg_show_key',    (string) $this->config->getConfig('News', 'show_key',  'show'));
        $this->ctx->set('cfg_order_key',   (string) $this->config->getConfig('News', 'order_key', 'order'));
        $this->ctx->set('cfg_page_window', (int)  $this->config->getConfig('News', 'page_window', 3));

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
    private function loadFile(string $baseName)
    : array {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') .
                $baseName .
                '.config.php';
        if (!is_file($path)) {
            return [];
        }
        $loaded = @include $path;

        return is_array($loaded) ? $loaded : [];
    }

    /** @return list<array{value:int,label:string,selected:bool}> */
    private function envOptions()
    : array
    {
        $current = (int)$this->config->getConfig('Prelude', 'environment', 0);
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