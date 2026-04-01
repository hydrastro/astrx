<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticRenderer;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Renders the error page for any HTTP error status.
 *
 * Reads http.status.<code>.name  and  http.status.<code>.message from the
 * Http lang domain (Http.en.php / Http.it.php).
 *
 * In development/debug mode the collected diagnostics are also rendered
 * in a <details> block below the error message to aid debugging.
 */
final class ErrorController implements Controller
{
    public function __construct(
        private readonly DefaultTemplateContext $ctx,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly DiagnosticsCollector   $collector,
        private readonly Gate                   $gate,
        private readonly DiagnosticRenderer     $renderer,
    ) {}

    public function handle(): Result
    {
        $status = (int) http_response_code();
        if ($status === 0) {
            $status = 500;
        }
        $this->t->loadDomain(langDir(), "Http");

        $errorWord = ucfirst($this->t->t('error', fallback: 'Error'));
        $name      = $this->t->t('http.status.' . $status . '.name',    fallback: $errorWord);
        $message   = $this->t->t('http.status.' . $status . '.message', fallback: 'An error occurred.');

        $isClientError = $status >= 400 && $status < 500;
        $isServerError = $status >= 500;

        $this->ctx->set('title',          $errorWord . " " . $status . ' — ' . $name);
        $this->ctx->set('description',    $message);
        $this->ctx->set('keywords',       $errorWord . ' ' . $status);
        $this->ctx->set('error',          $errorWord);
        $this->ctx->set('error_code',     (string) $status);
        $this->ctx->set('error_name',     $name);
        $this->ctx->set('error_message',  $message);
        $this->ctx->set('error_contact_admin', $this->t->t('error.contact_admin', fallback: ''));
        $this->ctx->set('error_go_home',  $this->t->t('error.go_home',       fallback: 'Home'));
        $this->ctx->set('error_back',     $this->t->t('error.back',          fallback: 'Back'));
        $this->ctx->set('home_url',       $this->urlGen->toPage($this->t->t('WORDING_MAIN', fallback: 'main')));
        // Show "Go back" only for client errors where going back makes sense
        $this->ctx->set('error_show_back', $isClientError);

        // Show diagnostics panel to admins (always) or in dev mode (always).
        // In production non-admins never see internal details.
        $isAdmin   = $this->gate->can(Permission::ADMIN_ACCESS);
        $showDiag  = $isAdmin || $isServerError;

        if ($showDiag) {
            $diags = $this->collector->diagnostics();
            if (count($diags) > 0) {
                $rendered = [];
                foreach ($diags as $d) {
                    /** @var \AstrX\Result\DiagnosticInterface $d */
                    $text     = $this->renderer->render($d);
                    $level    = $d->level();
                    $cssClass = match (true) {
                        $level->value >= DiagnosticLevel::ERROR->value   => 'diag-error',
                        $level->value >= DiagnosticLevel::WARNING->value => 'diag-warning',
                        $level->value >= DiagnosticLevel::NOTICE->value  => 'diag-notice',
                        default                                           => 'diag-debug',
                    };
                    $rendered[] = ['message' => $text, 'css_class' => $cssClass];
                }
                $this->ctx->set('error_show_diagnostics', true);
                $this->ctx->set('error_diagnostics',      $rendered);
            } else {
                $this->ctx->set('error_show_diagnostics', false);
                $this->ctx->set('error_diagnostics',      []);
            }
        } else {
            $this->ctx->set('error_show_diagnostics', false);
            $this->ctx->set('error_diagnostics',      []);
        }

        return Result::ok(null);
    }
}
