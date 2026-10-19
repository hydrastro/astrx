<?php
declare(strict_types=1);

namespace AstrX\Retention;

/**
 * One table the retention engine shreds.
 *
 *   'age'    — rows older than the operator's window (days, in site_config
 *              retention_days_<key>) are deleted by $column;
 *   'expiry' — rows whose $column (an expiry time) has passed are deleted.
 *
 * $table and $column are interpolated into SQL as identifiers, so they must
 * be constants of the module that declares them — never input.
 */
final class RetentionTarget
{
    public const string AGE    = 'age';
    public const string EXPIRY = 'expiry';

    public function __construct(
        public readonly string $key,
        public readonly string $table,
        public readonly string $column,
        public readonly string $mode,
    ) {
        foreach ([$key, $table, $column] as $identifier) {
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $identifier) !== 1) {
                throw new \InvalidArgumentException("Not a plain identifier: '{$identifier}'");
            }
        }
        if ($mode !== self::AGE && $mode !== self::EXPIRY) {
            throw new \InvalidArgumentException("Unknown retention mode '{$mode}'");
        }
    }
}
