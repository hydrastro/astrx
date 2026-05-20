<?php
declare(strict_types=1);

namespace AstrX\Api;

use AstrX\Auth\DiagnosticVisibilityChecker;
use AstrX\Http\Request;
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
 *     controllers/template engine emit. The renderer does not assume a
 *     context() method; it extracts context from public zero-argument accessors
 *     such as token(), file(), message(), detail(), captchaId(), etc.
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
                $envelope['error'] = [
                    'id'          => 'astrx.api/internal_error',
                    'level'       => 'error',
                    'level_value' => DiagnosticLevel::ERROR->value,
                    'message'     => 'Internal error',
                ];
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
     * Extract diagnostic payload without requiring every diagnostic class to
     * implement a parallel context() method.
     *
     * @return array<string,mixed>
     */
    private function diagnosticContext(DiagnosticInterface $diagnostic): array
    {
        $context = [];
        $reflection = new \ReflectionObject($diagnostic);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() !== 0) {
                continue;
            }

            $name = $method->getName();
            if (in_array($name, ['id', 'level', '__toString'], true)) {
                continue;
            }
            if (str_starts_with($name, '__')) {
                continue;
            }

            try {
                $value = $method->invoke($diagnostic);
            } catch (\Throwable) {
                continue;
            }

            $normalised = $this->normaliseContextValue($value);
            if ($normalised === null) {
                continue;
            }

            $context[$this->contextKeyFromMethodName($name)] = $normalised;
        }

        ksort($context);
        return $context;
    }

    private function contextKeyFromMethodName(string $methodName): string
    {
        if (str_starts_with($methodName, 'get') && strlen($methodName) > 3) {
            $methodName = substr($methodName, 3);
        }

        $key = preg_replace('/(?<!^)[A-Z]/', '_$0', $methodName);
        return strtolower((string) $key);
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
                if (!is_int($key) && !is_string($key)) {
                    continue;
                }
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
