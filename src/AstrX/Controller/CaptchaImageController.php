<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaRenderer;
use AstrX\Captcha\CaptchaRepository;
use AstrX\Http\Request;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;

/**
 * Serves the captcha image as a raw PNG response.
 *
 * URL: /<locale>/captcha-image?cid=<captcha-id>
 *
 * This endpoint is used by the iframe-reloadable captcha system. The
 * captcha record\'s plaintext is fetched from the DB, the image is
 * rendered on the fly, and the bytes are streamed as image/png.
 *
 * Security:
 *   - The captcha id is opaque (32 hex chars from random_bytes) so an
 *     attacker cannot enumerate captchas.
 *   - We never reveal whether a captcha exists or not — both "id missing"
 *     and "id expired" return a 404 with a tiny blank PNG, not text.
 *   - Cache-Control: no-store. Each render is fresh; the user\'s browser
 *     must not cache the image so a refresh in the iframe always pulls
 *     the latest answer.
 *
 * No template engine involvement — the controller writes directly to the
 * response. The page row has template=0 so the framework skips template
 * dispatch after the controller returns.
 */
final class CaptchaImageController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                $collector,
        private readonly Request            $request,
        private readonly CaptchaRepository  $captchaRepo,
        private readonly CaptchaRenderer    $captchaRenderer,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $cid = self::queryStr($this->request, 'cid', '');
        if ($cid === '' || !preg_match('/\A[0-9a-f]{32}\z/', $cid)) {
            $this->emitBlankPng(404);
            return $this->ok();
        }

        $found = $this->captchaRepo->find($cid);
        if (!$found->isOk()) {
            $this->emitBlankPng(404);
            return $this->ok();
        }
        $row = $found->unwrap();
        if (!is_array($row) || !isset($row['text'])) {
            $this->emitBlankPng(404);
            return $this->ok();
        }

        $text   = is_scalar($row['text']) ? (string) $row['text'] : '';
        $b64uri = $this->captchaRenderer->render($text);
        // render() returns a `data:image/png;base64,...` URI — strip the prefix.
        $comma  = strpos($b64uri, ',');
        $bytes  = $comma === false
            ? base64_decode($b64uri,         true)
            : base64_decode(substr($b64uri, $comma + 1), true);
        if (!is_string($bytes) || $bytes === '') {
            $this->emitBlankPng(500);
            return $this->ok();
        }

        http_response_code(200);
        if (!headers_sent()) {
            // CaptchaRenderer::render() emits GIF bytes (imagegif) — label them as
            // such so strict clients that don't content-sniff still display it (F-28).
            header('Content-Type: image/gif');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Content-Length: ' . (string) strlen($bytes));
        }
        echo $bytes;
        // Hard stop — the framework's fall-through code path after the
        // controller would otherwise still execute, and any further output
        // (warnings, debug, etc.) would corrupt the PNG bytes already on
        // the wire. We've sent the full image; nothing else should run.
        exit;
    }

    /**
     * 1x1 transparent PNG — the minimal valid image. We return this on every
     * lookup failure (missing id, expired, bad format) so the caller cannot
     * distinguish error cases from each other.
     */
    private function emitBlankPng(int $status): void
    {
        $pixel = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        );
        if (!is_string($pixel)) { $pixel = ''; }
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: image/png');
            header('Cache-Control: no-store');
            header('Content-Length: ' . (string) strlen($pixel));
        }
        echo $pixel;
        exit;
    }
}
