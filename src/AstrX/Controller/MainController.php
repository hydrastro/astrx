<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\ContentManager;
use AstrX\Result\Result;

final class MainController implements Controller
{
    public function __construct(private ContentManager $cm) {}

    public function handle(): Result
    {
        // Example content: dump current request params (whatever your Request exposes)
        $this->cm->templateArgs['main_page'] = ucwords((string)($this->cm->t('WORDING_MAIN_PAGE')));
        $this->cm->templateArgs['content'] = print_r($_GET, true);

        return Result::ok(false);
    }
}