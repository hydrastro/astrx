<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\Diagnostic\ChatCensoredDiagnostic;
use AstrX\Chat\Diagnostic\ChatEmptyDiagnostic;
use AstrX\Chat\Diagnostic\ChatGateDeniedDiagnostic;
use AstrX\Chat\Diagnostic\ChatPmTargetDiagnostic;
use AstrX\Chat\Diagnostic\ChatTooLongDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * Private messages: send to a nick that is currently in the roster, and read
 * the conversation involving an identity. Content passes the same censor and
 * BBCode rendering as public messages; retention mirrors the config.
 */
final class ChatPmService
{
    public function __construct(
        private readonly ChatPmRepository    $repo,
        private readonly ChatPresenceService $presence,
        private readonly Gate                $gate,
        private readonly BbcodeRenderer      $bbcode,
        private readonly WordCensor          $censor,
        private readonly ChatConfig          $config,
    ) {}

    /** @return Result<int> */
    public function send(ChatIdentity $from, string $toNick, string $content): Result
    {
        if (!$this->config->allowPm() || $this->gate->cannot(Permission::CHAT_PM)) {
            return $this->err('gate_denied');
        }
        // Guests may be barred from PMs while members keep them.
        if (!$from->isMember && $this->config->disableGuestPm()) {
            return $this->err('gate_denied');
        }

        $content = trim($content);
        if ($content === '') {
            return $this->err('empty');
        }
        if (mb_strlen($content) > $this->config->maxLength()) {
            return $this->err('too_long');
        }

        $toNick       = trim($toNick);
        $targetResult = $this->presence->findByNick($toNick);
        $target       = $targetResult->isOk() ? $targetResult->unwrap() : null;
        if ($target === null) {
            // Not in the roster — try a registered member (offline inbox delivery).
            $memberResult = $this->repo->findMemberByNick($toNick);
            $target       = $memberResult->isOk() ? $memberResult->unwrap() : null;
        }
        if ($target === null) {
            return $this->err('pm_target');
        }
        $toIdent      = is_scalar($target['ident'] ?? null) ? (string) $target['ident'] : '';
        $toNickActual = is_scalar($target['nick']  ?? null) ? (string) $target['nick']  : $toNick;
        if ($toIdent === '' || $toIdent === $from->ident) {
            return $this->err('pm_target');
        }

        $censored = $this->censor->apply($content);
        if ($censored['blocked']) {
            return $this->err('censored');
        }
        $content = $censored['text'];

        $expiresAt = date('Y-m-d H:i:s', time() + $this->config->pmRetentionMinutes() * 60);
        return $this->repo->create(
            $from->ident, $from->nick, $from->userId, $toIdent, $toNickActual, $from->color, $content, $expiresAt
        );
    }

    /**
     * Conversation lines involving $ident, each enriched with a rendered `html`
     * field and an `incoming` flag.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function inbox(string $ident, ?int $limit = null): Result
    {
        $this->repo->gcExpired();
        $result = $this->repo->conversation($ident, $limit ?? $this->config->messagesShown());
        if (!$result->isOk()) {
            return $result;
        }
        $rows   = $result->unwrap();
        $bbcode = $this->config->bbcodeEnabled();
        $links  = $this->config->linkConversion();

        $out = [];
        foreach ($rows as $row) {
            $contentRaw       = $row['content'] ?? '';
            $content          = is_scalar($contentRaw) ? (string) $contentRaw : '';
            $row['html']      = $this->bbcode->render($content, $bbcode, $links, $this->config->imageEmbed());
            $row['incoming']  = (($row['to_ident'] ?? null) === $ident);
            $out[]            = $row;
        }
        return Result::ok($out);
    }

    /** @return Result<bool> */
    public function markRead(string $ident): Result
    {
        return $this->repo->markReadFor($ident);
    }

    public function unread(string $ident): int
    {
        $r = $this->repo->unreadCount($ident);
        return $r->isOk() ? $r->unwrap() : 0;
    }

    /** @return Result<never> */
    private function err(string $op): Result
    {
        $d = match ($op) {
            'gate_denied' => new ChatGateDeniedDiagnostic('astrx.chat/gate_denied', DiagnosticLevel::WARNING),
            'empty'       => new ChatEmptyDiagnostic('astrx.chat/empty', DiagnosticLevel::NOTICE),
            'too_long'    => new ChatTooLongDiagnostic('astrx.chat/too_long', DiagnosticLevel::NOTICE),
            'censored'    => new ChatCensoredDiagnostic('astrx.chat/censored', DiagnosticLevel::NOTICE),
            default       => new ChatPmTargetDiagnostic('astrx.chat/pm_target', DiagnosticLevel::NOTICE),
        };
        return Result::err(null, Diagnostics::of($d));
    }
}
