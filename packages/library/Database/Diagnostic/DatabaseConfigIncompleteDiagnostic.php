<?php
declare(strict_types=1);

namespace AstrX\Database\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when resources/config/PDO.config.php does not declare every
 * credential the connection needs.
 *
 * It carries the missing KEY NAMES only. There is no accessor for the values
 * that were present, on purpose: this diagnostic exists precisely because a
 * connection was NOT attempted, and the thing an operator has to be told is
 * which line to add — never what the other lines currently say.
 *
 * The file is in .gitignore (credentials do not belong in the tree), so "the
 * section is absent entirely" is the normal state of a fresh checkout. That
 * used to resolve to a hardcoded 'user'/'password'/'localhost' guess; naming
 * the gap is the whole point.
 */
final class DatabaseConfigIncompleteDiagnostic extends AbstractDiagnostic
{
    /** @param list<string> $missingKeys */
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly array $missingKeys,
    ) {
        parent::__construct($id, $level);
    }

    /**
     * The required PDO config keys that are absent, in declaration order.
     *
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return $this->missingKeys;
    }
}
