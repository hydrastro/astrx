<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Request;
use AstrX\Identicon\IdenticonRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\User\AvatarService;
use AstrX\User\UserRepository;

/**
 * Raw avatar image endpoint — no template wrapping.
 *
 * URL: /avatar?uid=<hexUserId>  (query mode)
 *      /avatar/<hexUserId>      (rewrite mode via URL tail)
 *
 * Serves:
 *   - Custom PNG if user has uploaded one.
 *   - Identicon (generated from user ID) if use_identicons=true.
 *   - 404 otherwise.
 *
 * Calls exit after outputting so ContentManager does not try to
 * set a 204 response code over an already-output image.
 */
final class AvatarController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector        $collector,
        private readonly Request    $request,
        private readonly CurrentUrl $currentUrl,
        private readonly AvatarService $avatarService,
        private readonly IdenticonRenderer $identicon,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // ── Guest identicon by arbitrary seed ─────────────────────────────
        // ?seed=<hex> serves an identicon seeded from any hex string.
        // Used for guest comment avatars (seed = sha256 of name+ip).
        $seed = (string) ($this->request->query()->get('seed') ?? '');
        if ($seed !== '') {
            if (!ctype_xdigit($seed)) {
                http_response_code(400);
                exit;
            }
            if ($this->avatarService->useIdenticons()) {
                $b64 = $this->identicon->render($seed);
                $png = base64_decode($b64);
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=86400');
                echo $png;
                exit;
            }
            http_response_code(404);
            exit;
        }

        // ── Registered user avatar ────────────────────────────────────────
        $hexId = $this->currentUrl->tailSegment(0)
                 ?? (string) ($this->request->query()->get('uid') ?? '');

        if ($hexId === '' || !ctype_xdigit($hexId) || strlen($hexId) !== 32) {
            http_response_code(404);
            exit;
        }

        // Custom avatar on disk?
        if ($this->avatarService->exists($hexId)) {
            $path = $this->avatarService->pathFor($hexId);
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile($path);
            exit;
        }

        // Identicon fallback
        if ($this->avatarService->useIdenticons()) {
            $b64 = $this->identicon->render($hexId);
            $png = base64_decode($b64);
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=3600');
            echo $png;
            exit;
        }

        http_response_code(404);
        exit;
    }
}
