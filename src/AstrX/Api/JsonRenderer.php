<?php
declare(strict_types=1);

namespace AstrX\Api;

use AstrX\Auth\DiagnosticVisibilityChecker;
use AstrX\Http\Request;
use AstrX\Result\DiagnosticContextInterface;
use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\DefaultTemplateContext;

/**
 * Render the controller's output as a JSON envelope for API consumers.
 *
 * Envelope structure (success):
 *   {
 *     "ok":     true,
 *     "status": 200,
 *     "data":   { ...filtered context, by scope... },
 *     "html":   "<rendered template>",   // omit when ?html=0
 *     "meta": {
 *       "locale": "en",
 *       "page":   "user-profile",
 *       "diagnostics": { "total": 1, "visible": 1, "hidden": 0 }
 *     },
 *     "diagnostics": [ ...visible diagnostics... ]
 *   }
 *
 * Envelope structure (failure — dominant diagnostic level >= ERROR):
 *   {
 *     "ok":     false,
 *     "status": 4xx | 5xx,
 *     "error":  { "id": "astrx.x/y", "level": "error", "message": "..." },
 *     "diagnostics": [ ...visible diagnostics... ],
 *     "meta": { "diagnostics": { ... } }
 *   }
 *
 * Design choices:
 *   - API data is filtered through ContextScope: only SHARED, API_PUBLIC, and
 *     (for admins) API_ADMIN keys are exposed.
 *   - Diagnostics are serialised from the same DiagnosticInterface objects the
 *     controllers/template engine emit. Only stable fields (id, level,
 *     level_value, rendered message) are exposed; extra context is included
 *     ONLY when a diagnostic opts in via DiagnosticContextInterface::context().
 *     Arbitrary getters are never reflected, so internal details (file paths,
 *     eval text, temp paths) cannot leak into API responses.
 *   - Diagnostic visibility follows DiagnosticVisibilityChecker, just like the
 *     HTML message bar. Hidden diagnostics still influence the HTTP status, but
 *     their details are not leaked in the JSON body.
 *   - html is included by default. Pass ?html=0 to omit it for pure-data
 *     clients that want less bandwidth.
 *   - We never include WEB_ONLY context (the default scope), so legacy
 *     controllers that haven't been audited can't accidentally leak data.
 */
final class JsonRenderer
{
    public function __construct(
        private readonly Request $request,
        private readonly DiagnosticsCollector $collector,
        private readonly DiagnosticRenderer $diagnosticRenderer,
        private readonly DiagnosticVisibilityChecker $visibilityChecker,
    ) {}

    /**
     * Render and emit the JSON envelope.
     * Sends the appropriate HTTP status, Content-Type header, and JSON body.
     *
     * @param string  $locale          Active locale string (e.g. 'en')
     * @param string  $pageUrlId       The page's url_id token
     * @param string  $renderedHtml    Rendered template HTML or '' to omit
     * @param bool    $isAdmin         Whether the caller is admin-authed
     * @param ?string $csrfToken       Current CSRF token, if any
     */
    public function emit(
        DefaultTemplateContext $ctx,
        string                 $locale,
        string                 $pageUrlId,
        string                 $renderedHtml = '',
        bool                   $isAdmin      = false,
        ?string                $csrfToken    = null,
    ): void {
        $diagnostics = $this->collector->diagnostics()->toArray();
        $dominant    = $this->dominantLevel($diagnostics);

        $visibleDiagnostics = [];
        $hiddenCount = 0;
        foreach ($diagnostics as $diagnostic) {
            if ($this->visibilityChecker->canSee($diagnostic)) {
                $visibleDiagnostics[] = $diagnostic;
            } else {
                $hiddenCount++;
            }
        }

        $serialisedDiag = array_map([$this, 'serialiseDiagnostic'], $visibleDiagnostics);

        $isFailure = $dominant !== null && $dominant->value >= DiagnosticLevel::ERROR->value;
        $status    = $isFailure ? $this->statusFromDiagnostics($diagnostics) : 200;

        $diagnosticMeta = [
            'total'   => count($diagnostics),
            'visible' => count($visibleDiagnostics),
            'hidden'  => $hiddenCount,
        ];

        $envelope = [
            'ok'     => !$isFailure,
            'status' => $status,
        ];

        if ($isFailure) {
            $worst = $this->worstDiagnostic($diagnostics);
            if ($worst !== null && $this->visibilityChecker->canSee($worst)) {
                $envelope['error'] = $this->serialiseDiagnostic($worst);
            } else {
                // The dominant diagnostic is hidden from this caller (or absent):
                // do not leak it. Emit a generic, id-first envelope rendered
                // through the DiagnosticRenderer path the class already uses, so
                // the message is translated once an 'astrx.api/internal_error'
                // catalog entry exists and stays a clearly-marked fallback until
                // then — never a hardcoded English literal.
                $envelope['error'] = $this->serialiseDiagnostic($this->internalErrorDiagnostic());
            }
        } else {
            $envelope['data'] = $ctx->getApiData($isAdmin);

            $includeHtml = $this->shouldIncludeHtml();
            if ($includeHtml && $renderedHtml !== '') {
                $envelope['html'] = $renderedHtml;
            }
        }

        $envelope['meta'] = [
            'locale'      => $locale,
            'page'        => $pageUrlId,
            'diagnostics' => $diagnosticMeta,
        ];
        if ($csrfToken !== null) {
            $envelope['meta']['csrf_token'] = $csrfToken;
        }

        $envelope['diagnostics'] = $serialisedDiag;

        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
        }
        echo json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{id:string,level:string,level_value:int,message:string,context?:array<string,mixed>}
     */
    private function serialiseDiagnostic(DiagnosticInterface $diagnostic): array
    {
        $level = $this->visibilityChecker->effectiveLevel($diagnostic);
        $out = [
            'id'          => $diagnostic->id(),
            'level'       => strtolower($level->name),
            'level_value' => $level->value,
            'message'     => $this->diagnosticRenderer->render($diagnostic),
        ];

        $context = $this->diagnosticContext($diagnostic);
        if ($context !== []) {
            $out['context'] = $context;
        }

        return $out;
    }

    /**
     * Structured context for a diagnostic — ONLY from diagnostics that opt in
     * via DiagnosticContextInterface. Arbitrary public getters are never
     * reflected, since that could surface internal details (file paths, eval
     * text, temp paths) to API clients. The implementer decides exactly what is
     * exposed.
     *
     * @return array<string,mixed>
     */
    private function diagnosticContext(DiagnosticInterface $diagnostic): array
    {
        if (!$diagnostic instanceof DiagnosticContextInterface) {
            return [];
        }

        $context = [];
        foreach ($diagnostic->context() as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalised = $this->normaliseContextValue($value);
            if ($normalised === null) {
                continue;
            }
            $context[$key] = $normalised;
        }

        ksort($context);
        return $context;
    }

    /**
     * Synthetic diagnostic backing the generic "internal error" envelope when
     * the dominant diagnostic must not be disclosed. Carries the stable id and
     * ERROR level; it is rendered through the normal DiagnosticRenderer path so
     * the message translates once an 'astrx.api/internal_error' catalog entry
     * exists and remains a clearly-marked fallback until then.
     */
    private function internalErrorDiagnostic(): DiagnosticInterface
    {
        return new class implements DiagnosticInterface {
            public function id(): string { return 'astrx.api/internal_error'; }
            public function level(): DiagnosticLevel { return DiagnosticLevel::ERROR; }
        };
    }

    private function normaliseContextValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $normalised = $this->normaliseContextValue($item);
                if ($normalised !== null) {
                    $out[$key] = $normalised;
                }
            }
            return $out;
        }

        return null;
    }

    /** @param list<DiagnosticInterface> $diagnostics */
    private function dominantLevel(array $diagnostics): ?DiagnosticLevel
    {
        $max = null;
        foreach ($diagnostics as $diagnostic) {
            $level = $this->visibilityChecker->effectiveLevel($diagnostic);
            if ($max === null || $level->value > $max->value) {
                $max = $level;
            }
        }
        return $max;
    }

    /** @param list<DiagnosticInterface> $diagnostics */
    private function worstDiagnostic(array $diagnostics): ?DiagnosticInterface
    {
        $worst = null;
        $worstValue = -1;
        foreach ($diagnostics as $diagnostic) {
            $level = $this->visibilityChecker->effectiveLevel($diagnostic);
            if ($level->value > $worstValue) {
                $worst = $diagnostic;
                $worstValue = $level->value;
            }
        }
        return $worst;
    }

    /** @param list<DiagnosticInterface> $diagnostics */
    private function statusFromDiagnostics(array $diagnostics): int
    {
        $worst = $this->worstDiagnostic($diagnostics);
        if ($worst === null) {
            return 200;
        }

        $context = $this->diagnosticContext($worst);
        $explicitStatus = $context['http_status'] ?? $context['status'] ?? null;
        if (is_int($explicitStatus) && $explicitStatus >= 100 && $explicitStatus <= 599) {
            return $explicitStatus;
        }

        // Domain-level defaults. Framework/runtime failures are 5xx; user/input
        // failures are 4xx.
        $id = $worst->id();
        $level = $this->visibilityChecker->effectiveLevel($worst);

        if ($id === 'astrx.api/not_enabled') {
            return 404;
        }
        if (str_starts_with($id, 'astrx.csrf/')) {
            return 403;
        }
        if (str_starts_with($id, 'astrx.auth/') || str_contains($id, 'forbidden') || str_contains($id, 'denied')) {
            return 403;
        }
        if (str_contains($id, 'not_found')) {
            return 404;
        }
        if (str_starts_with($id, 'astrx.user/') || str_starts_with($id, 'astrx.comment/')) {
            return 422;
        }

        return match (true) {
            $level->value >= DiagnosticLevel::EMERGENCY->value => 503,
            $level->value >= DiagnosticLevel::CRITICAL->value  => 500,
            $level->value >= DiagnosticLevel::ERROR->value     => 500,
            default                                             => 200,
        };
    }

    private function shouldIncludeHtml(): bool
    {
        $value = $this->request->query()->get('html');
        return !in_array($value, ['0', 'false', 'no'], true);
    }
}
