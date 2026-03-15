<?php
declare(strict_types=1);

namespace AstrX\Template;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\User\UserSession;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\DiagnosticLevel;

/**
 * Mutable template variable bag.
 *
 * Replaces the public $template_args array on ContentManager.
 *
 * Usage:
 *   1. ContentManager calls buildBase($page) before dispatching the controller.
 *   2. The controller injects DefaultTemplateContext and calls set() to add/override vars.
 *   3. ContentManager calls finalise() after the controller returns.
 *   4. ContentManager passes all() to the template engine.
 *
 * Translation key convention (dot-notation, consistent across the whole framework):
 *   {page_url_id}.title         → page title
 *   {page_url_id}.description   → meta description
 *   {page_url_id}.keywords      → meta keywords
 *   generated_in                → "Generated in:" label
 *   go_top                      → "Go top" link label
 *
 * Language files live in:
 *   lang/{locale}/DefaultTemplateContext.{locale}.php
 *   lang/{locale}/{ControllerName}.{locale}.php
 */
final class DefaultTemplateContext
{
    /** @var array<string, mixed> */
    private array $vars = [];

    /** @var list<string> url_ids of the current page and all its ancestors */
    private array $ancestorUrlIds = [];

    public function __construct(
        private readonly Config      $config,
        private readonly Translator  $t,
        private readonly Request     $request,
        private readonly DiagnosticsCollector $collector,
        private readonly DiagnosticRenderer   $renderer,
        private readonly UserSession $userSession,
        private readonly UrlGenerator $urlGenerator,
        private readonly FlashBag    $flashBag,
        private readonly Gate        $gate,
    ) {}

    // -------------------------------------------------------------------------
    // Mutable context API — used by controllers
    // -------------------------------------------------------------------------

    public function set(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->vars) ? $this->vars[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->vars);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->vars;
    }

    // -------------------------------------------------------------------------
    // Called by ContentManager
    // -------------------------------------------------------------------------

    /**
     * Populate base variables from page metadata and config.
     * Called before the controller runs so that controllers can override
     * any of these values by calling set().
     *
     * i18n keys use dot-notation: {url_id}.title, {url_id}.description,
     * {url_id}.keywords. The page's database values serve as fallbacks so
     * the framework stays functional even when a translation file is absent.
     */
    public function buildBase(Page $page): void
    {
        $urlId = $page->urlId;

        $title = $page->i18n
            ? $this->t->t($urlId . '.title',       fallback: $page->title)
            : $page->title;

        $description = $page->i18n
            ? $this->t->t($urlId . '.description', fallback: $page->description)
            : $page->description;

        $keywordParts = [];
        foreach ($page->keywords as $kw) {
            $k              = $kw['keyword'];
            $keywordParts[] = (bool) $kw['i18n'] ? $this->t->t($k, fallback: $k) : $k;
        }
        $keywords = implode(', ', $keywordParts);

        $cssPath = (string) $this->config->getConfig(
            'ContentManager',   // ← correct: loaded at the very top of init()
            'css_file',
            defined('TEMPLATE_DIR') ? TEMPLATE_DIR . 'style.css' : '',
        );
        $css = ($cssPath !== '' && is_file($cssPath)) ? (string)
        str_replace(
            array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '),
            '',
            str_replace(
                ': ',
                ':',
                preg_replace(
                    '!/\*[^*]*\*+([^/][^*]*\*+)*/!',
                    '',
                    file_get_contents($cssPath)
                )
            )
        ) : '';

        $this->vars = [
            'lang'         => $this->t->getLocale(),
            'year'         => date('Y'),
            'title'        => $title,
            'description'  => $description,
            'keywords'     => $keywords,
            'index'        => $page->index,
            'follow'       => $page->follow,
            'include'      => $page->fileName,
            'captcha'      => 'captcha',  // partial name — {{> captcha}} resolves to captcha.html

            'website_name' => (string) $this->config->getConfig('ContentManager', 'website_name', 'AstrX'),
            'title_url'    => (string) $this->config->getConfig('ContentManager', 'title_url', '/'),
            'icon'         => (string) $this->config->getConfig('ContentManager', 'icon', '/favicon.ico'),

            'ip'           => (string) $this->request->ip(),
            'css'          => $css,

            'generated_in' => $this->t->t('generated_in', fallback: 'Generated in:'),
            'go_top'       => $this->t->t('go_top',       fallback: 'Go top'),

            'navbar'       => [],
            'got_results'  => false,
            'results'      => [],
        ];

        // Collect url_ids of this page + all its ancestors for highlight computation
        $this->ancestorUrlIds = array_column($page->ancestors, 'url_id');
    }

    /**
     * Add response-time and diagnostic info.
     * Called by ContentManager after the controller returns, before rendering.
     *
     * Diagnostics are filtered by the configured minimum level, then enriched
     * with a CSS class derived from the level→class map in config so the
     * template can style each entry without any PHP logic in the template itself.
     *
     * Template variables produced:
     *   got_results  bool
     *   results      list<array{message: string, level: string, css_class: string}>
     */
    public function finalise(): void
    {
        // ---- User session nav -------------------------------------------------
        // user_nav comes from navbar id=2 (DB-driven, managed via admin navbar editor).
        // When logged out, we show a single guest link instead.
        $this->vars['user_logged_in'] = $this->userSession->isLoggedIn();
        $this->vars['user_username']  = $this->userSession->username();

        // Guest link — shown when not logged in
        $this->vars['user_page_url']            = $this->urlGenerator->toPage(
            $this->t->t('WORDING_USER', fallback: 'user')
        );
        $this->vars['user_nav_guest_label']      = $this->t->t('user.nav.guest', fallback: 'Login');
        $this->vars['user_nav_guest_highlight']  = in_array('WORDING_USER',  $this->ancestorUrlIds, true)
                                                   || in_array('WORDING_LOGIN', $this->ancestorUrlIds, true);

        // DB-driven user nav (populated by ContentManager from navbar id=2).
        // When logged in, use the DB entries. When logged out, use an empty list
        // so the template falls through to the guest link branch.
        $dbUserNav = $this->vars['db_user_nav'] ?? [];
        $this->vars['user_nav'] = $this->userSession->isLoggedIn() ? $dbUserNav : [];

        // ---- Admin nav --------------------------------------------------------
        // DB-driven: ContentManager pre-loads navbar id=3 entries into db_admin_nav.
        // The Gate check still controls visibility.
        $isAdmin = $this->gate->can(Permission::ADMIN_ACCESS);
        $this->vars['is_admin']   = $isAdmin;
        $this->vars['admin_nav']  = $isAdmin ? ($this->vars['db_admin_nav'] ?? []) : [];

        // ---- Flash messages ---------------------------------------------------
        $flash = $this->flashBag->pull();
        if ($flash !== []) {
            $this->vars['flash_messages']     = $flash;
            $this->vars['has_flash_messages'] = true;
        } else {
            $this->vars['flash_messages']     = [];
            $this->vars['has_flash_messages'] = false;
        }

        $this->vars['time'] = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']), 4)
            : null;

        // Minimum level — controls which diagnostics appear in the status bar.
        // Default: NOTICE in development, ERROR in production.
        $minLevelValue = $this->config->getConfig(
            'ContentManager',
            'status_bar_min_level',
            DiagnosticLevel::NOTICE->value,
        );
        assert(is_int($minLevelValue));
        $minLevel = DiagnosticLevel::from($minLevelValue);

        // Level → CSS class map. Presentational concern: lives in config so
        // themes can override it without touching framework code.
        $levelClasses = $this->config->getConfig(
            'ContentManager',
            'status_bar_level_classes',
            [
                'DEBUG'     => 'diag-debug',
                'INFO'      => 'diag-info',
                'NOTICE'    => 'diag-notice',
                'WARNING'   => 'diag-warning',
                'ERROR'     => 'diag-error',
                'CRITICAL'  => 'diag-critical',
                'ALERT'     => 'diag-alert',
                'EMERGENCY' => 'diag-emergency',
            ],
        );
        assert(is_array($levelClasses));

        $filtered = $this->renderer->renderFiltered(
            $this->collector->diagnostics(),
            $minLevel,
        );

        if ($filtered !== []) {
            $results = [];
            foreach ($filtered as $entry) {
                $levelName = $entry['level']->name;
                $results[] = [
                    'message'     => $entry['message'],
                    'level'       => $levelName,
                    'level_label' => $entry['level_label'],
                    'css_class'   => (string) ($levelClasses[$levelName] ?? 'diag-unknown'),
                ];
            }
            $this->vars['got_results'] = true;
            $this->vars['results']     = $results;
        }
    }
}