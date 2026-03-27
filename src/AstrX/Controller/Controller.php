<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Result\Result;

interface Controller
{
    /** @return Result<mixed> */
    public function handle(): Result;
}
