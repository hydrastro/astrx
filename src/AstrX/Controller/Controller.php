<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Result\Result;

interface Controller
{
    /**
     * Controller may:
     * - mutate ContentManager template args
     * - send a response itself (API/avatar) and return Result::ok(true)
     * - or return ok(false) meaning "continue default rendering"
     *
     * @return Result<bool> true => response already sent; false => continue rendering
     */
    public function handle(): Result;
}