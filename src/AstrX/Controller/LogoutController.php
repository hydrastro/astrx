<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Response;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\User\UserSession;

/**
 * Immediately destroys the user session and redirects to the main page.
 * Logout is a GET action — no CSRF needed (logging out is not destructive).
 */
final class LogoutController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector        $collector,
        private readonly UserSession $session,
        private readonly UrlGenerator $urlGen,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        $this->session->logout();
        session_destroy();

        Response::redirect($this->urlGen->toPage($this->t->t('WORDING_MAIN')))
            ->send()
            ->drainTo($this->collector);

        exit;
    }
}