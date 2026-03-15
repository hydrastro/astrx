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
        $this->ctx->set('user_profile_heading', $this->t->t('user.home.profile_heading'));
        $this->ctx->set('user_profile_text',    $this->t->t('user.home.profile_text'));
        $this->ctx->set('user_settings_heading',$this->t->t('user.home.settings_heading'));
        $this->ctx->set('user_settings_text',   $this->t->t('user.home.settings_text'));
        $this->ctx->set('username',             $this->session->username());
        $this->ctx->set('profile_url',          $this->urlGen->toPage($this->t->t('WORDING_PROFILE'), ['uid' => $this->session->userId()]));
        $this->ctx->set('settings_url',         $this->urlGen->toPage($this->t->t('WORDING_SETTINGS')));
        $this->ctx->set('logout_url',           $this->urlGen->toPage($this->t->t('WORDING_LOGOUT')));

        return $this->ok();
    }
}