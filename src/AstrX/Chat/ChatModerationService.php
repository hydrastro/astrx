<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Admin\BanlistRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\Diagnostic\ChatGateDeniedDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Moderation actions on a chat identity — all gated by CHAT_MODERATE.
 *
 * kick  → flips the target's presence to KICKED (they drop from the roster and
 *         are bounced to the entry page on their next request; they may return
 *         unless also banned).
 * mute  → a timed mute (reuses the shared `mute` table via ChatRepository), by
 *         user id for members and by IP for guests.
 * ban   → a timed entry into the shared banlist: the nick, the IP, and (for a
 *         member) the account — then kicked so it takes effect immediately.
 */
final class ChatModerationService
{
    public function __construct(
        private readonly Gate                $gate,
        private readonly ChatPresenceService $presence,
        private readonly BanlistRepository   $banlist,
        private readonly ChatRepository      $chat,
        private readonly ChatService         $announce,
        private readonly ChatKickPenalty     $kickPenalty,
        private readonly UserSession         $session,
    ) {}

    /**
     * Kick a participant. When a kick penalty is configured, they are also
     * temporarily banned (by nick + IP) so they cannot immediately rejoin;
     * $penaltyReason is the caller-localised reason recorded on that ban.
     *
     * @return Result<bool>
     */
    public function kick(string $ident, string $penaltyReason = ''): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $p    = $this->presenceRow($ident);
        // Rank guard: a MOD may not act on a peer MOD or an ADMIN (only an ADMIN
        // may act on an equal-or-higher rank). Mirrors ChatPolicy CHAT_DELETE_ANY.
        if ($p !== null && $this->outranksActor($p)) {
            return $this->denied();
        }
        $nick = $p !== null && is_scalar($p['nick'] ?? null) ? (string) $p['nick'] : '';

        $result = $this->presence->kick($ident);
        if ($result->isOk() && $result->unwrap() === true) {
            $this->announce->postModAction($nick, 'kicked');
            if ($p !== null) {
                $this->applyKickPenalty($p, $penaltyReason);
            }
        }
        return $result;
    }

    /** @return Result<bool> */
    public function mute(string $ident, int $secs): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $p = $this->presenceRow($ident);
        if ($p === null) {
            return Result::ok(false);
        }
        if ($this->outranksActor($p)) {
            return $this->denied();
        }
        [$hexUserId, $packedIp] = $this->identityBits($p);
        // Mute keys on the account (members) or the per-visitor chat ident
        // (guests) — never the shared Tor exit IP, which would mute every guest
        // and, post R3-16, would no longer match the guest's own post-time check.
        $muteKey = $hexUserId !== null ? null : (ChatIdentity::guestRateKey($ident) ?? $packedIp);
        return $this->chat->addMute($hexUserId, $muteKey, max(1, $secs));
    }

    /** @return Result<bool> */
    public function ban(string $ident, int $durationSecs, string $reason): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $p = $this->presenceRow($ident);
        if ($p === null) {
            return Result::ok(false);
        }
        if ($this->outranksActor($p)) {
            return $this->denied();
        }

        $nick      = is_scalar($p['nick']   ?? null) ? (string) $p['nick']   : '';
        $ipStr     = is_scalar($p['ip_str'] ?? null) ? (string) $p['ip_str'] : '';
        $isMember  = $this->isMemberFlag($p);
        $hexUserId = $isMember && is_scalar($p['user_id'] ?? null) ? (string) $p['user_id'] : '';
        $end       = $durationSecs > 0 ? date('Y-m-d H:i:s', time() + $durationSecs) : null;
        $route     = BanlistRepository::ROUTE_CHAT;

        if ($nick !== '') {
            $r = $this->banlist->banNick($nick, $reason, $route, $end);
            if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        }
        if ($ipStr !== '') {
            $r = $this->banlist->banCidr($ipStr, $reason, $route, $end);
            if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        }
        if ($hexUserId !== '') {
            $r = $this->banlist->banUser($hexUserId, $reason, $route, $end);
            if (!$r->isOk()) { return Result::err(false, $r->diagnostics()); }
        }

        $this->presence->kick($ident);
        $this->announce->postModAction($nick, 'banned');
        return Result::ok(true);
    }

    /**
     * Purge — kick the target AND delete every message they posted (by nick).
     * The le-chat "kick and clean" in one action.
     *
     * @return Result<bool>
     */
    public function purge(string $ident): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $p = $this->presenceRow($ident);
        if ($p === null) {
            return Result::ok(false);
        }
        if ($this->outranksActor($p)) {
            return $this->denied();
        }
        $nick = is_scalar($p['nick'] ?? null) ? (string) $p['nick'] : '';

        $this->presence->kick($ident);
        if ($nick !== '') {
            $del = $this->chat->deleteByNick($nick);
            if (!$del->isOk()) {
                return Result::err(false, $del->diagnostics());
            }
        }
        $this->announce->postModAction($nick, 'purged');
        return Result::ok(true);
    }

    /**
     * Admit a guest awaiting approval (moderator-approval mode): flip them ACTIVE
     * and announce the join.
     *
     * @return Result<bool>
     */
    public function approve(string $ident): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $p    = $this->presenceRow($ident);
        $nick = $p !== null && is_scalar($p['nick'] ?? null) ? (string) $p['nick'] : '';
        $r    = $this->presence->setActive($ident);
        if ($r->isOk() && $r->unwrap() === true && $nick !== '') {
            $this->announce->postSystem($nick, 'join');
        }
        return $r;
    }

    /**
     * Refuse a guest awaiting approval — drop them from the roster; they land
     * back on the entry page on their next request.
     *
     * @return Result<bool>
     */
    public function deny(string $ident): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        return $this->presence->leave($ident);
    }

    /**
     * Drop every participant who has gone idle beyond the online window.
     *
     * @return Result<int> sessions removed
     */
    public function logoutInactive(): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        return $this->presence->logoutInactive();
    }

    /**
     * Kick every guest at once (le-chat "All guests") — a bulk status flip, so it
     * applies no per-guest penalty ban or announcement.
     *
     * @return Result<int> guests kicked
     */
    public function kickAllGuests(): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        return $this->presence->kickGuests();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function presenceRow(string $ident): ?array
    {
        $r = $this->presence->presence($ident);
        return $r->isOk() ? $r->unwrap() : null;
    }

    /**
     * Rank guard — refuse when the TARGET's chat rank is >= the ACTOR's own,
     * unless the actor is an ADMIN. The CHAT_MODERATE analogue of the
     * CHAT_DELETE_ANY rule in {@see \AstrX\Auth\Policy\ChatPolicy}: it stops a MOD
     * kicking/muting/banning/purging a peer MOD or an ADMIN. The target's rank
     * comes from the presence row's `role` (the UserGroup value stored at join,
     * resolved exactly as ChatPolicy resolves its author type); the actor's from
     * the live session.
     *
     * @param array<string,mixed> $p presence row
     */
    private function outranksActor(array $p): bool
    {
        $roleRaw    = $p['role'] ?? UserGroup::GUEST->value;
        $targetType = UserGroup::tryFrom(
            is_int($roleRaw) ? $roleRaw : (is_numeric($roleRaw) ? (int) $roleRaw : UserGroup::GUEST->value)
        ) ?? UserGroup::GUEST;

        $actor = $this->session->userType();
        return $targetType->rank() >= $actor->rank() && $actor !== UserGroup::ADMIN;
    }

    /**
     * Apply the configured kick penalty — a short entry ban (by nick + IP) that
     * keeps the target out for `kick_penalty_minutes`. No-op when the penalty is
     * disabled (0). Best-effort: a failed ban never fails the kick itself.
     *
     * @param array<string,mixed> $p presence row
     */
    private function applyKickPenalty(array $p, string $reason): void
    {
        $nick  = is_scalar($p['nick']   ?? null) ? (string) $p['nick']   : '';
        $ipStr = is_scalar($p['ip_str'] ?? null) ? (string) $p['ip_str'] : '';
        $this->kickPenalty->apply($nick, $ipStr, $reason);
    }

    /**
     * @param array<string,mixed> $p
     * @return array{0: ?string, 1: ?string} [hexUserId, packedIp]
     */
    private function identityBits(array $p): array
    {
        $isMember  = $this->isMemberFlag($p);
        $hexUserId = $isMember && is_scalar($p['user_id'] ?? null) ? (string) $p['user_id'] : null;

        $ipStr    = is_scalar($p['ip_str'] ?? null) ? (string) $p['ip_str'] : '';
        $packedIp = null;
        if ($ipStr !== '') {
            $packed   = @inet_pton($ipStr);
            $packedIp = $packed !== false ? $packed : null;
        }
        return [$hexUserId, $packedIp];
    }

    /** @param array<string,mixed> $p */
    private function isMemberFlag(array $p): bool
    {
        $raw = $p['is_member'] ?? 0;
        $val = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 0);
        return $val === 1;
    }

    /** @return Result<never> */
    private function denied(): Result
    {
        return Result::err(null, Diagnostics::of(new ChatGateDeniedDiagnostic(
            'astrx.chat/gate_denied', DiagnosticLevel::WARNING
        )));
    }
}
