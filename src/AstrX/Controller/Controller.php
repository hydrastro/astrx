<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Result\Result;

interface Controller
{
    /**
     * Execute controller logic.
     * - MUST NOT echo/exit.
     * - SHOULD mutate ContentManager template args via injected ContentManager.
     * - MAY return Result::err(...) for fatal failure.
     *
     * @return Result<null>  (use ok(null) or err($error))
     */
    public function handle(): Result;
}