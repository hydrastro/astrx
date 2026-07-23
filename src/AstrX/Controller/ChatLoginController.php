<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\BanlistRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Captcha\CaptchaService;
use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatFilterService;
use AstrX\Chat\ChatIdentity;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatService;
use AstrX\Chat\ChatStyles;
use AstrX\Chat\Diagnostic\ChatBannedDiagnostic;
use AstrX\Chat\Diagnostic\ChatEntryPasswordDiagnostic;
use AstrX\Chat\Diagnostic\ChatNickBlockedDiagnostic;
use AstrX\Chat\Diagnostic\ChatNickInvalidDiagnostic;
use AstrX\Chat\Diagnostic\ChatNickTakenDiagnostic;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserGroup;
use AstrX\User\UserRepository;
use AstrX\User\UserSession;

/**
 * Chat entry / guest-login controller — page file_name `chat_login`, template=1.
 *
 * A stable, no-JavaScript gate in front of the chat shell:
 *
 *   - Registered members never see the form. On GET they are joined into the
 *     roster as ACTIVE and redirected straight to the chat shell (WORDING_CHAT).
 *   - Guests get a small form: a nickname, an optional colour, the room rules,
 *     and (when configured) a captcha. On submit the nickname is validated,
 *     checked against registered usernames, against the live roster, and against
 *     the IP/nick banlist; a clean guest then joins the roster and is sent to the
 *     waiting room (WORDING_CHAT_WAIT) — or straight into the chat when no
 *     waiting room is configured.
 *
 * POST is intercepted by ContentManager, stored in the PRG, and replayed here as
 * ?_prg=token — the same flow every other form controller uses. A rejected
 * submission emits a chat diagnostic and redirects back to this form so the
 * error surfaces on reload; a successful one redirects onward and exits.
 */
final class ChatLoginController extends AbstractController
{
    private const FORM = 'chat_login';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly UserSession            $session,
        private readonly ChatConfig             $config,
        private readonly ChatPresenceService    $presence,
        private readonly CaptchaService         $captchaService,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly UserRepository         $users,
        private readonly BanlistRepository      $banlist,
        private readonly ChatService            $chat,
        private readonly ChatFilterService      $filters,
        private readonly Gate                   $gate,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(\AstrX\Support\langDir(), 'Chat');

        // Chat-disabled kill switch — non-staff see only the disabled message.
        if (!$this->config->chatEnabled() && $this->gate->cannot(Permission::CHAT_MODERATE)) {
            return $this->renderDisabled();
        }

        // Members-only mode — guests are turned away; only registered members
        // (who are logged in) may enter. Staff are members too, so this never
        // locks out moderation.
        if ($this->config->membersOnly() && !$this->session->isLoggedIn()) {
            return $this->renderMembersOnly();
        }

        // Members skip the form entirely: auto-join as ACTIVE and go to the shell.
        if ($this->session->isLoggedIn()) {
            $identity = $this->presence->identity();
            if ($identity !== null) {
                // Announce the join only on a real transition into the room.
                $priorResult = $this->presence->presence($identity->ident);
                $prior       = $priorResult->isOk() ? $priorResult->unwrap() : null;
                $wasActive   = $prior !== null
                    && self::mInt($prior, 'status', 0) === ChatPresenceService::STATUS_ACTIVE;

                $ip     = $this->clientIp();
                $packed = $this->packIp($ip);
                $this->presence->join($identity, ChatPresenceService::STATUS_ACTIVE, $packed)
                    ->drainTo($this->collector);
                if (!$wasActive) {
                    $this->chat->postSystem($identity->nick, 'join')->drainTo($this->collector);
                }
            }
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // PRG replay of a guest submission.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            // On success this redirects onward and exits; otherwise it returns and
            // we bounce back to the (freshly re-rendered) form with the diagnostic.
            $this->processSubmission($prgToken);
            Response::redirect($this->request->uri()->path())
                ->send()->drainTo($this->collector);
            exit;
        }

        return $this->renderForm();
    }

    // -------------------------------------------------------------------------
    // POST handling (guest join)
    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        if ($this->config->guestCaptcha()) {
            $captcha = $this->captchaService->verify(
                self::mStr($posted, 'captcha_id', ''),
                self::mStr($posted, 'captcha_text', ''),
            );
            if (!$captcha->isOk()) {
                $captcha->drainTo($this->collector);
                return;
            }
        }

        // Global entry password for guests — a blank config disables the gate.
        $entryPw = $this->config->entryPassword();
        if ($entryPw !== '' && !hash_equals($entryPw, self::mStr($posted, 'entry_password', ''))) {
            $this->emit(new ChatEntryPasswordDiagnostic('astrx.chat/entry_password', DiagnosticLevel::NOTICE));
            return;
        }

        // Nickname shape: a custom admin regex (with its own delimiters) is
        // authoritative when set and syntactically valid; otherwise the built-in
        // length + safe-character rule applies. An invalid regex is ignored so a
        // bad config edit can never lock everyone out.
        $nick    = trim(self::mStr($posted, 'nick', ''));
        $pattern = '/^[\p{L}\p{N}_.\- ]{' . $this->config->nickMinLen()
                 . ',' . $this->config->nickMaxLen() . '}$/u';
        $custom  = $this->config->nickRegex();
        if ($custom !== '' && @preg_match($custom, '') !== false) {
            $pattern = $custom;
        }
        if (preg_match($pattern, $nick) !== 1) {
            $this->emit(new ChatNickInvalidDiagnostic('astrx.chat/nick_invalid', DiagnosticLevel::NOTICE));
            return;
        }

        // Managed nickname filter (#134): a matched nick pattern is refused entry.
        if ($this->filters->nickBlocked($nick) !== null) {
            $this->emit(new ChatNickBlockedDiagnostic('astrx.chat/nick_blocked', DiagnosticLevel::NOTICE));
            return;
        }

        // Must not collide with a registered username (true = available/not-taken).
        $available = $this->users->isUsernameAvailable($nick);
        if (!$available->isOk() || $available->unwrap() !== true) {
            $this->emit(new ChatNickTakenDiagnostic('astrx.chat/nick_taken', DiagnosticLevel::NOTICE));
            return;
        }

        // Must be free in the live roster.
        $token = $this->presence->ensureGuestToken();
        if (!$this->presence->nickAvailableInRoster($nick, $token)) {
            $this->emit(new ChatNickTakenDiagnostic('astrx.chat/nick_taken', DiagnosticLevel::NOTICE));
            return;
        }

        // Banlist: IP or nickname.
        $ip      = $this->clientIp();
        $ipBan   = $this->banlist->findActiveBanForIp($ip);
        $nickBan = $this->banlist->findActiveBanForNick($nick);
        if (($ipBan->isOk() && $ipBan->unwrap() !== null)
            || ($nickBan->isOk() && $nickBan->unwrap() !== null)) {
            $this->emit(new ChatBannedDiagnostic('astrx.chat/banned', DiagnosticLevel::NOTICE));
            return;
        }

        // Passed the gate — persist the guest profile and join the roster.
        $color  = $this->config->allowUserColor()
            ? $this->resolveColor($posted)
            : null;
        $packed = $this->packIp($ip);

        $this->presence->setGuestProfile($nick, $color);
        $identity = new ChatIdentity($token, false, null, $nick, $color, UserGroup::GUEST->value);

        $mode = $this->config->guestAccessMode();

        // Moderator-approval — queue the guest as PENDING for a moderator to
        // admit, UNLESS the fallback is on and no moderator is currently online
        // (then they use the timed waiting room instead of being stuck forever).
        if ($mode === ChatConfig::ACCESS_APPROVAL
            && !($this->config->approvalFallbackWaiting() && !$this->presence->anyModOnline())) {
            $this->presence->join($identity, ChatPresenceService::STATUS_PENDING, $packed)
                ->drainTo($this->collector);
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT_WAIT')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // Open, or a waiting room configured to zero seconds → straight in.
        if ($mode === ChatConfig::ACCESS_OPEN || $this->config->waitingRoomSeconds() <= 0) {
            $this->presence->join($identity, ChatPresenceService::STATUS_ACTIVE, $packed)
                ->drainTo($this->collector);
            $this->chat->postSystem($nick, 'join')->drainTo($this->collector);
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // Timed waiting room (waiting_room mode, or approval-fallback with no mod).
        $this->presence->join($identity, ChatPresenceService::STATUS_WAITING, $packed)
            ->drainTo($this->collector);
        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT_WAIT')))
            ->send()->drainTo($this->collector);
        exit;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** @return Result<mixed> */
    private function renderForm(): Result
    {
        $selfUrl = $this->request->uri()->path();

        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        $this->ctx->set('nick_max',    $this->config->nickMaxLen());
        $this->ctx->set('allow_color', $this->config->allowUserColor());
        $this->ctx->set('color_options',       ChatStyles::palette());
        $this->ctx->set('color_default_label', $this->t->t('chat.color_default'));
        $this->ctx->set('color_random_label',  $this->t->t('chat.color_random'));
        $this->ctx->set('color_custom_label',  $this->t->t('chat.color_custom'));

        $rules = $this->config->roomRules();
        $this->ctx->set('has_room_rules', $rules !== '');
        $this->ctx->set('room_rules',     $rules);

        // "Currently in the chat" — who a guest would be joining. onlineUsers()
        // returns only ACTIVE participants (not those still in the waiting room),
        // so the count reflects who is actually in the room right now.
        $onlineResult = $this->presence->onlineUsers();
        $onlineResult->drainTo($this->collector);
        $onlineRows = $onlineResult->isOk() ? $onlineResult->unwrap() : [];
        $named = [];
        foreach ($onlineRows as $u) {
            if (self::mStr($u, 'nick', '') !== '') {
                $named[] = $u;
            }
        }
        $total   = count($named);
        $preview = [];
        foreach ($named as $i => $u) {
            $preview[] = [
                'nick'      => self::mStr($u, 'nick', ''),
                'is_member' => self::mBool($u, 'is_member'),
                'sep'       => $i < $total - 1 ? ', ' : '',
            ];
        }
        $this->ctx->set('online_present', $total > 0);
        $this->ctx->set('online_count',   $total);
        $this->ctx->set('online_users',   $preview);
        $this->ctx->set('online_heading', $this->t->t('chat.online_now'));
        $this->ctx->set('online_empty',   $this->t->t('chat.online_none'));

        $this->applyCaptcha();

        // Labels — flat `chat.*` keys to match the existing Chat domain; the
        // nickname field reuses the shell's `chat.guest_nick` ("Nickname").
        $this->ctx->set('chat_login_heading',       $this->t->t('chat.login_heading'));
        $this->ctx->set('chat_login_rules_heading', $this->t->t('chat.login_rules'));
        $this->ctx->set('chat_login_nick_label',    $this->t->t('chat.guest_nick'));
        $this->ctx->set('chat_login_color_label',   $this->t->t('chat.login_color'));
        $this->ctx->set('chat_login_submit',        $this->t->t('chat.login_submit'));
        $this->ctx->set('has_entry_password',       $this->config->entryPassword() !== '');
        $this->ctx->set('chat_entry_password_label', $this->t->t('chat.entry_password'));

        return $this->ok();
    }

    /**
     * Render only the "chat is disabled" message (kill switch is on and the
     * viewer is not staff). The template hides the entry form when this is set.
     *
     * @return Result<mixed>
     */
    private function renderDisabled(): Result
    {
        $msg = $this->config->disabledMessage();
        $this->ctx->set('chat_disabled',         true);
        $this->ctx->set('chat_disabled_message', $msg !== '' ? $msg : $this->t->t('chat.disabled_default'));
        return $this->ok();
    }

    /**
     * Render the "members only" notice (members-only mode is on and the viewer
     * is a guest). Reuses the disabled-wrapper so the entry form is hidden.
     *
     * @return Result<mixed>
     */
    private function renderMembersOnly(): Result
    {
        $this->ctx->set('chat_disabled',         true);
        $this->ctx->set('chat_disabled_message', $this->t->t('chat.members_only_default'));
        return $this->ok();
    }

    /**
     * Populate the shared captcha-partial context. Mirrors LoginController: an
     * iframe-reloadable widget when a captcha id was minted, falling back to the
     * inline base64 image otherwise.
     */
    private function applyCaptcha(): void
    {
        $show         = $this->config->guestCaptcha();
        $captchaId    = '';
        $captchaImage = '';

        if ($show) {
            $gen = $this->captchaService->generate();
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $captchaId    = $gen->unwrap()['id'];
                $captchaImage = $gen->unwrap()['image_b64'];
            }
        }

        $this->t->loadDomain(\AstrX\Support\langDir(), 'Captcha');
        $frameUrl = $captchaId !== ''
            ? $this->urlGen->toPage($this->t->t('WORDING_CAPTCHA_FRAME')) . '?cid=' . $captchaId
            : '';

        $this->ctx->set('show_captcha',         $show);
        $this->ctx->set('captcha_id',           $captchaId);
        $this->ctx->set('captcha_image',        $captchaImage);
        $this->ctx->set('captcha_frame_url',    $frameUrl);
        $this->ctx->set('has_captcha_frame',    $frameUrl !== '');
        $this->ctx->set('captcha_reload_label', $this->t->t('captcha.reload', fallback: 'New captcha'));
        $this->ctx->set('captcha_label',        $this->t->t('user.captcha.label', fallback: 'Captcha'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the chosen colour: a valid custom #hex wins, otherwise the named
     * palette selection (whitelisted). Returns null when neither is valid.
     *
     * @param array<string,mixed> $posted
     */
    private function resolveColor(array $posted): ?string
    {
        $custom = trim(self::mStr($posted, 'color_custom', ''));
        if ($custom !== '' && preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $custom) === 1) {
            return strtolower($custom);
        }
        $named = strtolower(trim(self::mStr($posted, 'color', '')));
        if ($named === 'random') {
            return ChatStyles::randomColor();
        }
        foreach (ChatStyles::palette() as $opt) {
            if ($opt['value'] === $named) {
                return $named;
            }
        }
        return null;
    }

    private function clientIp(): string
    {
        $ipRaw = $this->request->server()->get('REMOTE_ADDR');
        return is_scalar($ipRaw) ? (string) $ipRaw : '';
    }

    private function packIp(string $ip): ?string
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return inet_pton($ip) ?: null;
    }
}
