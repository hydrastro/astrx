<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Admin\BanlistRepository;

/**
 * The shared chat "kick penalty" — a short ROUTE_CHAT entry ban (by nick + IP)
 * that keeps a removed participant out for `kick_penalty_minutes`.
 *
 * A kick is just a ban with a short expiry, so both paths that remove someone —
 * a moderator's kick and the automatic word/link filter kick — enforce through
 * this ONE place and the ONE banlist, rather than each writing its own rows.
 * Chat entry re-checks the banlist (`findActiveBanForIp`/`findActiveBanForNick`),
 * so the penalty is what actually keeps them out until it expires; NULL end
 * (a full ban) is the same mechanism with no expiry.
 *
 * No-op when the penalty is disabled (0). Best-effort: a failed ban never
 * propagates — the caller has already dropped the participant from the roster.
 */
final class ChatKickPenalty
{
    public function __construct(
        private readonly BanlistRepository $banlist,
        private readonly ChatConfig        $config,
    ) {}

    public function apply(string $nick, string $ipStr, string $reason): void
    {
        $mins = $this->config->kickPenaltyMinutes();
        if ($mins <= 0) {
            return;
        }
        $end   = date('Y-m-d H:i:s', time() + $mins * 60);
        $route = BanlistRepository::ROUTE_CHAT;
        if ($nick !== '') {
            $this->banlist->banNick($nick, $reason, $route, $end);
        }
        if ($ipStr !== '') {
            $this->banlist->banCidr($ipStr, $reason, $route, $end);
        }
    }
}
