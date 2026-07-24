<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\Imageboard\ImageboardConfig;
use AstrX\Imageboard\ImageRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;

/**
 * Raw imageboard image endpoint — no template wrapping (page template=0).
 *
 * URL: /board-file?t=<token>[&thumb=1]. Looks the image up by its random token,
 * serves the full image or its thumbnail. Defence in depth mirrors
 * ChatFileController: the on-disk name must match exactly what ImageService
 * writes (32 hex + .jpg/.png — never a user path), the mime is one of the two we
 * re-encode to, and hardened headers (nosniff, no-referrer) are sent before the
 * file. exit() after output so ContentManager cannot stamp a code over it.
 */
final class BoardFileController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector             $collector,
        private readonly Request         $request,
        private readonly Gate            $gate,
        private readonly ImageRepository $repo,
        private readonly ImageboardConfig $config,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $raw   = $this->request->query()->get('t') ?? '';
        $token = is_scalar($raw) ? (string) $raw : '';
        if (strlen($token) !== 32 || !ctype_xdigit($token)) {
            http_response_code(404);
            exit;
        }

        $result = $this->repo->findByToken($token);
        $row    = $result->isOk() ? $result->unwrap() : null;
        if (!is_array($row)) {
            http_response_code(404);
            exit;
        }

        $wantThumb = self::queryStr($this->request, 'thumb') !== '';
        $stored    = self::mStr($row, $wantThumb ? 'thumb_name' : 'full_name');
        $mime      = self::mStr($row, 'mime');

        if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png)$/', $stored) !== 1
            || !in_array($mime, ['image/jpeg', 'image/png'], true)) {
            http_response_code(404);
            exit;
        }

        $dir  = $this->config->uploadDir();
        $path = $dir . '/' . $stored;
        if ($dir === '' || !is_file($path)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, max-age=86400');
        header('Content-Disposition: inline');
        readfile($path);
        exit;
    }
}
