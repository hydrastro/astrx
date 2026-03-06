<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\ContentManager;
use AstrX\Result\Result;

final class ErrorController implements Controller
{
    public function __construct(private ContentManager $cm) {}

    public function handle(): Result
    {
        $status = http_response_code();
        $errorName = ucfirst((string)WORDING_ERROR) . " " . $status;
        $key = "WORDING_HTTP_STATUS_" . $status;

        $errorMessage = defined($key) ? (string)constant($key) : 'Error';

        $this->cm->template_args["title"] = $errorName . " - " . $errorMessage;
        $this->cm->template_args["description"] = $errorMessage;
        $this->cm->template_args["keywords"] = ucwords($errorName);
        $this->cm->template_args["error_name"] = $errorName;
        $this->cm->template_args["error_message"] = $errorMessage;

        return Result::ok(null);
    }
}