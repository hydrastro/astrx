<?php
declare(strict_types=1);

namespace AstrX\Session;

/**
 * The default PRG namespace, used by every page controller that is not a
 * comment form. All behaviour lives in AbstractPrgHandler; this class only
 * names the session keys.
 */
final class PrgHandler extends AbstractPrgHandler
{
    private const string POST_PREFIX = 'POST_';
    /** Parallel per-token creation timestamp, so payloads can be aged out (R3-27). */
    private const string POST_META_PREFIX = 'POST_META_';
    private const string TARGET_PREFIX = 'PRG_TARGET_';
    private const string TOKEN_QUERY_KEY = '_prg';

    protected function postPrefix(): string     { return self::POST_PREFIX; }
    protected function postMetaPrefix(): string { return self::POST_META_PREFIX; }
    protected function targetPrefix(): string   { return self::TARGET_PREFIX; }

    public function tokenQueryKey(): string     { return self::TOKEN_QUERY_KEY; }
}
