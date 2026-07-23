<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Result\Result;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Owns chat identity and the live roster.
 *
 * A member's identity comes straight from their account session; a guest's is
 * a random token plus a chosen nick/colour kept in the PHP session. Presence
 * rows track status (waiting/active/kicked) and a heartbeat (last_seen) that
 * every frame refresh bumps — that heartbeat is what "online" and the
 * waiting-room countdown are both derived from.
 */
final class ChatPresenceService
{
    public const STATUS_WAITING = 0;
    public const STATUS_ACTIVE  = 1;
    public const STATUS_KICKED  = 2;
    public const STATUS_PENDING = 3;   // awaiting moderator approval (moderator-approval mode)

    private const SES_TOKEN  = 'astrx_chat_token';
    private const SES_NICK   = 'astrx_chat_nick';
    private const SES_COLOR  = 'astrx_chat_color';
    private const SES_JOINED  = 'astrx_chat_joined_ts';
    private const SES_IGNORE  = 'astrx_chat_ignore';
    private const SES_LAYOUT  = 'astrx_chat_layout_alt';
    private const SES_POSTBOX = 'astrx_chat_postbox_multiline';

    public function __construct(
        private readonly ChatPresenceRepository $repo,
        private readonly UserSession            $session,
        private readonly ChatConfig             $config,
    ) {}

    // ── Identity ─────────────────────────────────────────────────────────────

    /** Current participant's identity, or null if a guest has not chosen a nick. */
    public function identity(): ?ChatIdentity
    {
        if ($this->session->isLoggedIn()) {
            $hex  = $this->session->userId();
            $nick = $this->session->displayName();
            if ($nick === '') {
                $nick = $this->session->username();
            }
            if ($nick === '') {
                $nick = 'user';
            }
            return new ChatIdentity($hex, true, $hex, $nick, null, $this->session->userType()->value);
        }

        $token = $this->guestToken();
        $nick  = $this->guestNick();
        if ($token === '' || $nick === '') {
            return null;
        }
        return new ChatIdentity($token, false, null, $nick, $this->guestColor(), UserGroup::GUEST->value);
    }

    public function currentIdent(): string
    {
        return $this->session->isLoggedIn() ? $this->session->userId() : $this->guestToken();
    }

    // ── Guest session profile ────────────────────────────────────────────────

    public function guestToken(): string
    {
        $v = $_SESSION[self::SES_TOKEN] ?? '';
        return is_string($v) ? $v : '';
    }

    public function ensureGuestToken(): string
    {
        $t = $this->guestToken();
        if ($t === '') {
            $t = bin2hex(random_bytes(16));
            $_SESSION[self::SES_TOKEN] = $t;
        }
        return $t;
    }

    public function guestNick(): string
    {
        $v = $_SESSION[self::SES_NICK] ?? '';
        return is_string($v) ? $v : '';
    }

    public function guestColor(): ?string
    {
        $v = $_SESSION[self::SES_COLOR] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function setGuestProfile(string $nick, ?string $color): void
    {
        $_SESSION[self::SES_NICK] = $nick;
        if ($color !== null && $color !== '') {
            $_SESSION[self::SES_COLOR] = $color;
        } else {
            unset($_SESSION[self::SES_COLOR]);
        }
    }

    public function clearGuest(): void
    {
        unset($_SESSION[self::SES_TOKEN], $_SESSION[self::SES_NICK],
              $_SESSION[self::SES_COLOR], $_SESSION[self::SES_JOINED]);
    }

    // ── Join / status ────────────────────────────────────────────────────────

    /** @return Result<bool> */
    public function join(ChatIdentity $id, int $status, ?string $packedIp): Result
    {
        $_SESSION[self::SES_JOINED] = time();
        return $this->repo->upsert(
            $id->ident, $id->isMember, $id->userId, $id->nick, $id->color, $id->role, $status, $packedIp
        );
    }

    public function secondsSinceJoin(): int
    {
        $v  = $_SESSION[self::SES_JOINED] ?? 0;
        $ts = is_int($v) ? $v : 0;
        return $ts > 0 ? max(0, time() - $ts) : 0;
    }

    /** @return Result<bool> */
    public function heartbeat(string $ident): Result { return $this->repo->touch($ident); }
    /** @return Result<bool> */
    public function setActive(string $ident): Result { return $this->repo->setStatus($ident, self::STATUS_ACTIVE); }
    /** @return Result<bool> */
    public function kick(string $ident): Result { return $this->repo->setStatus($ident, self::STATUS_KICKED); }
    /** @return Result<bool> */
    public function leave(string $ident): Result { return $this->repo->remove($ident); }

    /** @return Result<array<string,mixed>|null> */
    public function presence(string $ident): Result { return $this->repo->find($ident); }

    // ── Roster ───────────────────────────────────────────────────────────────

    /**
     * @param bool $includeHidden staff pass true to also see incognito users
     * @return Result<list<array<string,mixed>>>
     */
    public function onlineUsers(bool $includeHidden = false): Result
    {
        $this->repo->gcStale($this->staleCutoff());
        return $this->repo->online($this->onlineCutoff(), $includeHidden);
    }

    /** @return Result<int> */
    public function countOnline(): Result
    {
        return $this->repo->countOnline($this->onlineCutoff());
    }

    /**
     * Guests awaiting moderator approval, oldest request first. Recency-filtered
     * like the roster, so a guest who closes their tab drops off the queue.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function pending(): Result
    {
        return $this->repo->pending($this->onlineCutoff());
    }

    /** @return Result<list<array<string,mixed>>> every live session (moderator view) */
    public function allSessions(): Result
    {
        return $this->repo->allSessions($this->onlineCutoff());
    }

    /** @return Result<int> remove presences idle beyond the online window */
    public function logoutInactive(): Result
    {
        return $this->repo->logoutInactive($this->onlineCutoff());
    }

    /** @return Result<int> flip all guest presences to KICKED */
    public function kickGuests(): Result
    {
        return $this->repo->kickGuests();
    }

    /** True when at least one moderator/admin is currently active in the room. */
    public function anyModOnline(): bool
    {
        $r = $this->onlineUsers(true);
        if (!$r->isOk()) {
            return false;
        }
        foreach ($r->unwrap() as $u) {
            $roleVal = is_numeric($u['role'] ?? null) ? (int) $u['role'] : UserGroup::GUEST->value;
            $group   = UserGroup::tryFrom($roleVal);
            // Privilege is rank()-ordered, NOT the raw enum value.
            if ($group !== null && $group->rank() >= UserGroup::MOD->rank()) {
                return true;
            }
        }
        return false;
    }

    /** @return Result<array<string,mixed>|null> */
    public function findByNick(string $nick): Result
    {
        return $this->repo->findByNick($nick, $this->onlineCutoff());
    }

    /** True when $nick is not held by another live presence. */
    public function nickAvailableInRoster(string $nick, string $exceptIdent): bool
    {
        $r = $this->repo->nickTaken($nick, $exceptIdent, $this->onlineCutoff());
        return $r->isOk() ? ($r->unwrap() === false) : true;
    }

    public function waitingRoomSeconds(): int { return $this->config->waitingRoomSeconds(); }

    // ── Ignore list (per session; hides a nick's messages for this viewer) ─────

    /** @return list<string> lowercased nicks the current viewer has ignored */
    public function ignoredNicks(): array
    {
        $v = $_SESSION[self::SES_IGNORE] ?? [];
        if (!is_array($v)) { return []; }
        $out = [];
        foreach ($v as $n) {
            if (is_string($n) && $n !== '') { $out[] = $n; }
        }
        return $out;
    }

    public function isIgnored(string $nick): bool
    {
        return in_array(strtolower(trim($nick)), $this->ignoredNicks(), true);
    }

    /** Toggle a nick on/off the viewer's ignore list. No-op for an empty nick. */
    public function toggleIgnore(string $nick): void
    {
        $n = strtolower(trim($nick));
        if ($n === '') { return; }
        $cur = $this->ignoredNicks();
        $key = array_search($n, $cur, true);
        if ($key !== false) {
            unset($cur[$key]);
        } else {
            $cur[] = $n;
        }
        $_SESSION[self::SES_IGNORE] = array_values($cur);
    }

    // ── Layout preference (per session; swaps the pane arrangement, no-JS) ─────

    /** True when the viewer has toggled the alternate (mirrored) pane layout. */
    public function layoutAlt(): bool
    {
        return !empty($_SESSION[self::SES_LAYOUT]);
    }

    /** Flip the alternate-layout preference for this session. */
    public function toggleLayout(): void
    {
        $_SESSION[self::SES_LAYOUT] = $this->layoutAlt() ? 0 : 1;
    }

    /**
     * Post box mode: false = single-line input (Enter submits), true = multiline
     * textarea. Per session, defaulting to single-line like le-chat.
     */
    public function postboxMultiline(): bool
    {
        return !empty($_SESSION[self::SES_POSTBOX]);
    }

    /** Flip the post box between single-line and multiline for this session. */
    public function togglePostbox(): void
    {
        $_SESSION[self::SES_POSTBOX] = $this->postboxMultiline() ? 0 : 1;
    }

    private function onlineCutoff(): string
    {
        return date('Y-m-d H:i:s', time() - $this->config->onlineWindowSecs());
    }

    private function staleCutoff(): string
    {
        return date('Y-m-d H:i:s', time() - max(3600, $this->config->onlineWindowSecs() * 4));
    }
}
