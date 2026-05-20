<?php
declare(strict_types=1);

namespace AstrX\Api;

use AstrX\Http\Request;
use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\DefaultTemplateContext;

/**
 * Render the controller\'s output as a JSON envelope for API consumers.
 *
 * Envelope structure (success):
 *   {
 *     "ok":     true,
 *     "status": 200,
 *     "data":   { ...filtered context, by scope... },
 *     "html":   "<rendered template>",   // omit when ?html=0
 *     "meta": {
 *       "locale":     "en",
 *       "page":       "user-profile",
 *       "csrf_token": "..."             // present when a session is active
 *     },
 *     "diagnostics": [ ... ]            // non-fatal notices only
 *   }
 *
 * Envelope structure (failure — dominant diagnostic level >= ERROR):
 *   {
 *     "ok":     false,
 *     "status": 4xx | 5xx,
 *     "error":  { "id": "astrx.x/y", "level": "error", "message": "..." },
 *     "diagnostics": [ ... ]
 *   }
 *
 * Design choices:
 *   - status code is set from the dominant diagnostic level (the worst one).
 *     ERROR/CRITICAL/ALERT/EMERGENCY → caller decides 4xx vs 5xx based on
 *     the diagnostic ID; NOTICE/WARNING → 200.
 *   - data is filtered through ContextScope: only SHARED, API_PUBLIC, and
 *     (for admins) API_ADMIN keys are exposed.
 *   - html is included by default. Pass ?html=0 to omit it for pure-data
 *     clients that want less bandwidth.
 *   - We never include WEB_ONLY context (the default scope), so legacy
 *     controllers that haven\'t been audited can\'t accidentally leak data.
 */
/** @internal sentinel — fix127 patch landed if this string is in the file */
final class JsonRenderer
{
    public function __construct(
        private readonly Request $request,
        private readonly DiagnosticsCollector $collector,
    ) {}

    /**
     * Render and emit the JSON envelope.
     * Sends the appropriate HTTP status, Content-Type header, and JSON body.
     *
     * @param string  $locale          Active locale string (e.g. 'en')
     * @param string  $pageUrlId       The page\'s url_id token
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
        // Determine dominant diagnostic level → HTTP status
        // fix126: collector->all() doesn't exist; the correct accessor is
        // diagnostics()->toArray(). diagnostics() returns the Diagnostics
        // monad; toArray() turns it into a plain list<DiagnosticInterface>.
        $diagnostics = $this->collector->diagnostics()->toArray();
        $dominant    = $this->dominantLevel($diagnostics);

        // Render diagnostics as plain arrays (drop object structure).
        // Levels below ERROR are "notes for the caller"; ERROR+ make the
        // response a failure.
        $serialisedDiag = array_map([$this, 'serialiseDiagnostic'], $diagnostics);

        $isFailure = $dominant !== null && $dominant->value >= DiagnosticLevel::ERROR->value;
        $status    = $isFailure ? $this->statusFromLevel($dominant) : 200;

        $envelope = [
            'ok'     => !$isFailure,
            'status' => $status,
        ];

        if ($isFailure) {
            // Find the worst diagnostic to surface as "error"
            $worst = null;
            $worstValue = -1;
            foreach ($diagnostics as $d) {
                if ($d->level()->value > $worstValue) {
                    $worst      = $d;
                    $worstValue = $d->level()->value;
                }
            }
            if ($worst !== null) {
                $envelope['error'] = $this->serialiseDiagnostic($worst);
            }
        } else {
            // Success path — emit filtered data + html
            $envelope['data'] = $ctx->getApiData($isAdmin);

            $includeHtml = $this->shouldIncludeHtml();
            if ($includeHtml && $renderedHtml !== '') {
                $envelope['html'] = $renderedHtml;
            }

            $envelope['meta'] = [
                'locale' => $locale,
                'page'   => $pageUrlId,
            ];
            if ($csrfToken !== null) {
                $envelope['meta']['csrf_token'] = $csrfToken;
            }
        }

        // Always include diagnostics (even on success — they're notices)
        $envelope['diagnostics'] = $serialisedDiag;

        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            // Conservative cache headers — API responses are per-session
            header('Cache-Control: private, no-store');
        }
        echo json_encode(
            $envelope,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    // -------------------------------------------------------------------------

    /** @return array{id:string,level:string,context?:array<string,mixed>} */
    private function serialiseDiagnostic(DiagnosticInterface $d): array
    {
        $out = [
            'id'    => $d->id(),
            'level' => strtolower($d->level()->name),
        ];
        $ctx = $d->context();
        if ($ctx !== []) {
            $out['context'] = $ctx;
        }
        return $out;
    }

    /** @param list<DiagnosticInterface> $diagnostics */
    private function dominantLevel(array $diagnostics): ?DiagnosticLevel
    {
        $max = null;
        foreach ($diagnostics as $d) {
            if ($max === null || $d->level()->value > $max->value) {
                $max = $d->level();
            }
        }
        return $max;
    }

    private function statusFromLevel(DiagnosticLevel $level): int
    {
        // Conservative mapping. Specific IDs can override this in future
        // by attaching an http_status to the diagnostic context.
        return match (true) {
            $level->value >= DiagnosticLevel::EMERGENCY->value => 503,
            $level->value >= DiagnosticLevel::CRITICAL->value  => 500,
            $level->value >= DiagnosticLevel::ERROR->value     => 400,
            default                                             => 200,
        };
    }

    private function shouldIncludeHtml(): bool
    {
        // Default: include. Opt out with ?html=0
        $v = $this->request->query()->get('html');
        if ($v === '0' || $v === 'false' || $v === 'no') {
            return false;
        }
        return true;
    }
}
