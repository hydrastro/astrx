<?php

/**
 * Class Error Controller.
 */
class ErrorController
{
    private ContentManager $ContentManager;

    public function __construct(ContentManager $ContentManager)
    {
        $this->ContentManager = $ContentManager;
    }

    public function init()
    : void
    {
        $status_code = http_response_code();
        $error_name = ucfirst(WORDING_ERROR) . " " . $status_code;
        $error_message = constant(
            "WORDING_HTTP_STATUS_" . $status_code
        );
        $this->ContentManager->template_args["title"] = $error_name;
        $this->ContentManager->template_args["description"] = $error_message;
        $this->ContentManager->template_args["keywords"] = array();
        $this->ContentManager->template_args["error_name"] = $error_name;
        $this->ContentManager->template_args["error_message"] = $error_message;
    }
}
