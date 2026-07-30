<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatNav;
use AstrX\Chat\ChatPmService;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatService;
use AstrX\Chat\ChatSettingsService;
use AstrX\Chat\ChatStyles;
use AstrX\Chat\Diagnostic\ChatKickedDiagnostic;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Http\UploadedFile;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Chat page controller — the STABLE SHELL of a no-JavaScript, le-chat-style
 * chatroom. Page row: WORDING_CHAT, template=1.
 *
 * This page never auto-refreshes. It hosts three <iframe> panes (message stream,
 * online users, private messages) that each carry their own
 * <meta http-equiv="refresh">, and a set of PRG-backed forms (post, PM, clean,
 * leave). Because the shell is stable, a half-typed message is never lost when a
 * pane reloads.
 *
 * Entry gating (identity → login, member auto-join, kicked → bounce, waiting →
 * hold, active → render) runs on every GET before the shell is drawn, so a
 * participant who was kicked or is still in the waiting room never sees the
 * room. POST is intercepted by ContentManager into the PRG and replayed here as
 * ?_prg=token — the same flow every other form controller uses.
 */
final class ChatController extends AbstractController
{
    private const FORM = 'chat';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ChatService            $chat,
        private readonly ChatPresenceService    $presence,
        private readonly ChatPmService          $pm,
        private readonly ChatSettingsService    $settings,
        private readonly ChatConfig             $config,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly ChatNav                $nav,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        // PRG replay: a form was posted, stored, and redirected back here.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $redirect = $this->processSubmission($prgToken);
            Response::redirect($redirect)->send()->drainTo($this->collector);
            exit;
        }

        return $this->renderPage();
    }

    // -------------------------------------------------------------------------
    // POST handling (post / pm / clean / leave)
    // -------------------------------------------------------------------------

    /** @return string the URL to redirect back to */
    private function processSubmission(string $prgToken): string
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return $this->selfUrl();
        }

        $identity = $this->presence->identity();
        $action   = self::mStr($posted, 'action', 'post');

        // Leaving does not require an active presence — always tear down and
        // bounce to the entry page.
        if ($action === 'leave') {
            if ($identity !== null) {
                $this->chat->postSystem($identity->nick, 'leave')->drainTo($this->collector);
                $this->presence->leave($identity->ident)->drainTo($this->collector);
            }
            $this->presence->clearGuest();
            return $this->loginUrl();
        }

        // Every other action needs a chat identity.
        if ($identity === null) {
            return $this->loginUrl();
        }

        if ($action === 'clean') {
            // cleanRoom() re-checks CHAT_MODERATE itself.
            $this->chat->cleanRoom()->drainTo($this->collector);
            return $this->selfUrl();
        }

        if ($action === 'rearrange') {
            // Cosmetic, per-session layout flip — no elevated rights needed.
            $this->presence->toggleLayout();
            return $this->selfUrl();
        }

        if ($action === 'postbox') {
            // Toggle the post box between single-line and multiline (per session).
            $this->presence->togglePostbox();
            return $this->selfUrl();
        }

        if ($action === 'topic') {
            // setTopic() re-checks CHAT_MODERATE itself.
            $this->chat->setTopic(self::mStr($posted, 'topic', ''))->drainTo($this->collector);
            return $this->selfUrl();
        }

        if ($action === 'clean_nick') {
            // cleanByNick() re-checks CHAT_MODERATE itself.
            $this->chat->cleanByNick(self::mStr($posted, 'nick', ''))->drainTo($this->collector);
            return $this->selfUrl();
        }

        if ($action === 'pm') {
            // A typed member name (offline-capable) takes precedence over the
            // online-recipient dropdown.
            $to = self::mStr($posted, 'to_name', '');
            if (trim($to) === '') {
                $to = self::mStr($posted, 'to', '');
            }
            $this->pm->send($identity, $to, self::mStr($posted, 'content', ''), $this->packedIp())
                ->drainTo($this->collector);
            return $this->selfUrl();
        }

        // action = post — the unified send box. A chosen recipient (the online
        // dropdown, or a typed offline member name that wins over it) → a private
        // message; "Everyone" (blank) → a public message.
        $toName  = trim(self::mStr($posted, 'to_name', ''));
        $to      = $toName !== '' ? $toName : trim(self::mStr($posted, 'to', ''));
        $content = self::mStr($posted, 'content', '');
        if ($to !== '' && $this->config->allowPm()) {
            $this->pm->send($identity, $to, $content, $this->packedIp())->drainTo($this->collector);
            return $this->selfUrl();
        }
        $attachment = null;
        $uploaded   = $this->request->files()->get('attachment');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            $attachment = $uploaded;
        }
        $this->chat->post($identity, $content, $this->packedIp(), $attachment)->drainTo($this->collector);
        return $this->selfUrl();
    }

    // -------------------------------------------------------------------------
    // Entry gating + rendering
    // -------------------------------------------------------------------------

    /** @return Result<mixed> */
    private function renderPage(): Result
    {
        // Chat-disabled kill switch — non-staff see only the disabled message.
        if (!$this->config->chatEnabled() && $this->gate->cannot(Permission::CHAT_MODERATE)) {
            $msg = $this->config->disabledMessage();
            $this->ctx->set('chat_disabled',         true);
            $this->ctx->set('chat_disabled_message', $msg !== '' ? $msg : $this->t->t('chat.disabled_default'));
            $this->ctx->set('chat_css', ChatStyles::shellCss());
            $this->nav->apply($this->ctx, 'chat');
            return $this->ok();
        }

        // ── Entry gating state machine ────────────────────────────────────────
        $identity = $this->presence->identity();
        if ($identity === null) {
            $this->redirect($this->loginUrl());
        }

        // Members-only — guests may not be in the room. Eject any who slipped in
        // (e.g. the mode was flipped on while they were active) and bounce to the
        // entry page, which shows the members-only notice.
        if ($this->config->membersOnly() && !$identity->isMember) {
            $this->presence->leave($identity->ident)->drainTo($this->collector);
            $this->presence->clearGuest();
            $this->redirect($this->loginUrl());
        }

        $presResult = $this->presence->presence($identity->ident);
        $presResult->drainTo($this->collector);
        $p = $presResult->isOk() ? $presResult->unwrap() : null;

        if ($p === null) {
            // No presence row: members auto-join active; guests must go to login.
            if (!$identity->isMember) {
                $this->redirect($this->loginUrl());
            }
            $this->presence->join($identity, ChatPresenceService::STATUS_ACTIVE, $this->packedIp())
                ->drainTo($this->collector);
            $this->chat->postSystem($identity->nick, 'join')->drainTo($this->collector);
        } else {
            $status = self::mInt($p, 'status', ChatPresenceService::STATUS_WAITING);

            if ($status === ChatPresenceService::STATUS_KICKED) {
                $this->presence->leave($identity->ident)->drainTo($this->collector);
                $this->presence->clearGuest();
                $this->emit(new ChatKickedDiagnostic('astrx.chat/kicked', DiagnosticLevel::NOTICE));
                $this->redirect($this->loginUrl());
            }

            if ($status === ChatPresenceService::STATUS_PENDING) {
                // Awaiting moderator approval — hold at the wait page, which shows
                // the pending notice and refreshes until a moderator admits them.
                $this->redirect($this->waitUrl());
            }

            if ($status === ChatPresenceService::STATUS_WAITING) {
                $wait      = $this->config->waitingRoomSeconds();
                $waited    = $this->presence->secondsSinceJoin() >= $wait;
                $mandatory = $this->config->waitingRoomMandatory();
                // Non-mandatory: arriving at the shell IS the "enter now" skip, so
                // promote straight away. Mandatory: hold at the waiting room until
                // the full wait has elapsed — there is no way to short-circuit it.
                if ($wait <= 0 || $waited || !$mandatory) {
                    $this->presence->setActive($identity->ident)->drainTo($this->collector);
                    $this->chat->postSystem($identity->nick, 'join')->drainTo($this->collector);
                } else {
                    $this->redirect($this->waitUrl());
                }
            } else {
                // Active — bump the heartbeat so the roster keeps us online.
                $this->presence->heartbeat($identity->ident)->drainTo($this->collector);
            }
        }

        // ── Participant is active: build the shell context ────────────────────
        $settings     = $this->settings->effective($identity->ident);
        $refresh      = self::mInt($settings, 'refresh_secs', $this->config->defaultRefreshSecs());
        $refreshParam = ['refresh' => (string) $refresh];

        $streamUrl = $this->urlGen->toPage($this->t->t('WORDING_CHAT_STREAM'), $refreshParam);
        $usersUrl  = $this->urlGen->toPage($this->t->t('WORDING_CHAT_USERS'),  $refreshParam);
        // PMs are off entirely when disabled, or — for a guest — when guest PMs
        // are disabled (members keep them).
        $allowPm   = $this->config->allowPm()
            && !(!$identity->isMember && $this->config->disableGuestPm());
        $pmUrl     = $allowPm
            ? $this->urlGen->toPage($this->t->t('WORDING_CHAT_PM'), $refreshParam)
            : '';

        $canPost = $this->gate->can(Permission::CHAT_POST)
            && ($identity->isMember || $this->config->guestPosting());

        $topic    = $this->chat->effectiveTopic();
        $unreadPm = $this->pm->unread($identity->ident);

        // Online users (excluding self) for the PM recipient dropdown.
        $pmRecipients = [];
        if ($allowPm) {
            $onlineResult = $this->presence->onlineUsers();
            $onlineResult->drainTo($this->collector);
            foreach (($onlineResult->isOk() ? $onlineResult->unwrap() : []) as $u) {
                $nick = self::mStr($u, 'nick', '');
                if ($nick !== '' && self::mStr($u, 'ident', '') !== $identity->ident) {
                    $pmRecipients[] = ['nick' => $nick];
                }
            }
        }

        $this->ctx->set('prg_id',            $this->prg->createId($this->selfUrl()));
        $this->ctx->set('csrf_token',        $this->csrf->generate(self::FORM));
        $this->ctx->set('stream_url',        $streamUrl);
        $this->ctx->set('users_url',         $usersUrl);
        $this->ctx->set('pm_url',            $pmUrl);
        $this->ctx->set('allow_pm',          $allowPm);
        $this->ctx->set('room_topic',        $topic);
        $this->ctx->set('room_topic_present', $topic !== '');
        $this->ctx->set('is_member',         $identity->isMember);
        $this->ctx->set('my_nick',           $identity->nick);
        $this->ctx->set('can_post',          $canPost);
        $this->ctx->set('is_mod',            $this->gate->can(Permission::CHAT_MODERATE));
        $this->ctx->set('max_length',        $this->config->maxLength());
        $this->ctx->set('unread_pm',         $unreadPm);
        $this->ctx->set('has_unread_pm',     $unreadPm > 0);
        $this->ctx->set('settings_url',      $this->urlGen->toPage($this->t->t('WORDING_CHAT_SETTINGS')));
        $this->ctx->set('pm_recipients',     $pmRecipients);
        $this->ctx->set('has_pm_recipients', $pmRecipients !== []);
        $this->ctx->set('chat_pm_no_recipients', $this->t->t('chat.pm_no_recipients'));
        $this->ctx->set('chat_css',          ChatStyles::shellCss());
        $this->ctx->set('hide_chatters',     self::mBool($settings, 'hide_chatters'));

        // Image attachments: show the file input only when enabled and this user
        // (member, or guest when guests are permitted) may upload.
        $this->ctx->set('uploads_ok', $this->config->uploadsEnabled()
            && ($identity->isMember || $this->config->uploadsGuests()));
        $this->ctx->set('chat_attach_label', $this->t->t('chat.attach'));

        // Shell control bar (reload / rearrange) visibility + the layout state.
        $this->ctx->set('nav_show_reload',    !$this->config->hideReloadButton());
        $this->ctx->set('nav_show_rearrange', !$this->config->hideRearrangeButton());
        $this->ctx->set('chat_alt_layout',    $this->presence->layoutAlt());
        $this->ctx->set('self_url',           $this->selfUrl());

        // Post box mode (single-line ↔ multiline) + the toggle button's label,
        // which names the OTHER mode you'd switch to.
        $multiline = $this->presence->postboxMultiline();
        $this->ctx->set('postbox_multiline', $multiline);
        $this->ctx->set('chat_postbox_toggle_label',
            $this->t->t($multiline ? 'chat.postbox_single' : 'chat.postbox_multiline'));

        // Chat toolbar (Chat · Profile · Help), shown on this shell page.
        $this->nav->apply($this->ctx, 'chat');

        $this->setLabels();

        // Admin-configured chat name overrides the default heading.
        if ($this->config->chatName() !== '') {
            $this->ctx->set('chat_heading', $this->config->chatName());
        }

        return $this->ok();
    }

    private function setLabels(): void
    {
        foreach ([
            'chat_heading'         => 'chat.heading',
            'chat_settings'        => 'chat.settings',
            'chat_unread_pm'       => 'chat.unread_pm',
            'chat_messages_title'  => 'chat.messages',
            'chat_users_title'     => 'chat.users_title',
            'chat_pm_title'        => 'chat.pm_title',
            'chat_posting_as'      => 'chat.posting_as',
            'chat_guest_tag'       => 'chat.guest_tag',
            'chat_message'         => 'chat.message',
            'chat_placeholder'     => 'chat.placeholder',
            'chat_send'            => 'chat.send',
            'chat_send_to'         => 'chat.send_to',
            'chat_to_everyone'     => 'chat.to_everyone',
            'chat_formatting_hint' => 'chat.formatting_hint',
            'chat_pm_to'           => 'chat.pm_to',
            'chat_pm_message'      => 'chat.pm_message',
            'chat_pm_send'         => 'chat.pm_send',
            'chat_cannot_post'     => 'chat.cannot_post',
            'chat_clean_room'      => 'chat.clean_room',
            'chat_leave'           => 'chat.leave',
            'chat_set_topic'       => 'chat.set_topic',
            'chat_topic_ph'        => 'chat.topic_ph',
            'chat_clean_nick'      => 'chat.clean_nick',
            'chat_clean_nick_ph'   => 'chat.clean_nick_ph',
            'chat_reload_messages' => 'chat.reload_messages',
            'chat_reload_online'   => 'chat.reload_online',
            'chat_reload_postbox'  => 'chat.reload_postbox',
            'chat_rearrange'       => 'chat.rearrange',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Send a redirect and stop; never returns. */
    private function redirect(string $url): never
    {
        Response::redirect($url)->send()->drainTo($this->collector);
        exit;
    }

    /** The guest's / member's packed IP (inet_pton), or null when unavailable. */
    private function packedIp(): ?string
    {
        $ipRaw = $this->request->server()->get('REMOTE_ADDR');
        $ip    = is_scalar($ipRaw) ? (string) $ipRaw : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return inet_pton($ip) ?: null;
    }

    private function selfUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CHAT'));
    }

    private function loginUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN'));
    }

    private function waitUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CHAT_WAIT'));
    }
}
