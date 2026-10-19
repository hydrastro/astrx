<?php

declare(strict_types = 1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a config key read as a flag holds something that is not a
 * boolean and not one of the unambiguous spellings of one ('true'/'false',
 * 'yes'/'no', 'on'/'off', 0/1).
 *
 * Config::getConfigBool() used to answer such a value with a plain `(bool)`
 * cast, which makes every non-empty string TRUE — so `'chat' => 'false'` in
 * Modules.config.php read back as "chat is enabled", and the operator who wrote
 * it had no way to find out. The value is now rejected, the caller's default
 * stands, and this says which key needs fixing.
 */
final class ConfigNotABoolDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $domain,
        private readonly string $key,
        /** The value's type as get_debug_type() reports it — never the value. */
        private readonly string $actual,
    ) {
        parent::__construct($id, $level);
    }

    public function domain(): string { return $this->domain; }

    public function key(): string { return $this->key; }

    public function actual(): string { return $this->actual; }
}
