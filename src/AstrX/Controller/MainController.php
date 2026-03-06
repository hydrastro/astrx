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
        $this->cm->template_args['main_page'] = ucwords((string)WORDING_MAIN_PAGE);
        $this->cm->template_args['content'] = print_r($_GET, true);

        return Result::ok(null);
    }
}