<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatPmService;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatService;
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
use function AstrX\Support\langDir;

/**
 * The auto-refreshing message pane, served as the <iframe> body of the chat
 * shell. Page row: WORDING_CHAT_STREAM, template=0.
 *
 * The ONLY part of the chat that reloads. It emits its own HTML document — but,
 * unlike a bare frame, it inlines the ACTIVE THEME stylesheet and wraps its body
 * in the theme's own `#wrap`/`#main` containers, so the pane reads exactly like
 * the rest of the site (right colours, right font) on every theme instead of
 * black-on-black. A `<meta http-equiv="refresh">` re-fetches just this iframe.
 *
 * Content: the single room's public messages AND this viewer's private messages,
 * merged into one time-ordered feed (le-chat shows PMs inline). Each public
 * message carries a delete control when the viewer may remove it. Message bodies
 * are pre-rendered to safe HTML by the service and emitted raw; everything else
 * is escaped. Frames never redirect — a viewer who is not an active participant
 * gets a small "join the chat" document instead.
 */
final class ChatStreamController extends AbstractController
{
    private const FORM = 'chat_stream';

    public function __construct(
        DiagnosticsCollector                 $collector,
        private readonly Request             $request,
        private readonly ChatService         $chat,
        private readonly ChatPmService       $pm,
        private readonly ChatPresenceService $presence,
        private readonly ChatSettingsService $settings,
        private readonly ChatConfig          $config,
        private readonly ThemeService        $themeService,
        private readonly CsrfHandler         $csrf,
        private readonly PrgHandler          $prg,
        private readonly UrlGenerator        $urlGen,
        private readonly Translator          $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken);
            Response::redirect($this->streamUrl())->send()->drainTo($this->collector);
            exit;
        }

        $this->renderStream();
        return $this->ok();
    }

    // -------------------------------------------------------------------------
    // POST handling (delete a message)
    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        if (self::mStr($posted, 'action', '') === 'delete') {
            $id = self::mInt($posted, 'id', 0);
            if ($id > 0) {
                $this->chat->deleteMessage($id)->drainTo($this->collector);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private function renderStream(): void
    {
        $refresh  = $this->refreshSecs();
        $identity = $this->presence->identity();

        if ($identity === null || !$this->isActive($identity->ident)) {
            $joinUrl = $this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN'));
            $body    = '<p class="chat-empty"><a href="' . $this->esc($joinUrl) . '" target="_top">'
                . $this->esc($this->t->t('chat.join_prompt')) . '</a></p>';
            $this->emitDoc($body, $refresh);
        }

        $this->presence->heartbeat($identity->ident)->drainTo($this->collector);

        $settings       = $this->settings->effective($identity->ident);
        $showTimestamps = self::mBool($settings, 'show_timestamps');

        // Per-user message order (null = follow the room default) and personal
        // font / size / background applied on top of the theme.
        $sortDir     = $settings['sort_dir'] ?? null;
        $newestFirst = $sortDir === null ? $this->config->newestFirst() : ($sortDir === 1);
        $personalCss = ChatStyles::personalFrameCss(
            is_string($settings['font_family'] ?? null) ? (string) $settings['font_family'] : '',
            self::mInt($settings, 'font_size', 13),
            is_string($settings['bg_color'] ?? null) ? (string) $settings['bg_color'] : '',
        );
        $tz = is_string($settings['timezone'] ?? null) ? (string) $settings['timezone'] : '';

        $messagesResult = $this->chat->messages();
        $messagesResult->drainTo($this->collector);
        $publicRows = $messagesResult->isOk() ? $messagesResult->unwrap() : [];

        // Merge in this viewer's private messages so they appear inline.
        $pmRows = [];
        if ($this->config->allowPm()) {
            $pmResult = $this->pm->inbox($identity->ident);
            $pmResult->drainTo($this->collector);
            $pmRows = $pmResult->isOk() ? $pmResult->unwrap() : [];
        }

        $this->emitDoc($this->renderFeed($publicRows, $pmRows, $showTimestamps, $newestFirst, $tz), $refresh, $personalCss);
    }

    /**
     * @param list<array<string,mixed>> $publicRows oldest-first public messages
     * @param list<array<string,mixed>> $pmRows      this viewer's PM lines
     */
    private function renderFeed(array $publicRows, array $pmRows, bool $showTimestamps, bool $newestFirst, string $tz): string
    {
        /** @var list<array{kind:string, ts:int, row:array<string,mixed>}> $items */
        $items = [];
        foreach ($publicRows as $r) {
            $items[] = ['kind' => 'msg', 'ts' => self::mInt($r, 'created_ts', 0), 'row' => $r];
        }
        foreach ($pmRows as $r) {
            $items[] = ['kind' => 'pm', 'ts' => self::mInt($r, 'created_ts', 0), 'row' => $r];
        }
        // Admin greeting / MOTD, shown above the feed (and on an empty room).
        $greeting     = $this->config->greetingMessage();
        $greetingHtml = $greeting !== ''
            ? '<div class="chat-greeting">' . $this->esc($greeting) . '</div>'
            : '';

        if ($items === []) {
            return $greetingHtml . '<p class="chat-empty">' . $this->esc($this->t->t('chat.no_messages')) . '</p>';
        }

        usort($items, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        if ($newestFirst) {
            $items = array_reverse($items);
        }

        $prgId        = $this->prg->createId($this->streamUrl());
        $token        = $this->csrf->generate(self::FORM);
        $deleteLabel  = $this->t->t('chat.delete');
        $linkProfiles = $this->config->namesLinkToProfile();
        $tsFormat     = $this->config->timestampFormat();
        $profileBase  = $this->urlGen->toPage($this->t->t('WORDING_PROFILE'));
        $pmTag        = $this->t->t('chat.pm_tag');
        $fromWord     = $this->t->t('chat.pm.from');
        $toWord       = $this->t->t('chat.pm.to');
        $ignored      = $this->presence->ignoredNicks();   // lowercased nicks the viewer muted

        $out = $greetingHtml . '<ul class="chat-messages">';
        foreach ($items as $item) {
            $row      = $item['row'];
            $timeHtml = '';
            if ($showTimestamps) {
                $ts = self::mInt($row, 'created_ts', 0);
                if ($ts > 0) {
                    $timeHtml = '<span class="chat-time">' . $this->esc($this->fmtTime($ts, $tsFormat, $tz)) . '</span> ';
                }
            }

            if ($item['kind'] === 'pm') {
                $incoming = self::mBool($row, 'incoming');
                $who      = $incoming ? self::mStr($row, 'from_nick', '') : self::mStr($row, 'to_nick', '');
                // Hide incoming PMs from anyone the viewer has ignored.
                if ($incoming && in_array(strtolower($who), $ignored, true)) {
                    continue;
                }
                $dir      = $incoming ? $fromWord : $toWord;
                $style    = ChatStyles::colorStyle(self::mStr($row, 'color', ''));
                $out .=
                    '<li class="chat-msg chat-pm">'
                    . $timeHtml
                    . '<span class="chat-pm-tag">' . $this->esc($pmTag) . ' ' . $this->esc($dir) . ' '
                    . '<span class="chat-user"' . $style . '>' . $this->esc($who) . '</span>:</span> '
                    . '<span class="chat-body">' . self::mStr($row, 'html', '') . '</span>'
                    . '</li>';
                continue;
            }

            $id       = self::mInt($row, 'id', 0);
            $isMember = self::mBool($row, 'is_member');
            if ($isMember) {
                $display = self::mStr($row, 'user_display_name', '');
                if ($display === '') {
                    $display = self::mStr($row, 'nick', '');
                }
            } else {
                $display = self::mStr($row, 'nick', '');
            }

            // Moderator broadcast — a prominent announcement rendered from the
            // moderator's own (BBCode) text; bypasses the ignore list and colours.
            if (self::mStr($row, 'type', 'user') === 'broadcast') {
                $out .= '<li class="chat-msg chat-broadcast">'
                    . $timeHtml
                    . '<span class="chat-broadcast-tag">' . $this->esc($this->t->t('chat.broadcast_tag')) . '</span> '
                    . '<span class="chat-body">' . self::mStr($row, 'html', '') . '</span>'
                    . '</li>';
                continue;
            }

            // Hide public messages authored by anyone the viewer has ignored.
            if (in_array(strtolower($display), $ignored, true)) {
                continue;
            }

            // System line (join/leave/moderation) — localised per viewer; the
            // body is a language-neutral token, never user text. No colour/delete.
            if (self::mStr($row, 'type', 'user') === 'system') {
                $phrase = match (self::mStr($row, 'content', '')) {
                    'leave'  => $this->t->t('chat.sys.leave'),
                    'kicked' => $this->t->t('chat.sys.kicked'),
                    'banned' => $this->t->t('chat.sys.banned'),
                    'purged' => $this->t->t('chat.sys.purged'),
                    default  => $this->t->t('chat.sys.join'),
                };
                $out .= '<li class="chat-msg chat-system">'
                    . $timeHtml
                    . '<span class="chat-sep">*</span> '
                    . '<span class="chat-user">' . $this->esc($display) . '</span> '
                    . '<span class="chat-body">' . $this->esc($phrase) . '</span>'
                    . '</li>';
                continue;
            }

            $style    = ChatStyles::colorStyle(self::mStr($row, 'color', ''));
            $nameHtml = $this->authorHtml($display, $isMember, self::mStr($row, 'user_id', ''), $style, $linkProfiles, $profileBase);
            $bodyHtml = self::mStr($row, 'html', '');
            $isAction = self::mStr($row, 'type', 'user') === 'action';

            $deleteHtml = '';
            if ($this->chat->canDeleteMessage($row)) {
                $deleteHtml =
                    '<form method="post" class="chat-del">'
                    . '<input type="hidden" name="prg_id" value="' . $this->esc($prgId) . '">'
                    . '<input type="hidden" name="_csrf" value="' . $this->esc($token) . '">'
                    . '<input type="hidden" name="action" value="delete">'
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<button type="submit">' . $this->esc($deleteLabel) . '</button>'
                    . '</form>';
            }

            if ($isAction) {
                // Emote: "* nick does something" — no colon, italicised via CSS.
                $out .=
                    '<li class="chat-msg chat-action" id="m' . $id . '">'
                    . $timeHtml
                    . '<span class="chat-sep">*</span> '
                    . $nameHtml . ' '
                    . '<span class="chat-body"' . $style . '>' . $bodyHtml . '</span>'
                    . ($deleteHtml !== '' ? ' ' . $deleteHtml : '')
                    . '</li>';
            } else {
                $out .=
                    '<li class="chat-msg" id="m' . $id . '">'
                    . $timeHtml
                    . $nameHtml
                    . '<span class="chat-sep">:</span> '
                    . '<span class="chat-body"' . $style . '>' . $bodyHtml . '</span>'
                    . ($deleteHtml !== '' ? ' ' . $deleteHtml : '')
                    . '</li>';
            }
        }
        $out .= '</ul>';

        return $out;
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

    private function isActive(string $ident): bool
    {
        $r = $this->presence->presence($ident);
        $p = $r->isOk() ? $r->unwrap() : null;
        return $p !== null
            && self::mInt($p, 'status', ChatPresenceService::STATUS_WAITING) === ChatPresenceService::STATUS_ACTIVE;
    }

    private function refreshSecs(): int
    {
        $requested = self::queryInt($this->request, 'refresh', $this->config->defaultRefreshSecs());
        $floor     = max(1, $this->config->minRefreshSecs());
        $ceil      = max($floor, $this->config->maxRefreshSecs());
        return max($floor, min($ceil, $requested));
    }

    private function streamUrl(): string
    {
        return $this->urlGen->toPage(
            $this->t->t('WORDING_CHAT_STREAM'),
            ['refresh' => (string) $this->refreshSecs()],
        );
    }

    /**
     * Emit the auto-refreshing pane: the active theme stylesheet + the chat CSS,
     * with the body wrapped in the theme's own containers so it inherits the
     * theme's colours. Hard-stops (raw-bytes controller).
     */
    private function emitDoc(string $body, int $refresh, string $personalCss = ''): never
    {
        $title    = $this->esc($this->t->t('chat.messages'));
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

    /** Format a timestamp in the viewer's timezone, falling back to server-local. */
    private function fmtTime(int $ts, string $fmt, string $tz): string
    {
        if ($tz !== '' && ChatStyles::isValidTimezone($tz)) {
            try {
                return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($tz))->format($fmt);
            } catch (\Exception) {
                // fall through to server-local formatting
            }
        }
        return date($fmt, $ts);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
