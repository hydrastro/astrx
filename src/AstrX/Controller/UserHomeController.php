<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;

/**
 * User home page — shown after login.
 * Redirects to login if not logged in.
 */
final class UserHomeController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly UserSession           $session,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if (!$this->session->isLoggedIn()) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        $this->ctx->set('user_welcome_heading', $this->t->t('user.home.heading'));
        $this->ctx->set('user_welcome_body',    $this->t->t('user.home.body'));
        $this->ctx->set('username',             $this->session->username());

        // Build section links dynamically — sorted alphabetically.
        // Add entries here (slug => label key) to have them appear on the home page.
        // Each slug must have a WORDING_{SLUG} translation key for URL resolution.
        $navPages = [
            'profile'  => 'user.home.profile_heading',
            'settings' => 'user.home.settings_heading',
            'logout'   => 'user.home.logout',
        ];
        $sections = [];
        foreach ($navPages as $slug => $labelKey) {
            $urlArgs = $slug === 'profile' ? ['uid' => $this->session->userId()] : [];
            $sections[] = [
                'url'  => $this->urlGen->toPage($this->t->t('WORDING_' . strtoupper($slug)), $urlArgs),
                'name' => $this->t->t($labelKey),
                'desc' => $this->t->t($labelKey . '.desc', fallback: ''),
            ];
        }
        usort($sections, fn($a, $b) => strcmp($a['name'], $b['name']));
        $this->ctx->set('user_sections', $sections);

        return $this->ok();
    }
}