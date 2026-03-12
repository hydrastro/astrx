<?php

declare(strict_types = 1);

namespace AstrX\Template;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;

final class DefaultTemplateContext
{
    public function __construct(
        private Config $config,
        private Translator $t,
        private Request $request,
        private DiagnosticsCollector $collector,
    ) {
    }

    /** @return array<string,mixed> */
    public function beforeController(Page $page)
    : array {
        $year = date('Y');

        // Title/description: if page i18n, translate using url_id-derived keys
        $title = $page->i18n ?
            $this->t->t($page->urlId . '_PAGE_TITLE', fallback: $page->title) :
            $page->title;
        $description = $page->i18n ?
            $this->t->t(
                $page->urlId . '_PAGE_DESCRIPTION',
                fallback: $page->description
            ) : $page->description;

        // keywords: keep it simple for now; you can re-add DB keywords later
        $keywords = $page->i18n ?
            $this->t->t($page->urlId . '_PAGE_KEYWORDS', fallback: '') : '';

        // css inline (your old design)
        $cssPath = (string)$this->config->getConfig(
            'TemplateEngine',
            'css_file',
            TEMPLATE_DIR . 'style.css'
        );
        $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';

        // include partial: this is your Mustache partial
        $include = $page->fileName; // e.g. "main" => loads main.html as partial

        return [
            'lang' => $this->t->getLocale(),
            'year' => $year,
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'index' => $page->index,
            'follow' => $page->follow,
            'include' => $include,

            // project identity (configure these later)
            'website_name' => (string)$this->config->getConfig(
                'ContentManager',
                'website_name',
                'AstrX'
            ),
            'title_url' => (string)$this->config->getConfig(
                'ContentManager',
                'title_url',
                '/'
            ),
            'icon' => (string)$this->config->getConfig(
                'ContentManager',
                'icon',
                '/favicon.ico'
            ),

            // footer
            'ip' => (string)$this->request->ip(),

            // misc
            'css' => $css,
            'generated_in' => $this->t->t(
                          'WORDING_GENERATED_IN',
                fallback: 'Generated in:'
            ),
            'go_top' => $this->t->t('WORDING_GO_TOP', fallback: 'Go top'),

            // navbar placeholder (you can wire NavigationBar later)
            'navbar' => [],
            'got_results' => false,
            'results' => [],
        ];
    }

    /** @return array<string,mixed> */
    public function afterController()
    : array
    {
        $time = isset($_SERVER['REQUEST_TIME_FLOAT']) ?
            round(
                (microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']),
                4
            ) : null;

        // If you want to show diagnostics in template:
        $diagStrings = [];
        foreach ($this->collector->diagnostics() as $d) {
            $diagStrings[] = (string)$d;
        }

        return [
            'time' => $time,
            'got_results' => $diagStrings !== [],
            'results' => $diagStrings,
        ];
    }
}