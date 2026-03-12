<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\I18n\Translator;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;

final class ErrorController implements Controller
{
    public function __construct(
        private readonly DefaultTemplateContext $ctx,
        private readonly Translator $t,
    ) {}

    public function handle(): Result
    {
        $status    = (int) http_response_code();
        $errorWord = ucfirst($this->t->t('error', fallback: 'Error'));
        $errorName = $errorWord . ' ' . $status;
        $errorMsg  = $this->t->t('http.status.' . $status, fallback: 'An error occurred.');

        $this->ctx->set('title',         $errorName . ' — ' . $errorMsg);
        $this->ctx->set('description',   $errorMsg);
        $this->ctx->set('keywords',      ucwords($errorName));
        $this->ctx->set('error_name',    $errorName);
        $this->ctx->set('error_message', $errorMsg);

        return Result::ok(null);
    }
}
