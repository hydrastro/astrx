<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatModerationService;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatSettingsService;
use AstrX\Chat\ChatStyles;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Theme\ThemeService;
use AstrX\User\UserGroup;
use function AstrX\Support\langDir;

/**
 * The auto-refreshing online-users pane, served as an <iframe> body of the chat
 * shell. Page row: WORDING_CHAT_USERS, template=0.
 *
 * Renders the live roster — each participant's nick (members bold + underline
 * and linked to their profile, guests plain), a role marker for staff, and,
 * for a viewer holding CHAT_MODERATE, per-user kick / mute / ban forms. Like the
 * stream frame it emits its own minimal auto-refreshing document and never uses
 * the site template.
 *
 * Two jobs:
 *   1. GET  → render the roster + the online count.
 *   2. POST (kick/mute/ban) → intercepted by ContentManager into the PRG,
 *             replayed here as ?_prg=token; we verify CSRF, dispatch to
 *             ChatModerationService (which re-checks CHAT_MODERATE itself), then
 *             redirect back to the clean users URL so the iframe refreshes.
 *
 * Frame gating is presence-based: a viewer with no chat identity, or one who is
 * not an active participant, gets a minimal "join the chat" document instead.
 */
final class ChatUsersController extends AbstractController
{
    private const FORM = 'chat_users';

    /** Fixed durations for the compact, no-JS mod controls (seconds). */
    private const MUTE_SECS    = 300;
    private const BAN_DURATION = 3600;

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly Request               $request,
        private readonly ChatPresenceService   $presence,
        private readonly ChatModerationService $mod,
        private readonly ChatConfig            $config,
        private readonly ChatSettingsService   $settings,
        private readonly ThemeService          $themeService,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        // PRG replay: a kick/mute/ban was posted, stored, and redirected here.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken);
            Response::redirect($this->usersUrl())
                ->send()->drainTo($this->collector);
            exit;
        }

        // Renders the iframe document and hard-stops (never returns).
        $this->renderUsers();

        // Unreachable — renderUsers() exits — but keeps the signature honest.
        return $this->ok();
    }

    // -------------------------------------------------------------------------
    // POST handling (kick / mute / ban)
    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        $action = self::mStr($posted, 'action', '');

        // Ignore is a personal, per-viewer toggle (NOT moderation): any active
        // participant may hide another participant's messages for themselves. It
        // is keyed by nick and needs no elevated permission.
        if ($action === 'ignore') {
            $this->presence->toggleIgnore(self::mStr($posted, 'nick', ''));
            return;
        }

        $ident = self::mStr($posted, 'ident', '');
        if ($ident === '') {
            return;
        }

        // Every branch re-checks CHAT_MODERATE inside ChatModerationService, so a
        // forged POST from a non-moderator is denied there, not here.
        switch ($action) {
            case 'kick':
                $this->mod->kick($ident, $this->t->t('chat.kick_penalty_reason'))->drainTo($this->collector);
                break;
            case 'mute':
                $this->mod->mute($ident, self::mInt($posted, 'secs', self::MUTE_SECS))
                    ->drainTo($this->collector);
                break;
            case 'ban':
                $this->mod->ban(
                    $ident,
                    self::mInt($posted, 'duration', self::BAN_DURATION),
                    self::mStr($posted, 'reason', ''),
                )->drainTo($this->collector);
                break;
            case 'purge':
                $this->mod->purge($ident)->drainTo($this->collector);
                break;
            case 'approve':
                $this->mod->approve($ident)->drainTo($this->collector);
                break;
            case 'deny':
                $this->mod->deny($ident)->drainTo($this->collector);
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderUsers(): void
    {
        $refresh  = $this->refreshSecs();
        $identity = $this->presence->identity();

        // Frame gating: only an active participant sees the roster.
        if ($identity === null || !$this->isActive($identity->ident)) {
            $joinUrl = $this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN'));
            $body    = '<p class="chat-empty"><a href="' . $this->esc($joinUrl) . '" target="_top">'
                . $this->esc($this->t->t('chat.join_prompt')) . '</a></p>';
            $this->emitDoc($body, $refresh);
        }

        $this->presence->heartbeat($identity->ident)->drainTo($this->collector);

        $canMod = $this->gate->can(Permission::CHAT_MODERATE);

        // Staff also see incognito users (so they remain moderatable).
        $usersResult = $this->presence->onlineUsers($canMod);
        $usersResult->drainTo($this->collector);
        $users = $usersResult->isOk() ? $usersResult->unwrap() : [];

        // Moderators additionally see the approval queue (guests awaiting entry).
        $pendingHtml = '';
        if ($canMod) {
            $pendingResult = $this->presence->pending();
            $pendingResult->drainTo($this->collector);
            $pending = $pendingResult->isOk() ? $pendingResult->unwrap() : [];
            if ($pending !== []) {
                $pendingHtml = $this->renderPending($pending);
            }
        }

        $eff         = $this->settings->effective($identity->ident);
        $personalCss = ChatStyles::personalFrameCss(
            is_string($eff['font_family'] ?? null) ? (string) $eff['font_family'] : '',
            self::mInt($eff, 'font_size', 16),
            is_string($eff['bg_color'] ?? null) ? (string) $eff['bg_color'] : '',
        );

        $this->emitDoc($pendingHtml . $this->renderRoster($users, $identity->ident), $refresh, $personalCss);
    }

    /**
     * @param list<array<string,mixed>> $users
     */
    private function renderRoster(array $users, string $viewerIdent): string
    {
        $canModerate  = $this->gate->can(Permission::CHAT_MODERATE);
        $linkProfiles = $this->config->namesLinkToProfile();
        $profileBase  = $this->urlGen->toPage($this->t->t('WORDING_PROFILE'));

        $adminTag = $this->t->t('chat.role_admin');
        $modTag   = $this->t->t('chat.role_mod');

        // One PRG target + one CSRF token cover every mod form on this render.
        $prgId = $this->prg->createId($this->usersUrl());
        $token = $this->csrf->generate(self::FORM);

        $out = '<p class="chat-roster-head">' . $this->esc($this->t->t('chat.online'))
            . ': ' . count($users) . '</p>';
        $out .= '<ul class="chat-users-list">';

        foreach ($users as $u) {
            $ident    = self::mStr($u, 'ident', '');
            $isMember = self::mBool($u, 'is_member');
            $role     = self::mInt($u, 'role', UserGroup::GUEST->value);

            $style    = ChatStyles::colorStyle(self::mStr($u, 'color', ''));
            $nameHtml = $this->authorHtml(
                self::mStr($u, 'nick', ''),
                $isMember,
                self::mStr($u, 'user_id', ''),
                $style,
                $linkProfiles,
                $profileBase,
            );

            $roleHtml = '';
            if ($role === UserGroup::ADMIN->value) {
                $roleHtml = ' <span class="chat-role chat-admin">' . $this->esc($adminTag) . '</span>';
            } elseif ($role === UserGroup::MOD->value) {
                $roleHtml = ' <span class="chat-role chat-mod">' . $this->esc($modTag) . '</span>';
            }

            $actionsHtml = '';
            if ($canModerate && $ident !== '' && $ident !== $viewerIdent) {
                $actionsHtml = $this->modForms($ident, $prgId, $token);
            }

            // Personal ignore toggle — shown for everyone (not just moderators),
            // for every roster row except the viewer's own.
            $ignoreHtml = '';
            if ($ident !== '' && $ident !== $viewerIdent) {
                $nick    = self::mStr($u, 'nick', '');
                $label   = $this->presence->isIgnored($nick)
                    ? $this->t->t('chat.unignore')
                    : $this->t->t('chat.ignore');
                $ignoreHtml = ' <span class="chat-mod-actions chat-personal-actions">'
                    . '<form method="post" class="chat-mod chat-ignore">'
                    . '<input type="hidden" name="prg_id" value="' . $this->esc($prgId) . '">'
                    . '<input type="hidden" name="_csrf" value="' . $this->esc($token) . '">'
                    . '<input type="hidden" name="action" value="ignore">'
                    . '<input type="hidden" name="nick" value="' . $this->esc($nick) . '">'
                    . '<button type="submit">' . $this->esc($label) . '</button>'
                    . '</form></span>';
            }

            $out .= '<li>' . $nameHtml . $roleHtml . $actionsHtml . $ignoreHtml . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }

    /**
     * The moderator-approval queue: each awaiting guest with Approve / Deny
     * forms. Rendered above the roster for moderators only, and only when the
     * queue is non-empty.
     *
     * @param list<array<string,mixed>> $pending
     */
    private function renderPending(array $pending): string
    {
        $prgId = $this->prg->createId($this->usersUrl());
        $token = $this->csrf->generate(self::FORM);

        $out = '<div class="chat-pending"><p class="chat-roster-head">'
            . $this->esc($this->t->t('chat.pending_heading')) . ': ' . count($pending)
            . '</p><ul class="chat-pending-list">';
        foreach ($pending as $g) {
            $ident = self::mStr($g, 'ident', '');
            if ($ident === '') {
                continue;
            }
            $hidden = '<input type="hidden" name="prg_id" value="' . $this->esc($prgId) . '">'
                . '<input type="hidden" name="_csrf" value="' . $this->esc($token) . '">'
                . '<input type="hidden" name="ident" value="' . $this->esc($ident) . '">';
            $approve = '<form method="post" class="chat-mod chat-approve">' . $hidden
                . '<input type="hidden" name="action" value="approve">'
                . '<button type="submit">' . $this->esc($this->t->t('chat.approve')) . '</button></form>';
            $deny = '<form method="post" class="chat-mod chat-deny">' . $hidden
                . '<input type="hidden" name="action" value="deny">'
                . '<button type="submit">' . $this->esc($this->t->t('chat.deny')) . '</button></form>';
            $out .= '<li><span class="chat-user chat-guest">' . $this->esc(self::mStr($g, 'nick', ''))
                . '</span> <span class="chat-mod-actions">' . $approve . $deny . '</span></li>';
        }
        return $out . '</ul></div>';
    }

    /** The kick / mute / ban forms for one roster row. */
    private function modForms(string $ident, string $prgId, string $token): string
    {
        $hidden = '<input type="hidden" name="prg_id" value="' . $this->esc($prgId) . '">'
            . '<input type="hidden" name="_csrf" value="' . $this->esc($token) . '">'
            . '<input type="hidden" name="ident" value="' . $this->esc($ident) . '">';

        $kick = '<form method="post" class="chat-mod chat-kick">'
            . $hidden
            . '<input type="hidden" name="action" value="kick">'
            . '<button type="submit">' . $this->esc($this->t->t('chat.kick')) . '</button>'
            . '</form>';

        $mute = '<form method="post" class="chat-mod chat-mute">'
            . $hidden
            . '<input type="hidden" name="action" value="mute">'
            . '<input type="hidden" name="secs" value="' . self::MUTE_SECS . '">'
            . '<button type="submit">' . $this->esc($this->t->t('chat.mute')) . '</button>'
            . '</form>';

        $ban = '<form method="post" class="chat-mod chat-ban">'
            . $hidden
            . '<input type="hidden" name="action" value="ban">'
            . '<input type="hidden" name="duration" value="' . self::BAN_DURATION . '">'
            . '<input type="text" name="reason" class="input" autocomplete="off" placeholder="'
            . $this->esc($this->t->t('chat.ban_reason')) . '">'
            . '<button type="submit">' . $this->esc($this->t->t('chat.ban')) . '</button>'
            . '</form>';

        $purge = '<form method="post" class="chat-mod chat-purge">'
            . $hidden
            . '<input type="hidden" name="action" value="purge">'
            . '<button type="submit">' . $this->esc($this->t->t('chat.purge')) . '</button>'
            . '</form>';

        return ' <span class="chat-mod-actions">' . $kick . $mute . $purge . $ban . '</span>';
    }

    /**
     * A member is bold + underline and (when configured) linked to their profile;
     * a guest is plain. $style is a pre-built ` style="..."` attribute or ''.
     */
    private function authorHtml(
        string $display,
        bool   $isMember,
        string $hexUserId,
        string $style,
        bool   $linkProfiles,
        string $profileBase,
    ): string {
        $inner = $this->esc($display);

        if ($isMember) {
            if ($linkProfiles && $hexUserId !== '') {
                $url = $profileBase . '?uid=' . rawurlencode($hexUserId);
                return '<a class="chat-user chat-member" href="' . $this->esc($url) . '"' . $style . ' target="_top">'
                    . $inner . '</a>';
            }
            return '<span class="chat-user chat-member"' . $style . '>' . $inner . '</span>';
        }

        return '<span class="chat-user chat-guest"' . $style . '>' . $inner . '</span>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** True when the identity holds an ACTIVE presence row. */
    private function isActive(string $ident): bool
    {
        $r = $this->presence->presence($ident);
        $p = $r->isOk() ? $r->unwrap() : null;
        return $p !== null
            && self::mInt($p, 'status', ChatPresenceService::STATUS_WAITING) === ChatPresenceService::STATUS_ACTIVE;
    }

    /** Refresh cadence: an optional ?refresh=N, clamped to the configured range. */
    private function refreshSecs(): int
    {
        $requested = self::queryInt($this->request, 'refresh', $this->config->defaultRefreshSecs());
        $floor     = max(1, $this->config->minRefreshSecs());
        $ceil      = max($floor, $this->config->maxRefreshSecs());
        return max($floor, min($ceil, $requested));
    }

    private function usersUrl(): string
    {
        return $this->urlGen->toPage(
            $this->t->t('WORDING_CHAT_USERS'),
            ['refresh' => (string) $this->refreshSecs()],
        );
    }

    /** Emit the minimal auto-refreshing iframe document and hard-stop. */
    private function emitDoc(string $body, int $refresh, string $personalCss = ''): never
    {
        $title    = $this->esc($this->t->t('chat.users_title'));
        $themeCss = $this->themeService->activeStylesheetContent();
        $chatCss  = ChatStyles::frameCss();

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Frame-Options: SAMEORIGIN');
        }

        echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta http-equiv=\"refresh\" content=\"{$refresh}\">\n"
            . "<title>{$title}</title>\n"
            . "<style>\n{$themeCss}\n{$chatCss}\n{$personalCss}\n</style>\n</head>\n"
            . "<body><div id=\"wrap\"><div id=\"main\">\n{$body}\n</div></div>\n</body>\n</html>";
        exit;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
