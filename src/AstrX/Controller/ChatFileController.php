<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatAttachmentRepository;
use AstrX\Chat\ChatConfig;
use AstrX\Http\Request;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;

/**
 * Raw chat-attachment endpoint — no template wrapping (page template=0).
 *
 * URL: /chat-file?t=<token>. Looks the attachment up by its random token,
 * confirms the stored file is one we wrote (hex name + .jpg/.png — never a
 * user-supplied path), and streams it with hardened headers:
 *   - Content-Type only ever image/jpeg or image/png (the two we re-encode to)
 *   - X-Content-Type-Options: nosniff  (no MIME sniffing to something runnable)
 *   - Referrer-Policy: no-referrer      (TOR: never leak the page URL)
 * Gated CHAT_VIEW — only chat participants can fetch attachments. exit() after
 * output so ContentManager does not stamp a response code over the image.
 */
final class ChatFileController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                      $collector,
        private readonly Request                  $request,
        private readonly Gate                     $gate,
        private readonly ChatAttachmentRepository $repo,
        private readonly ChatConfig               $config,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::CHAT_VIEW)) {
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

        $stored = is_scalar($row['stored_name'] ?? null) ? (string) $row['stored_name'] : '';
        $mime   = is_scalar($row['mime'] ?? null) ? (string) $row['mime'] : '';

        // Defence in depth: the name must be exactly what we write (hex + ext),
        // and the mime one of the two we ever produce.
        // Accept every format the ImageSanitizer can emit: jpg/png from the
        // re-encode path, and gif/webp preserved verbatim when metadata-strip is
        // OFF (animation opt-out). Previously gif/webp were stored yet 404'd (F-10).
        if (preg_match('/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/', $stored) !== 1
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
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
