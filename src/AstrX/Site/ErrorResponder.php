<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\ErrorHandler\EnvironmentType;
use AstrX\Http\HttpStatus;
use AstrX\I18n\DiagnosticRenderer;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Kernel\Controller;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\TemplateEngine;
use AstrX\Template\TemplateVars;
use AstrX\Theme\ThemeService;

/**
 * Produces the response for a request that cannot be served normally: the
 * site's themed error page when the page table and templates are reachable,
 * otherwise a self-contained failsafe page that needs no database and no
 * template engine. Both carry the baseline security headers.
 */
final class ErrorResponder
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly Config               $config,
        private readonly Translator           $translator,
        private readonly DiagnosticsCollector $collector,
        private readonly SecurityHeaders      $headers,
        private readonly SiteFlags            $flags,
        private readonly ControllerMap        $controllers,
    ) {}

    /**
     * Set the response code, then render the error page (or the failsafe).
     *
     * Every "this request cannot continue" point in the pipeline ends here, so
     * errors produce a useful page instead of a blank response.
     */
public function respond(HttpStatus $status): void
    {
        http_response_code($status->value);

        // Error pages are full HTML documents too: give them the same CSP /
        // Referrer-Policy / nosniff / frame protections as the main render path.
        // Covers both the templated error page and the failsafe below (the helper
        // no-ops if headers were already sent).
        $this->headers->emit($this->flags);

        // Load the Http lang domain so ErrorController has its translations.
        $this->translator->load('Http');

        // Try the full error page route.
        $errorUrlId = $this->config->getConfig(
            'ContentManager', 'error_page_url_id', 'WORDING_ERROR'
        );
        if (!is_string($errorUrlId)) {
            $errorUrlId = 'WORDING_ERROR';
        }

        $phResult = $this->injector->getClass(\AstrX\Page\PageHandler::class);
        if ($phResult->isOk()) {
            /** @var \AstrX\Page\PageHandler $ph */
            $ph  = $phResult->unwrap();
            $eid = $ph->getPageIdFromUrlId($errorUrlId);
            $errorPage = $eid !== null ? $ph->getPage($eid) : null;

            if ($errorPage !== null) {
                $this->injector->setClass($errorPage);
                $this->renderErrorPage($errorPage);
                return;
            }
        }

        // Failsafe: no templates, no database.
        $this->renderFailsafe($status);
    }

    /**
     * Render a single page (used by renderError to display the error page).
     * A stripped-down version of the normal page render in ContentManager.
     */
    private function renderErrorPage(Page $page): void
    {
        $this->translator->load(ucfirst($page->fileName));

        $ctxResult = $this->injector->createClass(DefaultTemplateContext::class)
            ->drainTo($this->collector);
        if (!$ctxResult->isOk()) { return; }
        /** @var DefaultTemplateContext $ctx */
        $ctx = $ctxResult->unwrap();
        // Feature controllers ask for the TemplateVars interface; point it at
        // THIS request's context (bind() copies the instance registered now).
        $this->injector->bind(TemplateVars::class, DefaultTemplateContext::class);
        $ctx->buildBase($page);

        if ($page->controller) {
            $fqcn = $this->controllers->classFor($page->fileName);
            if ($fqcn !== null && class_exists($fqcn)) {
                $controllerResult = $this->injector->createClass($fqcn)
                    ->drainTo($this->collector);
                if ($controllerResult->isOk()) {
                    $controller = $controllerResult->unwrap();
                    if ($controller instanceof Controller) {
                        $controller->handle()->drainTo($this->collector);
                    }
                }
            }
        }

        if ($page->template) {
            $engineResult = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector);
            if (!$engineResult->isOk()) { return; }
            /** @var TemplateEngine $engine */
            $engine = $engineResult->unwrap();
            $templateName = $this->config->getConfigString(
                'ContentManager', 'default_template', 'default'
            );
            $ctx->resolveUrls();
            $ctx->set('page_comments', false);
            $ctx->set('comments_html', '');
            $ctx->finalise();
            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);
            if ($renderResult->isOk()) {
                echo $renderResult->unwrap();
            }
        }
    }

    /**
     * The database-free error page.
     *
     * Reached whenever the themed error page cannot be built — above all when
     * the database itself is down, which is exactly when an operator most needs
     * to be told something. It therefore uses nothing but the config, the
     * filesystem and the Http lang domain renderError() has already loaded:
     *
     *   - the status name and message come from the Http catalog
     *     (http.status.<code>.*, whose en/it parity tools/check_lang_parity.php
     *     enforces) instead of the English literals that used to be inlined here;
     *   - the active theme's stylesheet is inlined, so a site with no database
     *     still answers in its own skin rather than as an unstyled browser
     *     default — ThemeService reads the filesystem only, and any failure
     *     resolving it simply means no <style> block;
     *   - in a dev-like environment the collected diagnostics are listed, so
     *     environment=testing still debugs. That list is safe by construction:
     *     the database diagnostics carry codes, never the DSN, host, user or
     *     password (see Database\ConnectionFactory). It stays OFF in production/staging, where a
     *     visitor must learn nothing from a 5xx.
     */
    private function renderFailsafe(HttpStatus $status): void
    {
        $code = $status->value;

        // Fall back to the enum's own name ("SERVICE_UNAVAILABLE" →
        // "Service Unavailable") if the catalog has no entry for this code.
        $nameFallback = ucwords(strtolower(str_replace('_', ' ', $status->name)));
        $name    = $this->translator->t('http.status.' . $code . '.name', fallback: $nameFallback);
        $message = $this->translator->t(
            'http.status.' . $code . '.message',
            fallback: 'An error occurred. Please try again or contact the administrator.',
        );
        $siteName = $this->config->getConfigString('ContentManager', 'website_name', 'AstrX');

        $lang    = $this->esc($this->translator->getLocale());
        $safeSite = $this->esc($siteName);
        $safeName = $this->esc($name);
        $safeMsg  = $this->esc($message);

        // Inline the theme CSS. ThemeService only touches config + filesystem,
        // but this is the failsafe: guard it so a broken theme cannot turn the
        // last-resort page into a second failure.
        $css = '';
        $themeResult = $this->injector->createClass(ThemeService::class);
        if ($themeResult->isOk()) {
            $theme = $themeResult->unwrap();
            if ($theme instanceof ThemeService) {
                $css = $theme->activeStylesheetContent();
            }
        }
        $styleBlock = $css !== ''
            // </style> is the only sequence that can break out of a style
            // element; the stylesheet is an operator-installed file, not input.
            ? '<style>' . str_replace('</', '<\/', $css) . '</style>'
            : '';

        echo '<!DOCTYPE html>' . "\n"
           . '<html lang="' . $lang . '">' . "\n"
           . '<head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow">'
           . '<title>' . $code . ' ' . $safeName . '</title>' . $styleBlock . '</head>' . "\n"
           . '<body>' . "\n"
           . '<div id="wrap">' . "\n"
           . '  <div id="header"><h1 id="title">' . $safeSite . '</h1></div>' . "\n"
           . '  <div id="main">' . "\n"
           . '    <h2>' . $code . ' — ' . $safeName . '</h2>' . "\n"
           . '    <p>' . $safeMsg . '</p>' . "\n"
           . $this->failsafeDiagnosticsHtml()
           . '  </div>' . "\n"
           . '</div>' . "\n"
           . '</body>' . "\n"
           . '</html>' . "\n";
    }

    /**
     * The diagnostics block for the failsafe page — dev-like environments only.
     *
     * Returns '' in production and staging. Renders through DiagnosticRenderer
     * so every line is the same translated, catalog-defined sentence the status
     * bar would show; a code with no catalog entry stays a [FALLBACK:…] stamp
     * rather than leaking an object dump.
     */
    private function failsafeDiagnosticsHtml(): string
    {
        $env = EnvironmentType::tryFrom($this->config->getConfigInt(
            'Prelude',
            'environment',
            EnvironmentType::PRODUCTION->value,
        )) ?? EnvironmentType::PRODUCTION;

        if (!$env->isDevLike()) {
            return '';
        }

        $rendererResult = $this->injector->getClass(DiagnosticRenderer::class);
        if (!$rendererResult->isOk()) {
            return '';
        }
        $renderer = $rendererResult->unwrap();
        if (!$renderer instanceof DiagnosticRenderer) {
            return '';
        }

        // A failsafe assumes nothing about how far the request got. init() loads
        // this catalog just before opening the database, but renderError() is
        // also reachable from paths that never ran that line, and a renderer
        // with no catalog prints "[FALLBACK:CRITICAL] astrx.database/…" instead
        // of the sentence. loadDomain() re-registers the same keys, so calling
        // it a second time costs one require and changes nothing.
        $renderer->load($this->config->getConfigString(
            'ContentManager',
            'diagnostics_lang_domain',
            'Diagnostics',
        ));

        // No DiagnosticVisibilityChecker: it reads its rules from the database,
        // which in this path is precisely what is unavailable. The environment
        // gate above is the access control here.
        $rows = $renderer->renderFiltered(
            $this->collector->diagnostics(),
            DiagnosticLevel::NOTICE,
        );
        if ($rows === []) {
            return '';
        }

        $html = '    <div id="message_bar">' . "\n";
        foreach ($rows as $row) {
            $html .= '      <p class="diag-' . strtolower($row['level']->name) . '">'
                   . '[' . $this->esc($row['level_label']) . '] '
                   . $this->esc($row['message']) . '</p>' . "\n";
        }

        return $html . '    </div>' . "\n";
    }

    /** HTML-escape for the failsafe page, which has no template engine to do it. */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
