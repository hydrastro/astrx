<?php

declare(strict_types = 1);
/**
 * Page class.
 */
class Page
{
    // page
    /**
     * @var int $id Page id.
     */
    public int $id;
    /**
     * @var string $url_id Page URL id.
     */
    public string $url_id;
    /**
     * @var bool $i18n Page i18n flag.
     */
    public bool $i18n;
    /**
     * @var string $file_name Page filename.
     */
    public string $file_name;
    /**
     * @var bool $controller Page controller flag.
     */
    public bool $controller;
    /**
     * @var bool $hidden Page hidden flag.
     */
    public bool $hidden;
    // page closure
    /**
     * @var array<int, array<string, mixed>> $ancestors Page ancestors.
     */
    public array $ancestors;
    // page robots
    /**
     * @var bool $index Page index flag.
     */
    public bool $index;
    /**
     * @var bool $follow Page follow flag.
     */
    public bool $follow;
    // page meta
    /**
     * @var string $title Page title.
     */
    public string $title;
    /**
     * @var string $description Page description.
     */
    public string $description;
    // page keywords
    /**
     * @var array<int, array<string, mixed>> $keywords Page keywords.
     */
    public array $keywords;

    /**
     * Page Constructor.
     *
     * @param int                              $id          Page id.
     * @param string                           $url_id      Page url id.
     * @param bool                             $i18n        Page
     *                                                      internationalization
     *                                                      flag.
     * @param string                           $file_name   Page file name.
     * @param bool                             $controller  Controller flag.
     * @param bool                             $hidden      Hidden flag.
     * @param array<int, array<string,mixed>>  $ancestors   Ancestors array.
     * @param bool                             $index       Index flag.
     * @param bool                             $follow      Follow flag.
     * @param string                           $title       Title.
     * @param string                           $description Description.
     * @param array<int, array<string, mixed>> $keywords    Keywords
     *                                                      array.
     */
    public function __construct(
        int $id,
        string $url_id,
        bool $i18n,
        string $file_name,
        bool $controller,
        bool $hidden,
        array $ancestors,
        bool $index = false,
        bool $follow = false,
        string $title = "",
        string $description = "",
        array $keywords = array()
    ) {
        $this->id = $id;
        $this->url_id = $url_id;
        $this->i18n = $i18n;
        $this->file_name = $file_name;
        $this->controller = $controller;
        $this->hidden = $hidden;
        $this->ancestors = $ancestors;
        $this->index = $index;
        $this->follow = $follow;
        $this->title = $title;
        $this->description = $description;
        $this->keywords = $keywords;
    }
}
