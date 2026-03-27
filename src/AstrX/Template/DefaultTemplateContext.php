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
use AstrX\Pagination\Pagination;
use AstrX\Template\SubPageState;
use AstrX\Template\CommentState;
use AstrX\Auth\DiagnosticVisibilityChecker;
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

    // ── Deferred URL generation ──────────────────────────────────────────
    // Controllers register state objects instead of computed URL strings.
    // finalise() generates all pagination URLs once both states are known.

    /** Guard: resolveUrls() is idempotent. */
    private bool $urlsResolved = false;

    /** State of the primary paginated controller (e.g. news). Null if page has no SubPage. */
    private ?SubPageState $subPageState = null;

    /** State of the comment controller. Null if page has no comments. */
    private ?CommentState $commentState = null;

    /** Stored Pagination object for deferred toTemplateVars() call. */
    private ?Pagination $pagination   = null;

    /** Page window for deferred Pagination::toTemplateVars(). */
    private int $pageWindow = 3;

    public function __construct(
        private readonly Config      $config,
        private readonly Translator  $t,
        private readonly Request     $request,
        private readonly DiagnosticsCollector $collector,
        private readonly DiagnosticRenderer           $renderer,
        private readonly DiagnosticVisibilityChecker  $checker,
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

    // ── Deferred URL registration ─────────────────────────────────────────

    /**
     * Register the primary controller's pagination state.
     * Replaces computing news pagination URLs in the controller — finalise() does it.
     */
    public function setSubPageState(SubPageState $state): void
    {
        $this->subPageState = $state;
    }

    /**
     * Store the Pagination object for deferred toTemplateVars() call.
     * Must be called with the same Pagination that was used for data fetching
     * (i.e. after withTotal()).
     */
    public function setPagination(Pagination $pagination, int $pageWindow = 3): void
    {
        $this->pagination  = $pagination;
        $this->pageWindow  = $pageWindow;
    }

    /**
     * Register the comment controller's pagination state.
     * Replaces computing comment pagination URLs in CommentController.
     */
    public function setCommentState(CommentState $state): void
    {
        $this->commentState = $state;
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
    /**
     * Build the template include path from ancestor file_names + the page's own.
     *
     * Examples:
     *   admin_banlist (child of admin) → 'admin/admin_banlist'
     *   profile       (child of user)  → 'user/profile'
     *   main          (no ancestors)   → 'main'
     *
     * Uses file_name (never translated) so this is fully i18n-safe.
     * Skips the self-reference (page_closure includes the page as its own ancestor).
     * Sorts by id ascending so root ancestors come first in the path.
     */
    private function buildIncludePath(Page $page): string
    {
        $ancestors = $page->ancestors;
        usort($ancestors, fn($a, $b) => $a['id'] <=> $b['id']);

        $parts = [];
        foreach ($ancestors as $anc) {
            $fn = $anc['file_name'];
            if ($fn !== '' && $fn !== $page->fileName) {
                $parts[] = $fn;
            }
        }
        $parts[] = $page->fileName;
        return implode('/', $parts);
    }

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
                    (string) file_get_contents($cssPath)
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
            'include'      => $this->buildIncludePath($page),
            'captcha'      => 'partials/captcha',  // partial name — {{> captcha}} resolves to captcha.html

            'website_name' => (string) $this->config->getConfig('ContentManager', 'website_name', 'AstrX'),
            'title_url'    => (string) $this->config->getConfig('ContentManager', 'title_url', '/'),
            'icon'         => (string) $this->config->getConfig('ContentManager', 'icon', '/favicon.ico'),

            'ip'           => (string) $this->request->ip(),
            'css'          => $css,

            'generated_in' => $this->t->t('generated_in', fallback: 'Generated in:'),
            'go_top'       => $this->t->t('go_top',       fallback: 'Go top'),

            'navbar'       => [],
            'has_messages'      => false,
            'messages'          => [],
            'got_results'       => false,
            'results'           => [],
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
        // ── Deferred URL resolution ───────────────────────────────────────────
        // Guard: resolveUrls() is called by ContentManager before comments are
        // pre-rendered. The guard ensures a second call from finalise() is a no-op.
        $this->resolveUrls();

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

        if ($this->userSession->isLoggedIn() && $dbUserNav !== []) {
            // If on the user section root with no child entry highlighted,
            // force-highlight the first nav entry (User Home).
            $anyUserChildHighlighted = false;
            foreach ($dbUserNav as $i => $e) {
                if ($i > 0 && $e['highlight']) {
                    $anyUserChildHighlighted = true;
                    break;
                }
            }
            $onUserRoot = in_array('WORDING_USER', $this->ancestorUrlIds, true)
                          && !$anyUserChildHighlighted;
            $processedUserNav = [];
            foreach ($dbUserNav as $i => $e) {
                if ($i === 0 && $onUserRoot) {
                    $e['highlight'] = true;
                }
                $processedUserNav[] = $e;
            }
            // Append the logout CSRF token to the logout entry URL.
            // LogoutController::handle() verifies this token before acting.
            $logoutToken = \AstrX\Controller\LogoutController::getOrCreateToken();
            $navWithToken = [];
            foreach ($processedUserNav as $entry) {
                if (str_contains((string) ($entry['url'] ?? ''), 'logout')
                    || str_contains((string) ($entry['name'] ?? ''), 'ogout')) {
                    $sep = str_contains((string) $entry['url'], '?') ? '&' : '?';
                    $entry['url'] = $entry['url'] . $sep . '_lt=' . rawurlencode($logoutToken);
                }
                $navWithToken[] = $entry;
            }
            $this->vars['user_nav'] = $navWithToken;
        } else {
            $this->vars['user_nav'] = [];
        }

        // ---- Admin nav --------------------------------------------------------
        // DB-driven: ContentManager pre-loads navbar id=3 entries into db_admin_nav.
        // The Gate check still controls visibility.
        $isAdmin = $this->gate->can(Permission::ADMIN_ACCESS);
        $this->vars['is_admin'] = $isAdmin;

        if ($isAdmin) {
            $rawAdminNav = $this->vars['db_admin_nav'] ?? [];
            // Dashboard (admin root, page_id=18) is an ancestor of every admin page,
            // so NavbarHandler always marks it highlighted. Override: highlight Dashboard
            // only when no other admin nav entry is also highlighted (i.e. we are
            // actually on the root itself, not a child page).
            $anyChildHighlighted = false;
            foreach ($rawAdminNav as $i => $entry) {
                if ($i > 0 && $entry['highlight']) {
                    $anyChildHighlighted = true;
                    break;
                }
            }
            $adminNav = [];
            foreach ($rawAdminNav as $i => $entry) {
                if ($i === 0) {
                    // First entry is always Dashboard
                    $entry['highlight'] = $entry['highlight'] && !$anyChildHighlighted;
                }
                $adminNav[] = $entry;
            }
            $this->vars['admin_nav'] = $adminNav;
        } else {
            $this->vars['admin_nav'] = [];
        }

        $this->vars['time'] = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']), 4)
            : null;

        // Minimum level — controls which diagnostics appear in the message bar.
        $minLevelValue = $this->config->getConfig(
            'ContentManager',
            'status_bar_min_level',
            DiagnosticLevel::NOTICE->value,
        );
        assert(is_int($minLevelValue));
        $minLevel = DiagnosticLevel::from($minLevelValue);

        // Level → CSS class map.
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
            $this->checker,
        );

        // Merge flash messages and diagnostic messages into one unified list.
        // Flash messages are controller-driven (already translated);
        // their 'type' maps directly to a css_class. Diagnostics follow.
        $messages = [];

        foreach ($this->flashBag->pull() as $flash) {
            $messages[] = [
                'message'     => $flash['text'],
                'level'       => strtoupper($flash['type']),
                'level_label' => '',
                'css_class'   => 'flash-' . $flash['type'],
            ];
        }

        foreach ($filtered as $entry) {
            $levelName  = $entry['level']->name;
            $messages[] = [
                'message'     => $entry['message'],
                'level'       => $levelName,
                'level_label' => $entry['level_label'],
                'css_class'   => (string) ($levelClasses[$levelName] ?? 'diag-unknown'),
            ];
        }

        $this->vars['has_messages']       = $messages !== [];
        $this->vars['messages']           = $messages;
        // Legacy aliases — keep existing templates working.
        $this->vars['got_results']        = $messages !== [];
        $this->vars['results']            = $messages;
        $this->vars['has_flash_messages'] = false;
        $this->vars['flash_messages']     = [];
    }

    // =========================================================================
    // Private — deferred URL generation
    // =========================================================================

    /**
     * Generate all deferred pagination URLs now that both SubPageState and
     * CommentState are known.
     *
     * SubPage pagination:
     *   Each page link appends comment path segments as pathSegments so the
     *   news URL correctly encodes both navigation states, e.g.:
     *     /en/main/3/desc/1/2/asc   (news page 3, comment page 2 with asc)
     *
     * Comment pagination:
     *   Each comment link prepends the current SubPage path prefix, e.g.:
     *     /en/main/4/desc/1/3/asc   (news page 4, comment page 3 with asc)
     *   On non-SubPage pages:
     *     /en/user/3/asc            (comment page 3, no news prefix)
     *
     * Query mode: path segments become ?key=val pairs — same logic, different output.
     *
     * This method also generates:
     *   news_comment_inputs  — hidden inputs carrying comment state through news filter form
     *   comments_base_query_inputs — hidden inputs carrying news state through comment filter form (query mode)
     *   comments_filter_action     — action URL for comment filter form
     */
    /** Idempotent: safe to call multiple times (second call is a no-op). */
    public function resolveUrls(): void
    {
        if ($this->urlsResolved) { return; }
        $this->urlsResolved = true;

        // Always initialise comment vars so templates never receive Undefined notices
        // on pages without comments (where setCommentState() was never called).
        $emptyString = '';
        foreach ([
                     'comments_filter_action', 'comments_base_query_inputs',
                     'comments_prev_url', 'comments_next_url',
                     'comments_first_url', 'comments_last_url',
                     'comments_to_asc_url', 'comments_to_desc_url',
                     'comments_to_nest_url', 'comments_to_flat_url',
                     'news_comment_inputs',
                 ] as $_k) {
            $this->vars[$_k] = $emptyString;
        }
        foreach ([
                     'comments_has_pagination', 'comments_has_prev', 'comments_has_next',
                     'comments_has_first', 'comments_has_last',
                 ] as $_k) {
            $this->vars[$_k] = false;
        }
        $this->vars['comments_pages'] = [];

        $urlRewrite = (bool) $this->config->getConfig('Routing', 'url_rewrite', true);
        $sp = $this->subPageState;
        $cs = $this->commentState;

        // ── SubPage pagination ────────────────────────────────────────────────
        if ($this->pagination !== null && $sp !== null) {
            // Comment segs to suffix onto every news pagination link.
            $commentSegs   = $cs !== null ? $cs->toPathSegments() : [];
            $commentExtra  = $cs !== null ? $cs->toQueryParams()  : [];
            $urlGenerator  = $this->urlGenerator;

            $urlForPage = function (int $p) use ($sp, $commentSegs, $commentExtra, $urlRewrite, $urlGenerator): string {
                return $urlGenerator->toSubPage(
                    resolvedUrlId:  $sp->resolvedUrlId,
                    page:           $p,
                    order:          $sp->order,
                    perPage:        $sp->perPage,
                    defaultPage:    $sp->defaultPage,
                    defaultOrder:   $sp->defaultOrder,
                    defaultPerPage: $sp->defaultPerPage,
                    extraQuery:     $urlRewrite ? [] : $commentExtra,
                    pathSegments:   $urlRewrite ? $commentSegs : [],
                );
            };

            foreach ($this->pagination->toTemplateVars($urlForPage, $this->pageWindow) as $k => $v) {
                $this->vars[$k] = $v;
            }
        }

        // ── Comment pagination ────────────────────────────────────────────────
        if ($cs !== null) {
            $urlGenerator = $this->urlGenerator;

            // Build a URL for arbitrary comment params (page, order, perPage, indent).
            $commentUrlFor = function (
                int $p, ?string $ord = null, ?int $show = null, ?int $ind = null
            ) use ($cs, $sp, $urlRewrite, $urlGenerator): string {
                $variant = $cs->withPage($p);
                if ($ord  !== null) { $variant = $variant->withOrder($ord); }
                if ($show !== null) { $variant = $variant->withPerPage($show); }
                if ($ind  !== null) { $variant = $variant->withIndent($ind); }

                $segs  = $variant->toPathSegments();
                $query = $variant->toQueryParams();

                if ($sp !== null) {
                    // SubPage prefix: comment state goes after news segments.
                    return $urlGenerator->toSubPage(
                        resolvedUrlId:  $sp->resolvedUrlId,
                        page:           $sp->page,
                        order:          $sp->order,
                        perPage:        $sp->perPage,
                        defaultPage:    $sp->defaultPage,
                        defaultOrder:   $sp->defaultOrder,
                        defaultPerPage: $sp->defaultPerPage,
                        extraQuery:     $urlRewrite ? [] : $query,
                        pathSegments:   $urlRewrite ? $segs : [],
                    );
                }

                // No SubPage prefix — comment state goes directly after page root.
                $pageBase = $urlGenerator->toPage($cs->resolvedPageUrlId);
                if ($urlRewrite && $segs !== []) {
                    return $pageBase . '/' . implode('/', $segs);
                }
                return $query !== [] ? $pageBase . '?' . http_build_query($query) : $pageBase;
            };

            $pageNum   = $cs->page;
            $pageCount = $cs->pageCount;
            $pageWindow = $cs->pageWindow;

            $prevUrl  = $pageNum > 1          ? $commentUrlFor($pageNum - 1) : '';
            $nextUrl  = $pageNum < $pageCount ? $commentUrlFor($pageNum + 1) : '';
            $firstUrl = $pageNum > 1          ? $commentUrlFor(1)            : '';
            $lastUrl  = $pageNum < $pageCount ? $commentUrlFor($pageCount)   : '';

            $pages = [];
            if ($pageCount > 1) {
                $lo = max(1, $pageNum - $pageWindow);
                $hi = min($pageCount, $pageNum + $pageWindow);
                for ($p = $lo; $p <= $hi; $p++) {
                    $url     = $p !== $pageNum ? $commentUrlFor($p) : '';
                    $pages[] = [
                        'number' => $p,
                        'url'    => $url,
                        'link'   => $url !== ''
                            ? '<a href="' . htmlspecialchars($url) . '">' . $p . '</a>'
                            : '',
                    ];
                }
            }

            $order  = $cs->order;
            $perPage = $cs->perPage;
            $indent = $cs->indent;

            // Filter form action: the news-state URL without comment segs.
            // Submitting the comment filter form sends the new co/cs/ci as
            // fresh query params, which the page then routes correctly.
            if ($sp !== null) {
                $filterAction = $urlGenerator->toSubPage(
                    resolvedUrlId:  $sp->resolvedUrlId,
                    page:           $sp->page,
                    order:          $sp->order,
                    perPage:        $sp->perPage,
                    defaultPage:    $sp->defaultPage,
                    defaultOrder:   $sp->defaultOrder,
                    defaultPerPage: $sp->defaultPerPage,
                    extraQuery:     [],
                    pathSegments:   [],
                );
            } else {
                $filterAction = $urlGenerator->toPage($cs->resolvedPageUrlId);
            }

            // Hidden inputs for the comment filter form in query mode:
            // the news sub-params (pn/order/show) live in the query string and
            // must be forwarded so the GET form does not lose them.
            $filterHiddenInputs = '';
            if (!$urlRewrite && $sp !== null) {
                $newsParams = [];
                if ($sp->page !== $sp->defaultPage)    { $newsParams[$sp->pnKey]    = $sp->page; }
                if ($sp->order !== $sp->defaultOrder)  { $newsParams[$sp->orderKey] = $sp->order; }
                if ($sp->perPage !== $sp->defaultPerPage) { $newsParams[$sp->showKey] = $sp->perPage; }
                foreach ($newsParams as $k => $v) {
                    $ek = htmlspecialchars($k, ENT_QUOTES);
                    $ev = htmlspecialchars((string) $v, ENT_QUOTES);
                    $filterHiddenInputs .= "<input type=\"hidden\" name=\"{$ek}\" value=\"{$ev}\">\n";
                }
            }

            $this->vars['comments_has_pagination']     = $pageCount > 1;
            $this->vars['comments_pages']              = $pages;
            $this->vars['comments_prev_url']           = $prevUrl;
            $this->vars['comments_next_url']           = $nextUrl;
            $this->vars['comments_first_url']          = $firstUrl;
            $this->vars['comments_last_url']           = $lastUrl;
            $this->vars['comments_has_prev']           = $prevUrl  !== '';
            $this->vars['comments_has_next']           = $nextUrl  !== '';
            $this->vars['comments_has_first']          = $firstUrl !== '';
            $this->vars['comments_has_last']           = $lastUrl  !== '';
            $this->vars['comments_to_asc_url']         = $commentUrlFor(1, 'asc',  $perPage, $indent);
            $this->vars['comments_to_desc_url']        = $commentUrlFor(1, 'desc', $perPage, $indent);
            $this->vars['comments_to_nest_url']        = $commentUrlFor(1, $order, $perPage, 1);
            $this->vars['comments_to_flat_url']        = $commentUrlFor(1, $order, $perPage, 0);
            $this->vars['comments_filter_action']      = $filterAction;
            $this->vars['comments_base_query_inputs']  = $filterHiddenInputs;
        }

        // ── news_comment_inputs ───────────────────────────────────────────────
        // Hidden inputs that carry the current comment state through the news
        // filter form submission so the comment page/order is not lost.
        if ($cs !== null) {
            $commentParams = $cs->toQueryParams();
            $inputs = '';
            foreach ($commentParams as $k => $v) {
                $ek = htmlspecialchars($k, ENT_QUOTES);
                $ev = htmlspecialchars((string) $v, ENT_QUOTES);
                $inputs .= "<input type=\"hidden\" name=\"{$ek}\" value=\"{$ev}\">\n";
            }
            $this->vars['news_comment_inputs'] = $inputs;
        }
    }

}
