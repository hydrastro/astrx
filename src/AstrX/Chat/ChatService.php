<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\Diagnostic\ChatCensoredDiagnostic;
use AstrX\Chat\Diagnostic\ChatEmptyDiagnostic;
use AstrX\Chat\Diagnostic\ChatFilterBlockedDiagnostic;
use AstrX\Chat\Diagnostic\ChatFilterKickedDiagnostic;
use AstrX\Chat\Diagnostic\ChatFloodDiagnostic;
use AstrX\Chat\Diagnostic\ChatGateDeniedDiagnostic;
use AstrX\Chat\Diagnostic\ChatMutedDiagnostic;
use AstrX\Chat\Diagnostic\ChatNotFoundDiagnostic;
use AstrX\Chat\Diagnostic\ChatRoomNotFoundDiagnostic;
use AstrX\Chat\Diagnostic\ChatTooLongDiagnostic;
use AstrX\Chat\Diagnostic\ChatUploadDiagnostic;
use AstrX\Http\UploadedFile;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\User\UserSession;

/**
 * Public-message business logic for the single chat room.
 *
 * Posting enforces, in order: permission, room existence, non-empty, length,
 * guest-posting toggle, word censor, mute, and flood (with auto-mute). Messages
 * are rendered through BbcodeRenderer honouring the BBCode/link-conversion
 * config toggles. Deletion is authorised via the Gate + ChatPolicy (registered
 * at boot by GateBootstrapper). All settings come from the injected ChatConfig.
 */
final class ChatService
{
    public function __construct(
        private readonly ChatRepository      $repo,
        private readonly UserSession         $session,
        private readonly Gate                $gate,
        private readonly BbcodeRenderer      $bbcode,
        private readonly WordCensor          $censor,
        private readonly ChatFilterService   $filters,
        private readonly ChatPresenceService $presence,
        private readonly ChatKickPenalty     $kickPenalty,
        private readonly ChatAttachmentService $attachments,
        private readonly ChatConfig          $config,
    ) {}

    public function config(): ChatConfig { return $this->config; }

    // -------------------------------------------------------------------------
    // Room (single)
    // -------------------------------------------------------------------------

    /** @return Result<array<string,mixed>|null> the single active room */
    public function room(): Result
    {
        $result = $this->repo->fetchRooms();
        if (!$result->isOk()) {
            return Result::err(null, $result->diagnostics());
        }
        $rooms = $result->unwrap();
        return Result::ok($rooms[0] ?? null);
    }

    public function roomId(): int
    {
        $r    = $this->room();
        $room = $r->isOk() ? $r->unwrap() : null;
        return $room !== null ? $this->int($room, 'id', 0) : 0;
    }

    // -------------------------------------------------------------------------
    // Messages
    // -------------------------------------------------------------------------

    /**
     * Visible messages, oldest first, each enriched with a rendered `html`
     * field and an `is_member` flag. Ordering for display (newest first) is a
     * presentation concern handled by the stream frame.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function messages(?int $limit = null): Result
    {
        $roomResult = $this->room();
        if (!$roomResult->isOk()) {
            return Result::err(null, $roomResult->diagnostics());
        }
        $room = $roomResult->unwrap();
        if ($room === null) {
            return Result::ok([]);
        }
        $roomId = $this->int($room, 'id', 0);

        $this->repo->gcExpired();
        $result = $this->repo->fetchMessages($roomId, $limit ?? $this->config->messagesShown());
        if (!$result->isOk()) {
            return $result;
        }
        /** @var list<array<string,mixed>> $rows */
        $rows   = $result->unwrap();
        $bbcode = $this->config->bbcodeEnabled();
        $links  = $this->config->linkConversion();

        $enriched = [];
        $ids      = [];
        foreach ($rows as $row) {
            $contentRaw      = $row['content'] ?? '';
            $content         = is_scalar($contentRaw) ? (string) $contentRaw : '';
            $row['html']     = $this->bbcode->render($content, $bbcode, $links, $this->config->imageEmbed());
            $row['is_member'] = ($row['user_id'] ?? null) !== null;
            $mid = $this->int($row, 'id', 0);
            if ($mid > 0) { $ids[] = $mid; }
            $enriched[]      = $row;
        }
        // Attach image-attachment metadata (token/mime/dimensions) per message.
        $attachMap = $this->attachments->forMessages($ids);
        foreach ($enriched as $k => $row) {
            $enriched[$k]['attachment'] = $attachMap[$this->int($row, 'id', 0)] ?? null;
        }
        return Result::ok($enriched);
    }

    // -------------------------------------------------------------------------
    // Posting
    // -------------------------------------------------------------------------

    /**
     * Post a public message as the given identity. IP is a packed inet_pton
     * string (guests) or null.
     *
     * @return Result<int> new message id
     */
    public function post(ChatIdentity $identity, string $content, ?string $packedIp, ?UploadedFile $attachment = null): Result
    {
        if ($this->gate->cannot(Permission::CHAT_POST)) {
            return $this->opErr('gate_denied');
        }
        if (!$identity->isMember && !$this->config->guestPosting()) {
            return $this->opErr('gate_denied');
        }

        $roomResult = $this->room();
        $room       = $roomResult->isOk() ? $roomResult->unwrap() : null;
        if ($room === null) {
            return $this->opErr('room_not_found');
        }
        $roomId = $this->int($room, 'id', 0);

        if (trim($content) === '' && $attachment === null) {
            return $this->opErr('empty');
        }

        // "/me does something" → an action line, rendered "* nick does something"
        // instead of "nick: …". The command word is stripped; the remainder is a
        // normal message body (still censored, length-checked and flood-limited).
        $type = 'user';
        if (preg_match('/^\s*\/me\s+(.+)$/su', $content, $mm) === 1) {
            $type    = 'action';
            $content = $mm[1];
        }

        if (mb_strlen($content) > $this->config->maxLength()) {
            return $this->opErr('too_long');
        }

        // Managed word/link filters (enforcement) run on the RAW text, before the
        // cosmetic censor stars anything out — so a kick fires on what the poster
        // actually typed. Staff (CHAT_MODERATE) are exempt unless a filter opts
        // them in. Block rejects the post; kick also removes the poster.
        $hit = $this->filters->match($content, $this->gate->can(Permission::CHAT_MODERATE));
        if ($hit !== null) {
            if ($this->int($hit, 'action', 0) === ChatFilterService::ACTION_KICK) {
                // A filter kick removes the poster live AND enforces through the
                // banlist — a kick is a short-expiry ROUTE_CHAT ban, the SAME
                // single path a moderator kick uses, so entry re-checks keep them
                // out for kick_penalty_minutes. The matched pattern is recorded as
                // the ban reason for the admin banlist view.
                $this->presence->kick($identity->ident);
                $ipStr = '';
                if ($packedIp !== null) {
                    $ntop = @inet_ntop($packedIp);
                    if ($ntop !== false) {
                        $ipStr = $ntop;
                    }
                }
                $pattern = is_scalar($hit['pattern'] ?? null) ? (string) $hit['pattern'] : '';
                $this->kickPenalty->apply($identity->nick, $ipStr, $pattern);
                return $this->opErr('filter_kicked');
            }
            return $this->opErr('filter_blocked');
        }

        $censored = $this->censor->apply($content);
        if ($censored['blocked']) {
            return $this->opErr('censored');
        }
        $content = $censored['text'];

        $hexUserId = $identity->isMember ? $identity->userId : null;
        $nick      = $identity->isMember ? null : $identity->nick;
        $color     = null;
        if ($this->config->allowUserColor() && $identity->color !== null && $identity->color !== '') {
            $color = $this->bbcode->safeColor($identity->color);
        }

        // Mute (global chat mute — page_id IS NULL).
        $muteResult = $this->repo->isMuted($hexUserId, $packedIp);
        if ($muteResult->isOk() && $muteResult->unwrap() === true) {
            return $this->opErr('muted');
        }

        // Flood, with an automatic short mute on breach.
        if ($this->config->minFloodSecs() > 0) {
            $lastResult = $this->repo->lastMessageTime($hexUserId, $packedIp);
            if ($lastResult->isOk() && $lastResult->unwrap() !== null) {
                $elapsed = time() - $lastResult->unwrap();
                if ($elapsed < $this->config->minFloodSecs()) {
                    if ($this->config->floodMuteSecs() > 0) {
                        $this->repo->addMute($hexUserId, $packedIp, $this->config->floodMuteSecs());
                    }
                    return $this->opErr('flood');
                }
            }
        }

        // Image attachment: validated + stripped + stored BEFORE the message row,
        // so a bad upload rejects the post without leaving a message behind.
        $attachMeta = null;
        if ($attachment !== null) {
            if (!$this->attachments->mayUpload($identity->isMember)) {
                return $this->opErr('upload_disabled');
            }
            $storeResult = $this->attachments->store($attachment);
            if (!$storeResult->isOk()) {
                return Result::err(null, $storeResult->diagnostics());
            }
            $attachMeta = $storeResult->unwrap();
        }

        $expiresAt    = date('Y-m-d H:i:s', time() + $this->config->retentionMinutes() * 60);
        $createResult = $this->repo->create($roomId, $hexUserId, $nick, $color, $content, $expiresAt, $packedIp, $type);

        if ($attachMeta !== null) {
            if ($createResult->isOk()) {
                $persist = $this->attachments->persist($createResult->unwrap(), $attachMeta);
                if (!$persist->isOk()) {
                    // Row failed: drop the orphan file, keep the (text) message.
                    $this->attachments->discard($attachMeta['stored_name']);
                }
            } else {
                $this->attachments->discard($attachMeta['stored_name']);
            }
        }
        return $createResult;
    }

    /**
     * Insert a join/leave system line. $event is a token ('join' | 'leave') that
     * the stream renders in each viewer's own language — nothing user-authored is
     * stored, so it is i18n-safe. No-op when announce_join_leave is off.
     *
     * @return Result<int>
     */
    public function postSystem(string $nick, string $event): Result
    {
        if (!$this->config->announceJoinLeave()) {
            return Result::ok(0);
        }
        return $this->insertSystem($nick, $event);
    }

    /**
     * Insert a moderation system line ("namedoer") — a token ('kicked' | 'banned'
     * | 'purged') naming the target, rendered per-viewer like join/leave. No-op
     * when announce_mod_actions is off.
     *
     * @return Result<int>
     */
    public function postModAction(string $nick, string $event): Result
    {
        if (!$this->config->announceModActions()) {
            return Result::ok(0);
        }
        return $this->insertSystem($nick, $event);
    }

    /**
     * Post a moderator broadcast — a prominent announcement to the whole room.
     * Unlike system lines this carries the moderator's own text (rendered through
     * BBCode) and is stored as its own 'broadcast' message type. CHAT_MODERATE only.
     *
     * @return Result<int>
     */
    public function broadcast(string $byNick, string $message): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->opErr('gate_denied');
        }
        $message = trim($message);
        if ($message === '') {
            return $this->opErr('empty');
        }
        if (mb_strlen($message) > $this->config->maxLength()) {
            return $this->opErr('too_long');
        }
        $roomResult = $this->room();
        $room       = $roomResult->isOk() ? $roomResult->unwrap() : null;
        if ($room === null) {
            return $this->opErr('room_not_found');
        }
        $roomId    = $this->int($room, 'id', 0);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->config->retentionMinutes() * 60);
        return $this->repo->create($roomId, null, $byNick, null, $message, $expiresAt, null, 'broadcast');
    }

    /**
     * Shared insert for the i18n-safe system feed: stores the language-neutral
     * event token as the body and the subject nick, typed 'system'. No-op on a
     * blank nick or when there is no room.
     *
     * @return Result<int>
     */
    private function insertSystem(string $nick, string $event): Result
    {
        if (trim($nick) === '') {
            return Result::ok(0);
        }
        $roomResult = $this->room();
        $room       = $roomResult->isOk() ? $roomResult->unwrap() : null;
        if ($room === null) {
            return Result::ok(0);
        }
        $roomId    = $this->int($room, 'id', 0);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->config->retentionMinutes() * 60);
        return $this->repo->create($roomId, null, $nick, null, $event, $expiresAt, null, 'system');
    }

    // -------------------------------------------------------------------------
    // Moderation (message-level)
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    public function deleteMessage(int $messageId): Result
    {
        $found = $this->repo->findMessageById($messageId);
        if (!$found->isOk()) {
            return Result::err($found->error(), $found->diagnostics());
        }
        $row = $found->unwrap();
        if ($row === null) {
            return $this->opErr('not_found');
        }
        /** @var array<string,mixed> $row */
        $resource   = $this->toResource($row);
        $isOwn      = $this->isOwn($resource);
        $permission = $isOwn ? Permission::CHAT_DELETE_OWN : Permission::CHAT_DELETE_ANY;

        if ($this->gate->cannot($permission, $resource)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->deleteMessage($messageId);
    }

    /**
     * Whether the current user may delete the given message row (used to decide
     * whether to render a delete control). Mirrors deleteMessage()'s authorization.
     *
     * @param array<string,mixed> $row
     */
    public function canDeleteMessage(array $row): bool
    {
        $resource   = $this->toResource($row);
        $permission = $this->isOwn($resource) ? Permission::CHAT_DELETE_OWN : Permission::CHAT_DELETE_ANY;
        return $this->gate->can($permission, $resource);
    }

    /** @return Result<int> rows removed */
    public function cleanRoom(): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->deleteRoomMessages($this->roomId());
    }

    /**
     * Delete every message posted under a nick (mod tool — "clean by nick"),
     * without kicking.
     *
     * @return Result<int> rows removed
     */
    public function cleanByNick(string $nick): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->opErr('gate_denied');
        }
        $nick = trim($nick);
        if ($nick === '') {
            return Result::ok(0);
        }
        return $this->repo->deleteByNick($nick);
    }

    /**
     * Set the live room topic from within the chat (mod tool).
     *
     * @return Result<bool>
     */
    public function setTopic(string $topic): Result
    {
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setRoomTopic($this->roomId(), mb_substr(trim($topic), 0, 255));
    }

    /**
     * The topic shown above the stream: the live room topic (mod-editable from
     * the chat) when set, otherwise the admin-configured default.
     */
    public function effectiveTopic(): string
    {
        $r    = $this->room();
        $room = $r->isOk() ? $r->unwrap() : null;
        $live = is_array($room) && is_scalar($room['topic'] ?? null) ? trim((string) $room['topic']) : '';
        return $live !== '' ? $live : $this->config->roomTopic();
    }

    /**
     * The admin greeting / public notes board shown above the stream, rendered
     * as a SAFE HTML fragment through the SAME BBCode pipeline as chat messages
     * (escape-first, allowlisted tags only, newlines → <br>, hardened links,
     * image embedding per config). Honours the same bbcode/link-conversion
     * config as messages, so it needs no extra flags. Multi-line and formatted,
     * so a single admin-maintained field doubles as the room's public notes
     * board. Returns '' when nothing is configured.
     */
    public function greetingHtml(): string
    {
        $greeting = $this->config->greetingMessage();
        if ($greeting === '') {
            return '';
        }
        return $this->bbcode->render(
            $greeting,
            $this->config->bbcodeEnabled(),
            $this->config->linkConversion(),
            $this->config->imageEmbed(),
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function isOwn(ChatMessageResource $resource): bool
    {
        return $this->session->isLoggedIn()
            && $resource->user_id !== null
            && $resource->user_id === $this->session->userId();
    }

    /**
     * @param array<string,mixed> $row
     */
    private function toResource(array $row): ChatMessageResource
    {
        $resource = new ChatMessageResource();

        $idRaw        = $row['id'] ?? 0;
        $resource->id = is_int($idRaw) ? $idRaw : (is_numeric($idRaw) ? (int) $idRaw : 0);

        $uidRaw            = $row['user_id'] ?? null;
        $resource->user_id = is_string($uidRaw) ? $uidRaw : null;

        $typeRaw             = $row['user_type'] ?? null;
        $resource->user_type = is_int($typeRaw)
            ? $typeRaw
            : (is_numeric($typeRaw) ? (int) $typeRaw : null);

        return $resource;
    }

    /** @param array<string,mixed> $arr */
    private function int(array $arr, string $key, int $default): int
    {
        $v = $arr[$key] ?? $default;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }

    /** @return Result<never> */
    private function opErr(string $op): Result
    {
        $diagnostic = match ($op) {
            'gate_denied'    => new ChatGateDeniedDiagnostic('astrx.chat/gate_denied', DiagnosticLevel::WARNING),
            'room_not_found' => new ChatRoomNotFoundDiagnostic('astrx.chat/room_not_found', DiagnosticLevel::WARNING),
            'empty'          => new ChatEmptyDiagnostic('astrx.chat/empty', DiagnosticLevel::NOTICE),
            'too_long'       => new ChatTooLongDiagnostic('astrx.chat/too_long', DiagnosticLevel::NOTICE),
            'censored'       => new ChatCensoredDiagnostic('astrx.chat/censored', DiagnosticLevel::NOTICE),
            'filter_blocked' => new ChatFilterBlockedDiagnostic('astrx.chat/filter_blocked', DiagnosticLevel::NOTICE),
            'filter_kicked'  => new ChatFilterKickedDiagnostic('astrx.chat/filter_kicked', DiagnosticLevel::WARNING),
            'upload_disabled' => new ChatUploadDiagnostic('astrx.chat/upload_disabled', DiagnosticLevel::NOTICE),
            'muted'          => new ChatMutedDiagnostic('astrx.chat/muted', DiagnosticLevel::NOTICE),
            'flood'          => new ChatFloodDiagnostic('astrx.chat/flood', DiagnosticLevel::NOTICE),
            'not_found'      => new ChatNotFoundDiagnostic('astrx.chat/not_found', DiagnosticLevel::WARNING),
            default          => new ChatNotFoundDiagnostic('astrx.chat/unknown', DiagnosticLevel::WARNING),
        };
        return Result::err(null, Diagnostics::of($diagnostic));
    }
}
