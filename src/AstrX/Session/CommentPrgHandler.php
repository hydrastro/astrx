<?php

declare(strict_types = 1);

namespace AstrX\Session;

/**
 * Dedicated PRG namespace for comment forms.
 *
 * Uses separate session keys (COMMENT_POST_ / COMMENT_TARGET_) and a separate
 * query key (_cp) so that comment form redirects are never consumed by other
 * page controllers that share the main _prg query key. This solves the bug
 * where pages with their own controller (e.g. UserController) call
 * PrgHandler::pull() on the shared _prg token before CommentController can
 * process it, silently dropping the comment submission.
 *
 * Behaviour — including the payload GC and __files__ upload scrubbing this
 * class used to lack entirely — now lives in AbstractPrgHandler. Before that,
 * COMMENT_POST_ entries were written and never swept: pruneTargets() only ever
 * touched COMMENT_TARGET_, so every abandoned comment submission stayed in the
 * session blob for the life of the session and grew it towards the MEDIUMBLOB
 * ceiling, taking any uploaded temp files with it.
 */
final class CommentPrgHandler extends AbstractPrgHandler
{
    private const string POST_PREFIX = 'COMMENT_POST_';
    /** MUST start with POST_PREFIX — see AbstractPrgHandler::postMetaPrefix(). */
    private const string POST_META_PREFIX = 'COMMENT_POST_META_';
    private const string TARGET_PREFIX = 'COMMENT_TARGET_';

    public const string QUERY_KEY = '_cp';

    protected function postPrefix(): string     { return self::POST_PREFIX; }
    protected function postMetaPrefix(): string { return self::POST_META_PREFIX; }
    protected function targetPrefix(): string   { return self::TARGET_PREFIX; }

    public function tokenQueryKey(): string     { return self::QUERY_KEY; }
}
