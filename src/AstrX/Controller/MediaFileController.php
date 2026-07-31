<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Request;
use AstrX\Media\MediaConfig;
use AstrX\Media\MediaRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;

/**
 * Raw media-file endpoint — no template wrapping (page template=0).
 *
 * URL: /media/<name>            (rewrite mode, name from the URL tail)
 *      /media?name=<name>       (query mode / generated links)
 *
 * Serves a re-encoded media file by its stored name. Defence in depth mirrors
 * BoardFileController: the name must match the exact token shape MediaService
 * writes (a [a-z0-9-] slug + a servable extension — never a user path, never a
 * '/', '.' or '..'), it must exist in the `media` table, its stored MIME must be
 * one of the image types we re-encode to, and the served path is confined to the
 * configured upload dir. Hardened headers (nosniff, no-referrer) precede the
 * bytes and exit() follows so ContentManager cannot stamp a code over it.
 *
 * Public (no permission gate): media is meant to be embedded in public content
 * pages. The module is still gated as a whole — if it is disabled the core
 * ModulePageGuard 404s this page before the controller ever runs.
 */
final class MediaFileController extends AbstractController
{
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,79}\.(?:jpg|png|gif|webp)$/';

    /** The only MIME types the re-encode can produce / this endpoint will serve. */
    private const array ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        DiagnosticsCollector             $collector,
        private readonly Request         $request,
        private readonly CurrentUrl      $currentUrl,
        private readonly MediaRepository $repo,
        private readonly MediaConfig     $config,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // Name from the URL tail (/media/<name>) or the ?name= query fallback.
        $name = $this->currentUrl->tailSegment(0)
                ?? (is_scalar($vn = $this->request->query()->get('name') ?? '') ? (string) $vn : '');
        $name = strtolower(trim($name));

        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            http_response_code(404);
            exit;
        }

        $result = $this->repo->byName($name);
        $row    = $result->isOk() ? $result->unwrap() : null;
        if (!is_array($row)) {
            http_response_code(404);
            exit;
        }

        $stored = self::mStr($row, 'name');
        $mime   = self::mStr($row, 'mime');
        // Re-validate the stored name (defence in depth) and whitelist the MIME.
        if (preg_match(self::NAME_PATTERN, $stored) !== 1 || !in_array($mime, self::ALLOWED_MIMES, true)) {
            http_response_code(404);
            exit;
        }

        $dir  = $this->config->uploadDir();
        $path = $dir . '/' . $stored;
        if ($dir === '' || !is_file($path)) {
            http_response_code(404);
            exit;
        }

        $size = @filesize($path);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($path);
        exit;
    }
}
