<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\ContentManager;
use AstrX\Result\Result;

final class ErrorController implements Controller
{
    public function __construct(private ContentManager $cm)
    {
    }

    public function handle()
    : Result
    {
        $status = http_response_code();
        $errorName = ucfirst((string)$this->cm->t('WORDING_ERROR')) .
                     ' ' .
                     $status;

        $key = 'WORDING_HTTP_STATUS_' . $status;
        $errorMessage = (string)$this->cm->t($key, fallback: $key);

        $this->cm->templateArgs['title'] = $errorName . ' - ' . $errorMessage;
        $this->cm->templateArgs['description'] = $errorMessage;
        $this->cm->templateArgs['keywords'] = ucwords($errorName);
        $this->cm->templateArgs['error_name'] = $errorName;
        $this->cm->templateArgs['error_message'] = $errorMessage;

        return Result::ok(false);
    }
}