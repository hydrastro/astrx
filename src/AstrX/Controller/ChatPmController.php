<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatPmService;
use AstrX\Chat\ChatPresenceService;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use function AstrX\Support\langDir;

/**
 * The auto-refreshing private-messages pane, served as an <iframe> body of the
 * chat page. Page row: WORDING_CHAT_PM, template=0, controller=1.
 *
 * Like ChatStreamController, this emits its OWN minimal HTML document with a
 * `<meta http-equiv="refresh" content="N">`, so the browser re-fetches just this
 * iframe every N seconds while the surrounding shell (where PMs are composed) is
 * never touched. This frame is READ-ONLY: it never posts. Its only side effects
 * are the presence heartbeat every refresh and marking the viewer's inbox read.
 *
 * Gating mirrors the other frames: no identity, or a presence row that is not
 * ACTIVE, yields a minimal "not in chat" document (still auto-refreshing, so it
 * recovers the moment the viewer is admitted). Frames NEVER redirect — they live
 * inside an iframe and a redirect there would be invisible/broken.
 *
 * The `html` field of each PM line is already a safe, BBCode-rendered fragment
 * (ChatPmService) and is emitted raw; every other datum (nick, timestamp) is
 * escaped at the point of output.
 */
final class ChatPmController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                 $collector,
        private readonly Request             $request,
        private readonly ChatPresenceService $presence,
        private readonly ChatPmService       $pm,
        private readonly ChatConfig          $config,
        private readonly Translator          $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        $ident = $this->presence->identity();
        if ($ident === null || !$this->isActive($ident->ident)) {
            // Not in the chat (yet) — a valid, still-refreshing document that
            // recovers automatically once the viewer becomes active.
            $this->renderNotInChat();
            return $this->ok(); // unreachable — renderNotInChat() exits.
        }

        // Every refresh bumps the heartbeat and clears the unread flag; both are
        // best-effort, so diagnostics are drained but never block rendering.
        $this->presence->heartbeat($ident->ident)->drainTo($this->collector);
        $this->pm->markRead($ident->ident)->drainTo($this->collector);

        $inboxResult = $this->pm->inbox($ident->ident);
        $inboxResult->drainTo($this->collector);
        $rows = $inboxResult->isOk() ? $inboxResult->unwrap() : [];

        $this->renderInbox($rows);

        // Unreachable — renderInbox() exits — but keeps the signature honest.
        return $this->ok();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderNotInChat(): void
    {
        $body = '<p class="chat-empty">' . $this->esc($this->t->t('chat.not_in_chat')) . '</p>';
        $this->emitDoc($this->refreshSecs(), $this->esc($this->t->t('chat.pm.heading')), $body);
    }

    /** @param list<array<string,mixed>> $rows */
    private function renderInbox(array $rows): void
    {
        $this->emitDoc(
            $this->refreshSecs(),
            $this->esc($this->t->t('chat.pm.heading')),
            $this->renderPmLines($rows),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows conversation lines, newest-first as
     *        ChatPmService returns them.
     */
    private function renderPmLines(array $rows): string
    {
        if ($rows === []) {
            return '<p class="chat-empty">' . $this->esc($this->t->t('chat.pm.none')) . '</p>';
        }

        // The service returns newest-first; keep that when the room is configured
        // newest-first, otherwise flip to oldest-first.
        if (!$this->config->newestFirst()) {
            $rows = array_reverse($rows);
        }

        $fromLabel = $this->t->t('chat.pm.from');
        $toLabel   = $this->t->t('chat.pm.to');
        $format    = $this->config->timestampFormat();

        $out = '<ul class="chat-pm-list">';
        foreach ($rows as $row) {
            // direction: incoming → shown as "from <sender>", outgoing → "to <recipient>".
            $incoming = self::mBool($row, 'incoming');
            $nick     = $incoming ? self::mStr($row, 'from_nick', '') : self::mStr($row, 'to_nick', '');
            $dirLabel = $incoming ? $fromLabel : $toLabel;
            $dirClass = $incoming ? 'chat-pm-in' : 'chat-pm-out';

            $ts       = self::mInt($row, 'created_ts', 0);
            $timeHtml = $ts > 0 ? $this->esc(date($format, $ts)) : '';

            // Body is already a safe HTML fragment (BbcodeRenderer) → emit raw.
            $bodyHtml = self::mStr($row, 'html', '');

            $out .=
                '<li class="chat-pm ' . $dirClass . '">'
                . ($timeHtml !== '' ? '<span class="chat-time">' . $timeHtml . '</span> ' : '')
                . '<span class="chat-pm-dir">' . $this->esc($dirLabel) . ' '
                . '<span class="chat-pm-nick">' . $this->esc($nick) . '</span></span>'
                . '<span class="chat-sep">:</span> '
                . '<span class="chat-body">' . $bodyHtml . '</span>'
                . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }

    /**
     * Emit the complete iframe document and hard-stop. Raw-bytes controllers must
     * not fall through to the framework's template path (see ChatStreamController).
     */
    private function emitDoc(int $refresh, string $title, string $body): void
    {
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            // Same-origin embedding only — this document lives inside the chat
            // page's iframe and nowhere else.
            header('X-Frame-Options: SAMEORIGIN');
        }

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="{$refresh}">
<title>{$title}</title>
<style>
    body { margin: 0; padding: .4em .6em; font: 13px/1.45 sans-serif; }
    ul.chat-pm-list { list-style: none; margin: 0; padding: 0; }
    li.chat-pm { padding: 2px 0; border-bottom: 1px solid #eee; word-wrap: break-word; overflow-wrap: anywhere; }
    .chat-time { color: #999; font-size: 11px; }
    .chat-pm-dir { font-weight: bold; }
    .chat-pm-in .chat-pm-dir { color: #1a5db8; }
    .chat-pm-out .chat-pm-dir { color: #7a7a7a; }
    .chat-pm-nick { text-decoration: underline; }
    .chat-body code.chat-code { background: #f2f2f2; padding: 0 .2em; }
    .chat-body blockquote.chat-quote { margin: .2em 0 .2em .8em; padding-left: .5em; border-left: 3px solid #ccc; color: #444; }
    .chat-empty { color: #777; }
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** True only when the viewer holds an ACTIVE presence row in the room. */
    private function isActive(string $ident): bool
    {
        $result = $this->presence->presence($ident);
        $result->drainTo($this->collector);
        if (!$result->isOk()) {
            return false;
        }
        $row = $result->unwrap();
        return is_array($row)
            && self::mInt($row, 'status', -1) === ChatPresenceService::STATUS_ACTIVE;
    }

    /** Refresh cadence: an optional ?refresh=N, clamped to the configured range. */
    private function refreshSecs(): int
    {
        $requested = self::queryInt($this->request, 'refresh', $this->config->defaultRefreshSecs());
        return max($this->config->minRefreshSecs(), min($this->config->maxRefreshSecs(), $requested));
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
