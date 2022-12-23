<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Main Controller.
 */
class MainController
{
    /**
     * @var ContentManager $ContentManager Content Manager.
     */
    private ContentManager $ContentManager;

    /**
     * Main Controller Constructor.
     *
     * @param ContentManager $ContentManager Content Manager.
     */
    public function __construct(ContentManager $ContentManager)
    {
        $this->ContentManager = $ContentManager;
    }

    /**
     * Init.
     * Does "controller" stuff. Handles input requests and sets up things for
     * the response.
     * @return void
     */
    public function init()
    : void
    {
        $this->ContentManager->template_args["main_page"] = ucwords(
            WORDING_MAIN_PAGE
        );
        $this->ContentManager->template_args["content"] = print_r($_GET, true);
    }
}
