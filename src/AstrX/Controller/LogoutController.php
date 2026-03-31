<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\User\UserSession;

use AstrX\Http\Request;

/**
 * Destroys the session and redirects to the main page.
 *
 * Uses a URL token to prevent CSRF: the link in the template must include
 * ?_lt=<token> where the token is a HMAC of the session ID stored in the
 * session itself. A bare GET to /en/logout without the token does nothing.
 *
 * The token is single-use — it is cleared on successful logout so replay
 * after session destruction is impossible.
 */
final class LogoutController extends AbstractController
{

    public function __construct(
        DiagnosticsCollector        $collector,
        private readonly UserSession  $session,
        private readonly UrlGenerator $urlGen,
        private readonly Translator   $t,
        private readonly Request      $request,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if (!$this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $ltRaw = $this->request->query()->get('_lt');
        $provided = is_string($ltRaw) ? $ltRaw : '';
        $expected = $this->session->logoutToken();

        if ($provided === '' || !hash_equals($expected, $provided)) {
            // No token or wrong token — redirect silently (don't log out)
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // Consume the token before destroying the session
        $this->session->consumeLogoutToken();

        $this->session->logout();
        session_destroy();

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

}
