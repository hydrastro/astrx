<?php
declare(strict_types=1);

namespace AstrX\Template;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;

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
 * Controllers should depend on DefaultTemplateContext (or a narrower interface)
 * rather than on ContentManager, to avoid the bidirectional coupling.
 */
final class DefaultTemplateContext
{
    /** @var array<string, mixed> */
    private array $vars = [];

    public function __construct(
        private readonly Config $config,
        private readonly Translator $t,
        private readonly Request $request,
        private readonly DiagnosticsCollector $collector,
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
     */
    public function buildBase(Page $page): void
    {
        $title = $page->i18n
            ? $this->t->t($page->urlId . '_PAGE_TITLE', fallback: $page->title)
            : $page->title;

        $description = $page->i18n
            ? $this->t->t($page->urlId . '_PAGE_DESCRIPTION', fallback: $page->description)
            : $page->description;

        $keywords = $page->i18n
            ? $this->t->t($page->urlId . '_PAGE_KEYWORDS', fallback: '')
            : '';

        $cssPath = (string) $this->config->getConfig(
            'TemplateEngine',
            'css_file',
            defined('TEMPLATE_DIR') ? TEMPLATE_DIR . 'style.css' : '',
        );
        $css = ($cssPath !== '' && is_file($cssPath)) ? (string) file_get_contents($cssPath) : '';

        $this->vars = [
            'lang'         => $this->t->getLocale(),
            'year'         => date('Y'),
            'title'        => $title,
            'description'  => $description,
            'keywords'     => $keywords,
            'index'        => $page->index,
            'follow'       => $page->follow,
            'include'      => $page->fileName,

            'website_name' => (string) $this->config->getConfig('ContentManager', 'website_name', 'AstrX'),
            'title_url'    => (string) $this->config->getConfig('ContentManager', 'title_url', '/'),
            'icon'         => (string) $this->config->getConfig('ContentManager', 'icon', '/favicon.ico'),

            'ip'           => (string) $this->request->ip(),
            'css'          => $css,

            'generated_in' => $this->t->t('generated_in', fallback: 'Generated in:'),
            'go_top'       => $this->t->t('go_top', fallback: 'Go top'),

            'navbar'       => [],
            'got_results'  => false,
            'results'      => [],
        ];
    }

    /**
     * Add response-time and diagnostic info.
     * Called by ContentManager after the controller returns, before rendering.
     */
    public function finalise(): void
    {
        $this->vars['time'] = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']), 4)
            : null;

        $diagStrings = [];
        foreach ($this->collector->diagnostics() as $d) {
            $diagStrings[] = (string) $d;
        }

        if ($diagStrings !== []) {
            $this->vars['got_results'] = true;
            $this->vars['results']     = $diagStrings;
        }
    }
}
