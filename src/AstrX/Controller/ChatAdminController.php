<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatModerationService;
use AstrX\Chat\ChatReportService;
use AstrX\Chat\ChatNav;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserGroup;
use function AstrX\Support\langDir;

/**
 * In-chat moderator admin panel — page file_name `chat_admin`, template=1.
 *
 * le-chat's "Administrative functions", gated by CHAT_MODERATE inside the
 * controller (the page is routed normally but shows only a forbidden notice to
 * non-moderators). Consolidates the live tools: an active-sessions table with
 * multi-kick (+ a kick message, + "all guests"), logout of idle participants,
 * clean the room or by nick, set the topic, and broadcast an announcement. POST
 * is intercepted by ContentManager into the PRG and replayed here as
 * ?_prg=token, the same flow every other chat form uses.
 */
final class ChatAdminController extends AbstractController
{
    private const FORM = 'chat_admin';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ChatPresenceService    $presence,
        private readonly ChatModerationService  $mod,
        private readonly ChatService            $chat,
        private readonly ChatReportService      $reports,
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

        // Non-moderators see only the forbidden notice (with the toolbar).
        if ($this->gate->cannot(Permission::CHAT_MODERATE)) {
            http_response_code(403);
            $this->ctx->set('chat_admin_forbidden',     true);
            $this->ctx->set('chat_admin_forbidden_msg', $this->t->t('chat.admin.forbidden'));
            $this->nav->apply($this->ctx, 'admin');
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken);
            Response::redirect($this->selfUrl())->send()->drainTo($this->collector);
            exit;
        }

        return $this->renderPanel();
    }

    // -------------------------------------------------------------------------
    // POST handling — every mutating call re-checks CHAT_MODERATE in its service.
    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        switch (self::mStr($posted, 'action', '')) {
            case 'kick':
                $message = self::mStr($posted, 'message', '');
                foreach ($this->identList($posted) as $ident) {
                    $this->mod->kick($ident, $message)->drainTo($this->collector);
                }
                if (self::mBool($posted, 'all_guests')) {
                    $this->mod->kickAllGuests()->drainTo($this->collector);
                }
                break;
            case 'logout_inactive':
                $this->mod->logoutInactive()->drainTo($this->collector);
                break;
            case 'clean':
                $this->chat->cleanRoom()->drainTo($this->collector);
                break;
            case 'clean_nick':
                $this->chat->cleanByNick(self::mStr($posted, 'nick', ''))->drainTo($this->collector);
                break;
            case 'topic':
                $this->chat->setTopic(self::mStr($posted, 'topic', ''))->drainTo($this->collector);
                break;
            case 'broadcast':
                $identity = $this->presence->identity();
                $by       = $identity !== null ? $identity->nick : '';
                $this->chat->broadcast($by, self::mStr($posted, 'message', ''))->drainTo($this->collector);
                break;
            case 'report_dismiss':
                $this->reports->dismiss(self::mInt($posted, 'id', 0))->drainTo($this->collector);
                break;
            case 'report_block':
                $this->reports->blockLink(self::mInt($posted, 'id', 0))->drainTo($this->collector);
                break;
        }
    }

    /**
     * The checked session idents from the multi-kick form (array field idents[]).
     *
     * @param array<string,mixed> $posted
     * @return list<string>
     */
    private function identList(array $posted): array
    {
        $raw = $posted['idents'] ?? [];
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $i) {
                if (is_string($i) && $i !== '') {
                    $out[] = $i;
                }
            }
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** @return Result<mixed> */
    private function renderPanel(): Result
    {
        $selfUrl = $this->selfUrl();
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        $sessionsResult = $this->presence->allSessions();
        $sessionsResult->drainTo($this->collector);
        $rows = $sessionsResult->isOk() ? $sessionsResult->unwrap() : [];

        $tsFmt    = $this->config->timestampFormat();
        $sessions = [];
        foreach ($rows as $r) {
            $ident = self::mStr($r, 'ident', '');
            if ($ident === '') {
                continue;
            }
            $joined = self::mInt($r, 'joined_ts', 0);
            $sessions[] = [
                'ident'     => $ident,
                'nick'      => self::mStr($r, 'nick', ''),
                'is_member' => self::mBool($r, 'is_member'),
                'type'      => self::mBool($r, 'is_member')
                    ? $this->t->t('chat.admin.type_member')
                    : $this->t->t('chat.admin.type_guest'),
                'role'      => $this->roleLabel(self::mInt($r, 'role', UserGroup::GUEST->value)),
                'ip'        => self::mStr($r, 'ip_str', ''),
                'status'    => $this->statusLabel(self::mInt($r, 'status', 0)),
                'joined'    => $joined > 0 ? date($tsFmt, $joined) : '',
            ];
        }

        $this->ctx->set('sessions',      $sessions);
        $this->ctx->set('has_sessions',  $sessions !== []);
        $this->ctx->set('session_count', count($sessions));
        $this->ctx->set('room_topic',    $this->chat->effectiveTopic());
        $this->ctx->set('config_url',    $this->urlGen->toPage($this->t->t('WORDING_ADMIN_CONFIG_CHAT')));
        // R12: only ADMIN reaches the chat CONFIG page now (MOD lost admin.config.chat),
        // so hide the link for a MOD rather than show one that 403s.
        $this->ctx->set('can_config_chat', $this->gate->can(Permission::ADMIN_CONFIG_CHAT));

        // #132 report queue.
        $reportsResult = $this->reports->pending();
        $reportsResult->drainTo($this->collector);
        $reportRows = $reportsResult->isOk() ? $reportsResult->unwrap() : [];
        $reports    = [];
        foreach ($reportRows as $rr) {
            $mid = self::mInt($rr, 'message_id', 0);
            if ($mid <= 0) {
                continue;
            }
            $url = self::mStr($rr, 'first_url', '');
            $reports[] = [
                'message_id' => $mid,
                'nick'       => self::mStr($rr, 'nick', ''),
                'preview'    => mb_strimwidth(self::mStr($rr, 'content', ''), 0, 140, '…'),
                'count'      => self::mInt($rr, 'report_count', 0),
                'has_link'   => $url !== '',
            ];
        }
        $this->ctx->set('reports',      $reports);
        $this->ctx->set('has_reports',  $reports !== []);
        $this->ctx->set('report_count', count($reports));

        $this->setLabels();
        $this->nav->apply($this->ctx, 'admin');
        return $this->ok();
    }

    private function roleLabel(int $role): string
    {
        return match ($role) {
            UserGroup::ADMIN->value => $this->t->t('chat.role_admin'),
            UserGroup::MOD->value   => $this->t->t('chat.role_mod'),
            UserGroup::GUEST->value => $this->t->t('chat.admin.type_guest'),
            default                 => $this->t->t('chat.admin.type_member'),
        };
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            ChatPresenceService::STATUS_ACTIVE  => $this->t->t('chat.admin.status_active'),
            ChatPresenceService::STATUS_WAITING => $this->t->t('chat.admin.status_waiting'),
            ChatPresenceService::STATUS_KICKED  => $this->t->t('chat.admin.status_kicked'),
            ChatPresenceService::STATUS_PENDING => $this->t->t('chat.admin.status_pending'),
            default                             => '',
        };
    }

    private function setLabels(): void
    {
        foreach ([
            'chat_admin_heading'       => 'chat.admin.heading',
            'chat_admin_sessions_h'    => 'chat.admin.sessions',
            'chat_admin_sessions_none' => 'chat.admin.sessions_none',
            'reports_h'                => 'chat.admin.reports',
            'reports_none'             => 'chat.admin.reports_none',
            'report_col_msg'           => 'chat.admin.report_col_msg',
            'report_col_by'            => 'chat.admin.report_col_by',
            'report_col_count'         => 'chat.admin.report_col_count',
            'report_dismiss'           => 'chat.admin.report_dismiss',
            'report_block'             => 'chat.admin.report_block',
            'col_select'               => 'chat.admin.col_select',
            'col_nick'                 => 'chat.admin.col_nick',
            'col_type'                 => 'chat.admin.col_type',
            'col_role'                 => 'chat.admin.col_role',
            'col_ip'                   => 'chat.admin.col_ip',
            'col_status'               => 'chat.admin.col_status',
            'col_joined'               => 'chat.admin.col_joined',
            'chat_admin_kick_msg'      => 'chat.admin.kick_msg',
            'chat_admin_kick_sel'      => 'chat.admin.kick_selected',
            'chat_admin_all_guests'    => 'chat.admin.all_guests',
            'chat_admin_logout'        => 'chat.admin.logout_inactive',
            'chat_admin_clean_h'       => 'chat.admin.clean_heading',
            'chat_admin_clean_room'    => 'chat.clean_room',
            'chat_admin_clean_nick'    => 'chat.clean_nick',
            'chat_admin_clean_nick_ph' => 'chat.clean_nick_ph',
            'chat_admin_topic_h'       => 'chat.admin.topic_heading',
            'chat_admin_set_topic'     => 'chat.set_topic',
            'chat_admin_topic_ph'      => 'chat.topic_ph',
            'chat_admin_broadcast_h'   => 'chat.admin.broadcast_heading',
            'chat_admin_broadcast'     => 'chat.admin.broadcast',
            'chat_admin_broadcast_ph'  => 'chat.admin.broadcast_ph',
            'chat_admin_config_link'   => 'chat.admin.config_link',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    private function selfUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CHAT_ADMIN'));
    }
}
