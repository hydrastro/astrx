<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\I18n\Translator;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;

final class MainController implements Controller
{
    public function __construct(
        private readonly DefaultTemplateContext $ctx,
        private readonly Translator $t,
    ) {}

    public function handle(): Result
    {
        $this->ctx->set('main_page', ucwords($this->t->t('main_page', fallback: 'Main Page')));

        // TODO: replace with real page content once data layer is wired
        $this->ctx->set('content', 'test');

        return Result::ok(null);
    }
}
