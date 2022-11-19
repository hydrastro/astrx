<?php

declare(strict_types = 1);
/**
 * Page class.
 */
class Page
{
    /**
     * @var string $id Page id.
     */
    public string $id;
    /**
     * @var string $file_name Page filename.
     */
    public string $file_name;
    /**
     * @var array $ancestors Page ancestors.
     */
    public array $ancestors;
    /**
     * @var string $title Page title.
     */
    public string $title;
    /**
     * @var string $description Page description.
     */
    public string $description;
    /**
     * @var array<int, string> $keywords Page keywords.
     */
    public array $keywords;
    /**
     * @var bool $index Page index flag.
     */
    public bool $index;
    /**
     * @var bool $follow Page follow flag.
     */
    public bool $follow;
    /**
     * @var bool $controller Page controller flag.
     */
    public bool $controller;
    /**
     * @var bool $hidden Page hidden flag.
     */
    public bool $hidden;

    /**
     * Page constructor.
     *
     * @param string             $id          Page id.
     * @param string             $file_name   Page filename.
     * @param string             $title       Page title.
     * @param string             $description Page description
     * @param array<int, string> $keywords    Page keywords.
     * @param bool               $index       Page index flag.
     * @param bool               $follow      Page follow flag.
     * @param bool               $controller  Page controller flag.
     * @param bool               $hidden      Page hidden flag.
     */
    public function __construct(
        string $id,
        string $file_name,
        string $title,
        string $description,
        array $keywords,
        bool $index,
        bool $follow,
        bool $controller,
        bool $hidden,
    ) {
        $this->id = $id;
        $this->file_name = $file_name;
        $this->title = $title;
        $this->description = $description;
        $this->keywords = $keywords;
        $this->index = $index;
        $this->follow = $follow;
        $this->controller = $controller;
        $this->hidden = $hidden;
    }
}
