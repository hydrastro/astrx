<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\Diagnostic\ChatGateDeniedDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * Managed word + link filters — the ENFORCEMENT layer, distinct from the
 * cosmetic WordCensor.
 *
 * Where the censor stars-out or blocks matched words globally, each filter here
 * is a literal fragment that, on a hit, takes a moderation action:
 *   - kind  0 word → matched anywhere in the message; 1 link → matched only
 *           inside a detected http(s) URL (so "bit.ly" in prose won't fire a
 *           link rule, only an actual link will).
 *   - action 0 block → the post is rejected; 1 kick → the poster is removed.
 *   - apply_to_mods → staff (anyone with CHAT_MODERATE) are exempt unless set.
 *
 * CRUD is gated ADMIN_CONFIG_CHAT. Matching (`match`) is pure and ungated — it
 * runs for every poster inside ChatService::post(). Matching fails OPEN: if the
 * filter list cannot be read, posting is never blocked by a broken table.
 */
final class ChatFilterService
{
    public const KIND_WORD    = 0;
    public const KIND_LINK    = 1;
    public const KIND_NICK    = 2;  // matched against the chosen nick at entry, not on messages
    public const ACTION_BLOCK = 0;
    public const ACTION_KICK  = 1;

    public function __construct(
        private readonly ChatFilterRepository $repo,
        private readonly Gate                 $gate,
    ) {}

    /**
     * All configured filters (admin listing).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function all(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CHAT)) {
            return $this->denied();
        }
        return $this->repo->all();
    }

    /** @return Result<int> new filter id */
    public function add(string $pattern, int $kind, int $action, bool $applyToMods): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CHAT)) {
            return $this->denied();
        }
        $pattern = trim($pattern);
        if ($pattern === '') {
            return $this->denied();
        }
        $kind   = in_array($kind, [self::KIND_WORD, self::KIND_LINK, self::KIND_NICK], true) ? $kind : self::KIND_WORD;
        $action = $action === self::ACTION_KICK ? self::ACTION_KICK : self::ACTION_BLOCK;
        return $this->repo->add(mb_substr($pattern, 0, 255), $kind, $action, $applyToMods);
    }

    /** @return Result<bool> */
    public function remove(int $id): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CHAT)) {
            return $this->denied();
        }
        if ($id <= 0) {
            return Result::ok(false);
        }
        return $this->repo->delete($id);
    }

    /**
     * The first filter that matches $content, honouring apply_to_mods for staff.
     * Pure, ungated, fail-open. Returns the matched filter row or null.
     *
     * @return array<string,mixed>|null
     */
    public function match(string $content, bool $posterIsStaff): ?array
    {
        $r = $this->repo->all();
        if (!$r->isOk()) {
            return null; // fail open — a broken filter table must not stop the chat
        }

        /** @var list<string>|null $urls lazily extracted on first link rule */
        $urls = null;

        foreach ($r->unwrap() as $f) {
            $applyToMods = self::toInt($f['apply_to_mods'] ?? 0) === 1;
            if ($posterIsStaff && !$applyToMods) {
                continue;
            }
            $pattern = is_scalar($f['pattern'] ?? null) ? (string) $f['pattern'] : '';
            if ($pattern === '') {
                continue;
            }
            $kind = self::toInt($f['kind'] ?? 0);
            if ($kind === self::KIND_NICK) {
                continue; // nick filters are checked at entry (nickBlocked), not on messages
            }

            if ($kind === self::KIND_LINK) {
                if ($urls === null) {
                    $urls = $this->extractUrls($content);
                }
                foreach ($urls as $u) {
                    if (mb_stripos($u, $pattern) !== false) {
                        return $f;
                    }
                }
                continue;
            }

            if (mb_stripos($content, $pattern) !== false) {
                return $f;
            }
        }
        return null;
    }

    /**
     * The first NICK filter that matches $nick (case-insensitive substring), or
     * null. Checked at chat entry (not post time) so a blocked nick can't join.
     * Pure, ungated, fail-open.
     *
     * @return array<string,mixed>|null
     */
    public function nickBlocked(string $nick): ?array
    {
        $r = $this->repo->all();
        if (!$r->isOk()) {
            return null;
        }
        foreach ($r->unwrap() as $f) {
            if (self::toInt($f['kind'] ?? 0) !== self::KIND_NICK) {
                continue;
            }
            $pattern = is_scalar($f['pattern'] ?? null) ? (string) $f['pattern'] : '';
            if ($pattern !== '' && mb_stripos($nick, $pattern) !== false) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Bare http(s) URLs in $text (same shape BbcodeRenderer links).
     *
     * @return list<string>
     */
    private function extractUrls(string $text): array
    {
        if (preg_match_all('~\bhttps?://[^\s\[\]<>"\']+~i', $text, $m) >= 1) {
            /** @var list<string> $found */
            $found = $m[0];
            return $found;
        }
        return [];
    }

    /** Safe mixed → int (never casts a non-numeric value). */
    private static function toInt(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }

    /** @return Result<never> */
    private function denied(): Result
    {
        return Result::err(null, Diagnostics::of(new ChatGateDeniedDiagnostic(
            'astrx.chat/gate_denied', DiagnosticLevel::WARNING
        )));
    }
}
