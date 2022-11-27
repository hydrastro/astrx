<?php

/**
 * Class Error Controller.
 */
class ErrorController
{
    /**
     * @var TemplateEngine $TemplateEngine Template Engine.
     */
    private TemplateEngine $TemplateEngine;
    /**
     * @var Response $response Response.
     */
    private Response $response;
    /**
     * @var Page $current_page Current Page.
     */
    private Page $current_page;

    public function __construct(
        TemplateEngine $TemplateEngine,
        Response $response,
        Page $current_page
    ) {
        $this->TemplateEngine = $TemplateEngine;
        $this->response = $response;
        $this->current_page = $current_page;
    }

    public function init()
    : void
    {
        $status_code = http_response_code();
        $error_name = ucfirst(WORDING_ERROR) . " " . $status_code;
        $error_message = constant(
            "WORDING_HTTP_STATUS_" . $status_code
        );
        $template_args = array();
        $template_args["content"] = $this->current_page->file_name;
        $template_args["title"] = $error_name;
        $template_args["description"] = $error_message;
        // TODO: i have no idea what to put in this page keywords.
        $template_args["keywords"] = ucwords($error_name);
        $template_args["error_name"] = $error_name;
        $template_args["error_message"] = $error_message;
        $template_args["time"] = round(
            (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
            4
        );

        //template_args["results"] = $this->ErrorHandler->getResults();
        $template = $this->TemplateEngine->loadTemplate("template");
        assert(is_object($template));
        assert(method_exists($template, "render"));
        // Setting and sending the rendered page.
        $this->response->setContent($template->render($template_args));
        $this->response->send();
        exit();
    }
}
