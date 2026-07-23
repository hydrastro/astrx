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
 * User reports → moderator queue (#132).
 *
 * Any participant can `report()` a message (ungated; just needs a valid ident).
 * Moderators (CHAT_MODERATE) review the queue and either `dismiss()` it or
 * `blockLink()` — turn the reported message's link into a kick filter so anyone
 * who posts it next is auto-removed (closing the loop with Phase 4's filters).
 *
 * blockLink writes the filter via ChatFilterRepository DIRECTLY (not the
 * ADMIN_CONFIG_CHAT-gated ChatFilterService), because this is a moderator action
 * authorised by CHAT_MODERATE — so a mod who isn't an admin can still act.
 */
final class ChatReportService
{
    public function __construct(
        private readonly ChatReportRepository $repo,
        private readonly ChatFilterRepository $filters,
        private readonly Gate                 $gate,
    ) {}

    /**
     * File a report (any participant).
     *
     * @return Result<bool>
     */
    public function report(int $messageId, string $reporterIdent): Result
    {
        if ($messageId <= 0 || $reporterIdent === '') {
            return Result::ok(false);
        }
        return $this->repo->create($messageId, $reporterIdent);
    }

    /**
     * Pending reports for the moderator panel, each with the first URL in the
     * message (so the panel can offer "block this link").
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function pending(): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $r = $this->repo->pending();
        if (!$r->isOk()) {
            return $r;
        }
        $out = [];
        foreach ($r->unwrap() as $row) {
            $content = is_scalar($row['content'] ?? null) ? (string) $row['content'] : '';
            $row['first_url'] = $this->firstUrl($content);
            $out[] = $row;
        }
        return Result::ok($out);
    }

    public function countPending(): int
    {
        $r = $this->repo->countPending();
        return $r->isOk() ? $r->unwrap() : 0;
    }

    /**
     * Dismiss all reports on a message (no filter).
     *
     * @return Result<bool>
     */
    public function dismiss(int $messageId): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        return $this->repo->resolveMessage($messageId);
    }

    /**
     * Approve: turn the reported message's first link into a kick filter, then
     * resolve the reports. No-op filter if the message has no link.
     *
     * @return Result<bool>
     */
    public function blockLink(int $messageId): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->denied();
        }
        $cr      = $this->repo->messageContent($messageId);
        $content = ($cr->isOk() && is_string($cr->unwrap())) ? $cr->unwrap() : '';
        $url     = $this->firstUrl($content);
        if ($url !== '') {
            $this->filters->add(
                mb_substr($url, 0, 255),
                ChatFilterService::KIND_LINK,
                ChatFilterService::ACTION_KICK,
                false,
            );
        }
        return $this->repo->resolveMessage($messageId);
    }

    private function firstUrl(string $text): string
    {
        if (preg_match('~\bhttps?://[^\s\[\]<>"\']+~i', $text, $m) === 1) {
            return $m[0];
        }
        return '';
    }

    /** @return Result<never> */
    private function denied(): Result
    {
        return Result::err(null, Diagnostics::of(new ChatGateDeniedDiagnostic(
            'astrx.chat/gate_denied', DiagnosticLevel::WARNING
        )));
    }
}
