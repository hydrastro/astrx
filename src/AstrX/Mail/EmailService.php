<?php
declare(strict_types=1);

namespace AstrX\Mail;

use AstrX\Config\InjectConfig;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\TemplateEngine;

/**
 * High-level email orchestration.
 *
 * Wraps the low-level Mailer with template rendering, i18n, and
 * type-safe composers for each kind of transactional email the
 * framework sends.
 *
 * Each compose method:
 *   1. Renders subject + body from translated strings and templates.
 *   2. Calls Mailer::send() with both text and HTML variants.
 *   3. Returns Result<bool> — ok(true) on success, err with diagnostics
 *      on failure. The caller decides what to do with failures (block
 *      the action, retry, surface a diagnostic, etc.).
 *
 * Templates live in resources/template/email/:
 *   - <kind>.html — HTML variant (Mustache)
 *   - <kind>.txt  — plain-text variant (Mustache, but most just use {{var}})
 *
 * Subject lines and any prose-rich text live in resources/lang/{en,it}/Email.{en,it}.php
 * so they can be edited without touching code.
 */
final class EmailService
{
    /** Base URL of the site (e.g. "https://example.com") used to build absolute links in emails. */
    private string $siteUrl = '';
    /** Public name of the site, used in greetings and signatures. */
    private string $siteName = 'AstrX';

    #[InjectConfig('site_url')]
    public function setSiteUrl(string $v): void  { $this->siteUrl  = rtrim($v, '/'); }
    #[InjectConfig('site_name')]
    public function setSiteName(string $v): void { $this->siteName = $v; }

    public function __construct(
        private readonly Mailer          $mailer,
        private readonly TemplateEngine  $templates,
        private readonly Translator      $t,
        private readonly UrlGenerator    $urlGen,
    ) {}

    // -------------------------------------------------------------------------
    // Public API — one method per email kind
    // -------------------------------------------------------------------------

    /**
     * Account-verification email.
     *
     * Sent right after registration. The link contains the token + the user's
     * hex id; clicking it lands on /<locale>/user with `_token` and `_uid`
     * query params, which the user account endpoint consumes and verifies.
     *
     * @return Result<bool>
     */
    public function sendVerificationEmail(
        string $toAddress,
        string $toName,
        string $username,
        string $token,
        string $userHexId,
    ): Result {
        return $this->compose(
            kind:       'verify_account',
            toAddress:  $toAddress,
            toName:     $toName,
            vars:       [
                'username' => $username,
                'site_name'=> $this->siteName,
                'site_url' => $this->siteUrl,
                'link'     => $this->buildTokenLink($token, $userHexId),
            ],
        );
    }

    /**
     * Password recovery email.
     *
     * Same link shape as verification — distinguished server-side by token_type.
     *
     * @return Result<bool>
     */
    public function sendPasswordResetEmail(
        string $toAddress,
        string $toName,
        string $username,
        string $token,
        string $userHexId,
    ): Result {
        return $this->compose(
            kind:       'password_reset',
            toAddress:  $toAddress,
            toName:     $toName,
            vars:       [
                'username' => $username,
                'site_name'=> $this->siteName,
                'site_url' => $this->siteUrl,
                'link'     => $this->buildTokenLink($token, $userHexId),
            ],
        );
    }

    /**
     * Generic notification email — admins to users, system announcements, etc.
     *
     * @return Result<bool>
     */
    public function sendNotificationEmail(
        string $toAddress,
        string $toName,
        string $subject,
        string $bodyText,
        string $bodyHtml = '',
    ): Result {
        $sendResult = $this->mailer->send(
            $toAddress,
            $toName,
            $subject,
            $bodyText,
            $bodyHtml,
        );
        return $sendResult->isOk()
            ? Result::ok(true)
            : Result::err(false, $sendResult->diagnostics());
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Render subject + bodies for the given email kind and send via Mailer.
     *
     * @param array<string,scalar> $vars
     * @return Result<bool>
     */
    private function compose(
        string $kind,
        string $toAddress,
        string $toName,
        array  $vars,
    ): Result {
        // Make sure the Email lang domain is loaded for this locale.
        $this->t->loadDomain($this->langDir(), 'Email');

        // Subject — comes from the lang catalog so it's editable in EN/IT
        // without touching templates.
        $subjectKey = 'email.' . $kind . '.subject';
        $subjectRaw = $this->t->t($subjectKey, fallback: '');
        if ($subjectRaw === '' || $subjectRaw === $subjectKey) {
            // Fall back to a generic subject so the email still goes out.
            $subjectRaw = $this->siteName;
        }
        $subject = $this->interpolate($subjectRaw, $vars);

        // Bodies — Mustache-rendered from email/<kind>.{html,txt}.
        $htmlResult = $this->templates->renderTemplate('email/' . $kind . '_html', $vars);
        $txtResult  = $this->templates->renderTemplate('email/' . $kind . '_txt',  $vars);
        $bodyHtml = $htmlResult->isOk() ? (string) $htmlResult->unwrap() : '';
        $bodyText = $txtResult->isOk()  ? (string) $txtResult->unwrap()  : '';

        // If both renders failed, we have nothing to send.
        if ($bodyHtml === '' && $bodyText === '') {
            return Result::err(false, Diagnostics::of(
                new Diagnostic\EmailTemplateMissingDiagnostic(
                    'astrx.email/template_missing',
                    DiagnosticLevel::ERROR,
                ),
            ));
        }
        // Plain-text part is required (good email practice). If only HTML
        // rendered, strip tags as a fallback so the email is still RFC-clean.
        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = trim(strip_tags($bodyHtml));
        }

        $sendResult = $this->mailer->send(
            $toAddress,
            $toName,
            $subject,
            $bodyText,
            $bodyHtml,
        );

        return $sendResult->isOk()
            ? Result::ok(true)
            : Result::err(false, $sendResult->diagnostics());
    }

    /**
     * Build the absolute link a user clicks to consume a token. The link
     * lands on /<locale>/user (the user account endpoint) which inspects
     * the _token and _uid query params and routes based on token_type.
     */
    private function buildTokenLink(string $token, string $userHexId): string
    {
        // Internally, urlGen produces relative paths. Prefix with the
        // configured site URL so the link is absolute in the email body.
        $relative = $this->urlGen->toPage($this->t->t('WORDING_USER')) .
                    '?_token=' . rawurlencode($token) .
                    '&_uid='   . rawurlencode($userHexId);
        return $this->siteUrl !== ''
            ? $this->siteUrl . $relative
            : $relative;
    }

    /**
     * Resolve {{var}} placeholders in a translated string. Used for subjects
     * where Mustache is overkill but we still want variable interpolation.
     *
     * @param array<string,scalar> $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{{' . $key . '}}', (string) $value, $out);
        }
        return $out;
    }

    /**
     * Resolve the lang directory the framework uses. Centralised so tests
     * can override if needed.
     */
    private function langDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/resources/lang';
        return is_dir($dir) ? $dir : (defined('LANG_DIR') ? (string) constant('LANG_DIR') : '');
    }
}
