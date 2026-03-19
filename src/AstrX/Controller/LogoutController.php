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
    private const TOKEN_KEY = '_logout_token';

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

        $provided = (string) ($this->request->query()->get('_lt') ?? '');
        $expected = $this->getOrCreateToken();

        if ($provided === '' || !hash_equals($expected, $provided)) {
            // No token or wrong token — redirect silently (don't log out)
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // Consume the token before destroying the session
        unset($_SESSION[self::TOKEN_KEY]);

        $this->session->logout();
        session_destroy();

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
            ->send()->drainTo($this->collector);
        exit;
    }

    /**
     * Returns the logout token for the current session, creating it if absent.
     * The token is a URL-safe 32-byte hex string stored in the session.
     */
    public static function getOrCreateToken(): string
    {
        if (!isset($_SESSION[self::TOKEN_KEY]) || !is_string($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }
}