<?php

declare(strict_types = 1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted for each config key that was loaded from a config file
 * but never consumed by an #[InjectConfig] setter or getConfig() call.
 * This usually means a typo in a config key, or a removed feature
 * whose config was not cleaned up.
 */
final class ConfigKeyUnusedDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $domain,
        public readonly string $key,
    ) {
        parent::__construct($id, $level);
    }

    public function vars()
    : array
    {
        return ['domain' => $this->domain, 'key' => $this->key];
    }
}