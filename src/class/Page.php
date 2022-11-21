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
     * @var array<int, int> $ancestors Page ancestors.
     */
    public array $ancestors;
    // page robots
    /**
     * @var bool|null $index Page index flag.
     */
    public bool|null $index;
    /**
     * @var bool|null $follow Page follow flag.
     */
    public bool|null $follow;
    // page meta
    /**
     * @var string|null $title Page title.
     */
    public string|null $title;
    /**
     * @var string|null $description Page description.
     */
    public string|null $description;
    // page keywords
    /**
     * @var array<int, string> $keywords Page keywords.
     */
    public array $keywords;

    public function __construct(
        int $id,
        string $url_id,
        bool $i18n,
        string $file_name,
        bool $controller,
        bool $hidden,
        array $ancestors,
        bool|null $index = null,
        bool|null $follow = null,
        string|null $title = null,
        string|null $description = null,
        array|null $keywords = null
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
