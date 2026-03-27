<?php
declare(strict_types=1);
namespace AstrX\Injector;

final class RegisteredHelper
{
    public function __construct(
        public readonly string $className,
        public readonly object $instance,
        public readonly string $method,
    ) {}
}
