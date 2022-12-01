<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Default Template.
 */
class DefaultTemplateHandler
{
    public const ERROR_INVALID_I18N_KEYWORD = 0;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    private Config $config;
    private Injector $injector;
    private ErrorHandler $ErrorHandler;
    private Page $page;
    private UrlHandler $UrlHandler;

    public function __construct(
        Config $config,
        Injector $injector,
        ErrorHandler $ErrorHandler,
        Page $page,
        UrlHandler $UrlHandler
    ) {
        $this->config = $config;
        $this->injector = $injector;
        $this->ErrorHandler = $ErrorHandler;
        $this->page = $page;
        $this->UrlHandler = $UrlHandler;
    }

    /**
     * Get Template Args.
     * Returns the template arguments needed for rendering.
     * This function is called before the controller init.
     * @return array<string, mixed>
     */
    public function getTemplateArgs()
    : array
    {
        $template_args = array();
        // Setting the page meta tags.
        $template_args["index"] = $this->page->index;
        $template_args["follow"] = $this->page->follow;
        $template_args["content"] = $this->page->file_name;

        // Setting the page title and description.
        if ($this->page->i18n) {
            $this->config->loadPageLang($this->page->url_id);
            if (defined($this->page->url_id . "_PAGE_TITLE")) {
                $template_args["title"] = constant(
                    $this->page->url_id . "_PAGE_TITLE"
                );
            }
            if (defined($this->page->url_id . "_PAGE_DESCRIPTION")) {
                $template_args["description"] = constant(
                    $this->page->url_id . "_PAGE_DESCRIPTION"
                );
            }
        } else {
            $template_args["title"] = $this->page->title;
            $template_args["description"] = $this->page->description;
        }

        // Loading the page keywords.
        $this->config->loadKeywordsLang();
        $keywords_filter = function (array $keywords, bool $i18n) {
            return array_filter(
                array_map(
                    function ($item) use ($i18n) {
                        return $this->keywordsFilter($item, $i18n);
                    },
                    $keywords
                )
            );
        };
        $i18n_keywords = $keywords_filter($this->page->keywords, true);
        $normal_keywords = $keywords_filter($this->page->keywords, false);
        $keywords = array();
        foreach ($i18n_keywords as $keyword) {
            if (defined($keyword)) {
                $keywords[] = constant($keyword);
            } else {
                $this->results[] = array(
                    self::ERROR_INVALID_I18N_KEYWORD,
                    array("keyword" => $keyword)
                );
            }
        }
        $keywords = array_merge($keywords, $normal_keywords);

        // Keywords are all capitalised by default because keywords (which
        // can also use URL IDs constants) are (SHOULD) all be stored in
        // lowercase.
        $template_args["keywords"] = implode(
            ", ",
            array_map(function ($val) {
                assert(is_string($val));

                return ucwords($val);
            }, $keywords)
        );

        // Setting navigation bar arguments.
        $NavigationBar = $this->injector->getClass("NavigationBar");
        assert($NavigationBar instanceof NavigationBar);
        $navigation_bar = $NavigationBar->getNavigationBar();
        $cleaned_navigation_bar = array();
        foreach ($navigation_bar as $entry) {
            assert(is_array($entry));
            if ($entry["internal"]) {
                $url = $this->UrlHandler->getUrl(
                    array(
                        "page_id_parameter_name" => $entry["page_id"]
                    ),
                    true,
                    false,
                    true
                );
                $highlight = ($this->page->id == $entry["page_id"]);
            } else {
                $url = $entry["url"];
                $highlight = false;
            }
            if ($entry["i18n"]) {
                if (defined($entry["name"])) {
                    $name = constant($entry["name"]);
                } else {
                    $name = "error";
                }
            } else {
                $name = $entry["name"];
            }
            $cleaned_navigation_bar[] = array(
                "name" => $name,
                "url" => $url,
                "highlight" => $highlight
            );
        }
        $template_args["navbar"] = $cleaned_navigation_bar;

        return $template_args;
    }

    /**
     * Keywords Filter.
     * This is a helper function used for filtering out I18N keywords from
     * non-I18N ones.
     *
     * @param array<string, mixed> $keyword Keyword array.
     * @param bool                 $i18n    I18N Flag.
     *
     * @return string|null
     */
    public function keywordsFilter(array $keyword, bool $i18n)
    : string|null {
        if (!($i18n ^ $keyword["i18n"])) {
            assert(is_string($keyword["keyword"]));

            return $keyword["keyword"];
        }

        return null;
    }

    /**
     * Any Last Args?
     * Returns the last arguments needed for rendering the page.
     * This function is called after the controller init.
     * @return array<string, mixed>
     */
    public function anyLastArgs()
    : array
    {
        $template_args = array();
        // Errors handling:
        $template_args["results"] = $this->ErrorHandler->getResults();
        // I like to know how much all of this took, so I set an additional
        // parameter for the page rendering.
        $template_args["time"] = round(
            (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
            4
        );

        return $template_args;
    }
}
