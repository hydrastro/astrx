<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatService;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Chat waiting room — page file_name `chat_wait`, template=1.
 *
 * A guest who joined the roster while a waiting period is configured lands here.
 * Unlike a bare interstitial, this is a NORMAL templated page: it renders inside
 * the site chrome (header, nav, active theme) exactly like every other page, so
 * it no longer looks foreign. The countdown auto-advances via an HTTP
 * `Refresh: 2` header (no JavaScript) — each reload re-evaluates the state.
 *
 * State machine (agrees with the chat shell):
 *   - no identity                        → redirect to the login/entry page.
 *   - no presence row / already active   → redirect to the chat shell.
 *   - waited long enough                 → promote to ACTIVE, redirect to shell.
 *   - otherwise                          → render the themed countdown + refresh.
 */
final class ChatWaitController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly ChatPresenceService    $presence,
        private readonly ChatConfig             $config,
        private readonly ChatService            $chat,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        $identity = $this->presence->identity();
        if ($identity === null) {
            $this->redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN')));
        }

        $presenceResult = $this->presence->presence($identity->ident);
        $presenceResult->drainTo($this->collector);
        $row = $presenceResult->isOk() ? $presenceResult->unwrap() : null;

        $chatUrl = $this->urlGen->toPage($this->t->t('WORDING_CHAT'));

        // No roster row, or already admitted → the shell owns them now.
        if ($row === null || self::mInt($row, 'status', 0) === ChatPresenceService::STATUS_ACTIVE) {
            $this->redirect($chatUrl);
        }

        $status = self::mInt($row, 'status', ChatPresenceService::STATUS_WAITING);

        // Denied / kicked while waiting → back to the entry page.
        if ($status === ChatPresenceService::STATUS_KICKED) {
            $this->redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN')));
        }

        // Awaiting moderator approval — no countdown, never self-promotes. Bump the
        // heartbeat so the guest stays in the moderator's queue, and refresh; when a
        // moderator admits them their status flips to ACTIVE and the next load hands
        // off to the shell.
        if ($status === ChatPresenceService::STATUS_PENDING) {
            $this->presence->heartbeat($identity->ident)->drainTo($this->collector);
            if (!headers_sent()) {
                header('Refresh: 3');
            }
            $this->ctx->set('wait_heading', $this->t->t('chat.wait_heading'));
            $this->ctx->set('wait_message', $this->t->t('chat.wait_approval'));
            return $this->ok();
        }

        $waitSeconds = $this->config->waitingRoomSeconds();
        $since       = $this->presence->secondsSinceJoin();

        // Waited long enough → promote to ACTIVE and hand off to the shell.
        if ($since >= $waitSeconds) {
            $this->presence->setActive($identity->ident)->drainTo($this->collector);
            $this->chat->postSystem($identity->nick, 'join')->drainTo($this->collector);
            $this->redirect($chatUrl);
        }

        // Still waiting — themed countdown page that reloads itself.
        if (!headers_sent()) {
            header('Refresh: 2');
        }

        $this->ctx->set('wait_heading',        $this->t->t('chat.wait_heading'));
        $this->ctx->set('wait_message',        $this->t->t('chat.wait_message'));
        $this->ctx->set('wait_seconds_label',  $this->t->t('chat.wait_seconds'));
        $this->ctx->set('wait_remaining',      max(0, $waitSeconds - $since));
        $this->ctx->set('wait_show_count',     true);

        // The "enter now" skip is only offered when the wait is NOT mandatory.
        // Under a mandatory wait the link is withheld, so the guest cannot short-
        // circuit the countdown; the shell enforces the same rule server-side.
        if (!$this->config->waitingRoomMandatory()) {
            $this->ctx->set('wait_continue_url',   $chatUrl);
            $this->ctx->set('wait_continue_label', $this->t->t('chat.wait_continue'));
        }

        return $this->ok();
    }

    /** Send a redirect and stop; never returns. */
    private function redirect(string $url): never
    {
        Response::redirect($url)->send()->drainTo($this->collector);
        exit;
    }
}
