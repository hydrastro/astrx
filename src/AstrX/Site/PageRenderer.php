<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Injector\Injector;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\TemplateEngine;

/**
 * Produces the response body for a page whose controllers have run:
 *
 *   - the JS-browser fragment (X-AstrX-JS-Browser: the /js/ runtime asks
 *     for the chrome/content fragments only);
 *   - the API's JSON (/api/<page>, only for api_enabled pages);
 *   - the full HTML document (the default);
 *   - nothing, for template=0 pages whose controller wrote its own bytes
 *     (then 204 only if truly nothing was emitted).
 *
 * The baseline security headers are already out when this runs.
 */
final class PageRenderer
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly Config               $config,
        private readonly DiagnosticsCollector $collector,
    ) {}

    /** @return HttpStatus|null the error status to render, or null when the response is done */
    public function render(Page $page, DefaultTemplateContext $ctx, Request $request, string $locale, float $startedAt): ?HttpStatus
    {
        if ($page->template) {
            $engineResult = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector);

            if (!$engineResult->isOk()) {
                return HttpStatus::INTERNAL_SERVER_ERROR;
            }

            /** @var TemplateEngine $engine */
            $engine = $engineResult->unwrap();

            $templateName = $page->templateFileName !== ''
                ? $page->templateFileName
                : $this->config->getConfigString('ContentManager', 'default_template', 'default');

            $ctx->resolveUrls();

            if ($page->comments) {
                $ctx->set('page_comments', true);
                $commentsPreResult = $engine->renderTemplate('partials/comments', $ctx->all())
                    ->drainTo($this->collector);
                if ($commentsPreResult->isOk()) {
                    $ctx->set('comments_html', $commentsPreResult->unwrap());
                }
            } else {
                $ctx->set('page_comments', false);
                $ctx->set('comments_html', '');
            }

            $ctx->finalise();

            // ── JS-browser fragment dispatch ────────────────────────────────
            // The /js/ runtime sends X-AstrX-JS-Browser when it browses the
            // canonical PHP pages. In that case we can skip the expensive outer
            // document shell and return only the chrome/content fragments the
            // runtime knows how to transplant (#header, navs, #message_bar,
            // #main, #footer). Normal browsers still receive the full layout.
            if (!$request->isApi() && $this->isJsBrowserContentRequest($request)) {
                $fragmentResult = $engine->renderTemplate('js_fragment', $ctx->all())
                    ->drainTo($this->collector);

                if (!$fragmentResult->isOk()) {
                    return HttpStatus::INTERNAL_SERVER_ERROR;
                }

                if (!headers_sent()) {
                    header('Content-Type: text/html; charset=utf-8');
                    header('Cache-Control: private, no-store');
                    header('Vary: X-AstrX-JS-Browser, Accept', false);
                    header('X-AstrX-JS-Browser: fragment');
                    // CSP / Referrer-Policy / nosniff / X-Frame-Options /
                    // Onion-Location already went out with the baseline above.
                    // This fragment is a full HTML document to anything that
                    // navigates to it directly (curl, or a browser following the
                    // URL), so it needs the same strict CSP as the main document;
                    // the JS shell's own, more permissive CSP is emitted by
                    // JsController on the /js/ shell response, not here.
                    $this->emitServerTiming('astrx_fragment', $startedAt);
                }

                echo $fragmentResult->unwrap();
                return null;
            }

            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);

            // ── API dispatch ─────────────────────────────────────────────────
            // /api/<page> URLs are served via the JsonRenderer. The page
            // must have api_enabled = 1 — otherwise we return 404 without
            // revealing the page exists.
            if ($request->isApi()) {
                if (!$page->apiEnabled) {
                    $this->collector->emit(new \AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic(
                        'astrx.api/not_enabled',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    http_response_code(404);
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode([
                        'ok'     => false,
                        'status' => 404,
                        'error'  => [
                            'id'          => 'astrx.api/not_enabled',
                            'level'       => 'warning',
                            'level_value' => \AstrX\Result\DiagnosticLevel::WARNING->value,
                            'message'     => 'API endpoint not enabled',
                        ],
                        'meta' => [
                            'locale'      => $locale,
                            'page'        => $page->urlId,
                            'diagnostics' => ['total' => 1, 'visible' => 1, 'hidden' => 0],
                        ],
                        'diagnostics' => [[
                            'id'          => 'astrx.api/not_enabled',
                            'level'       => 'warning',
                            'level_value' => \AstrX\Result\DiagnosticLevel::WARNING->value,
                            'message'     => 'API endpoint not enabled',
                        ]],
                    ], JSON_UNESCAPED_SLASHES);
                    return null;
                }

                $rendererResult = $this->injector->createClass(\AstrX\Site\JsonRenderer::class)
                    ->drainTo($this->collector);
                if (!$rendererResult->isOk()) {
                    return HttpStatus::INTERNAL_SERVER_ERROR;
                }
                /** @var \AstrX\Site\JsonRenderer $jsonRenderer */
                $jsonRenderer = $rendererResult->unwrap();

                $html = $renderResult->isOk() ? (string) $renderResult->unwrap() : '';
                // Try to find the user-session admin flag through the injector
                $isAdmin = false;
                $sessResult = $this->injector->getClass(\AstrX\User\UserSession::class);
                if ($sessResult->isOk()) {
                    /** @var \AstrX\User\UserSession $sess */
                    $sess = $sessResult->unwrap();
                    $isAdmin = $sess->isLoggedIn() && $sess->isAdmin();
                }

                if (!headers_sent()) {
                    $this->emitServerTiming('astrx_api', $startedAt);
                }
                $jsonRenderer->emit(
                    ctx:          $ctx,
                    locale:       $locale,
                    pageUrlId:    $page->urlId,
                    renderedHtml: $html,
                    isAdmin:      $isAdmin,
                );
                return null;
            }

            // ── HTML dispatch (default) ──────────────────────────────────────
            if (!$renderResult->isOk()) {
                return HttpStatus::INTERNAL_SERVER_ERROR;
            }

            if (!headers_sent()) {
                $this->emitServerTiming('astrx_html', $startedAt);
            }
            // Baseline security headers (CSP etc.) already went out at the top of
            // the response phase, for this branch and every other one.
            echo $renderResult->unwrap();
        return null;
    }

    // Fall-through for pages with template=0. The original intent was
    // "no template, no body — emit 204 No Content". But that breaks for
    // controllers that legitimately write raw bytes (PNG image endpoint,
    // JSON, XML feeds, etc.) — downgrading 200→204 with a non-empty body
    // makes browsers either reject the response ("image contains errors")
    // or strip the body entirely (empty iframe).
    //
    // The fix: only downgrade when NOTHING has been emitted. ob_get_length()
    // tells us if any output buffer holds bytes, and headers_sent() catches
    // the case where output went straight to the wire. If either is true,
    // the controller produced a real response and we leave the status alone.
    $hasContent  = headers_sent() || (ob_get_level() > 0 && ob_get_length() > 0);
    $currentCode = http_response_code();
    if (!$hasContent && ($currentCode === false || $currentCode === 200)) {
        http_response_code(HttpStatus::NO_CONTENT->value);
    }

        return null;
    }

    private function isJsBrowserContentRequest(Request $request): bool
    {
        $value = $request->headers()->get('X-AstrX-JS-Browser');
        if ($value === null || trim($value) === '') {
            return false;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        $acceptRaw = $request->headers()->get('Accept', '');
        $accept = is_string($acceptRaw) ? strtolower($acceptRaw) : '';
        return $accept === '' || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private function emitServerTiming(string $name, float $started): void
    {
        // Off by default: Server-Timing / X-AstrX-Elapsed-Ms expose high-resolution
        // compute time (a timing side channel) and fingerprint the stack — both
        // undesirable on a privacy/Tor-first deployment. Enable for debugging via
        // ContentManager.expose_server_timing.
        if ($this->config->getConfig('ContentManager', 'expose_server_timing', false) !== true) {
            return;
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?: 'astrx';
        $dur = max(0.0, (microtime(true) - $started) * 1000.0);
        header('Server-Timing: ' . $safe . ';dur=' . number_format($dur, 2, '.', ''), false);
        header('X-AstrX-Elapsed-Ms: ' . number_format($dur, 2, '.', ''));
    }
}
