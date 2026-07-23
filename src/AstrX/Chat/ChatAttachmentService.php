<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Chat\Diagnostic\ChatUploadDiagnostic;
use AstrX\Http\UploadedFile;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * Chat image attachments — validate, strip, downscale, store.
 *
 * SECURITY MODEL (mirrors AvatarService, hardened for a TOR service):
 *   1. Images only. Extension must be in the admin allowlist AND getimagesize()
 *      must confirm it is a real image — no arbitrary files.
 *   2. The image is DECODED and RE-ENCODED through GD (imagecreatefromstring →
 *      imagejpeg/imagepng). Only decoded pixels survive, so ALL metadata (EXIF,
 *      GPS, comments) is discarded and any trailing/polyglot payload is
 *      neutralised — the re-encode IS the strip.
 *   3. Oversized images are downscaled to `upload_max_dimension`.
 *   4. Stored under a random 16-byte name in `upload_dir`; the public handle is a
 *      separate random 32-hex `token` (served by ChatFileController), so the
 *      on-disk name is never exposed and files can't be enumerated.
 *
 * `store()` processes the file and returns its metadata (no DB); `persist()`
 * writes the row once the owning message exists. Split so post() can reject a
 * bad upload BEFORE creating the message.
 */
final class ChatAttachmentService
{
    public function __construct(
        private readonly ChatAttachmentRepository $repo,
        private readonly ChatConfig               $config,
    ) {}

    public function enabled(): bool { return $this->config->uploadsEnabled(); }

    /** Whether $isMember may upload under the current config. */
    public function mayUpload(bool $isMember): bool
    {
        return $this->config->uploadsEnabled() && ($isMember || $this->config->uploadsGuests());
    }

    /**
     * Validate + strip + downscale + write the file. Returns stored metadata
     * (no DB row yet). On any failure the post should be rejected.
     *
     * @return Result<array{token:string,stored_name:string,mime:string,size:int,width:int,height:int}>
     */
    public function store(UploadedFile $file): Result
    {
        if ($file->hasError()) {
            return $this->err('upload_error');
        }
        if ($file->size() > $this->config->uploadMaxBytes()) {
            return $this->err('upload_too_big');
        }
        $ext = strtolower(pathinfo($file->clientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $this->config->uploadTypes(), true)) {
            return $this->err('upload_type');
        }

        // Confirm it is really an image (getimagesize is part of GD; no exif ext).
        $info = @getimagesize($file->tempPath());
        if ($info === false) {
            return $this->err('upload_invalid');
        }
        $type = $info[2]; // getimagesize offset 2 = IMAGETYPE_* (always a set int)

        $raw = @file_get_contents($file->tempPath());
        if ($raw === false) {
            return $this->err('upload_invalid');
        }
        $img = @imagecreatefromstring($raw);
        if (!$img instanceof \GdImage) {
            return $this->err('upload_invalid');
        }

        // Downscale to the max dimension (proportional), if configured and needed.
        $w   = imagesx($img);
        $h   = imagesy($img);
        $max = $this->config->uploadMaxDimension();
        if ($max > 0 && ($w > $max || $h > $max)) {
            $scale = $max / max($w, $h);
            $nw    = max(1, (int) round($w * $scale));
            $nh    = max(1, (int) round($h * $scale));
            $scaled = imagescale($img, $nw, $nh);
            if ($scaled instanceof \GdImage) {
                imagedestroy($img);
                $img = $scaled;
                $w   = $nw;
                $h   = $nh;
            }
        }

        // Re-encode: JPEG source → JPEG (photos stay small), everything else → PNG.
        // This is the strip: only pixels are written, no metadata carries over.
        $isJpeg     = $type === IMAGETYPE_JPEG;
        $storedName = bin2hex(random_bytes(16)) . ($isJpeg ? '.jpg' : '.png');

        $dir = $this->config->uploadDir();
        if ($dir === '') {
            imagedestroy($img);
            return $this->err('upload_failed');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            imagedestroy($img);
            return $this->err('upload_failed');
        }
        $dest = $dir . '/' . $storedName;
        $ok   = $isJpeg ? @imagejpeg($img, $dest, 85) : @imagepng($img, $dest);
        imagedestroy($img);
        if ($ok !== true) {
            return $this->err('upload_failed');
        }

        $size = (int) @filesize($dest);
        return Result::ok([
            'token'       => bin2hex(random_bytes(16)),
            'stored_name' => $storedName,
            'mime'        => $isJpeg ? 'image/jpeg' : 'image/png',
            'size'        => $size,
            'width'       => $w,
            'height'      => $h,
        ]);
    }

    /**
     * Write the DB row linking a stored file to its message.
     *
     * @param array{token:string,stored_name:string,mime:string,size:int,width:int,height:int} $meta
     * @return Result<int>
     */
    public function persist(int $messageId, array $meta): Result
    {
        return $this->repo->create(
            $messageId,
            $meta['token'],
            $meta['stored_name'],
            $meta['mime'],
            $meta['size'],
            $meta['width'],
            $meta['height'],
        );
    }

    /**
     * Attachment metadata for a set of message ids, keyed by message_id.
     *
     * @param list<int> $messageIds
     * @return array<int,array<string,mixed>>
     */
    public function forMessages(array $messageIds): array
    {
        $r = $this->repo->forMessages($messageIds);
        return $r->isOk() ? $r->unwrap() : [];
    }

    /** Remove a stored file (used to roll back if the message row fails). */
    public function discard(string $storedName): void
    {
        $dir = $this->config->uploadDir();
        if ($dir !== '' && $storedName !== '') {
            @unlink($dir . '/' . $storedName);
        }
    }

    /** @return Result<never> */
    private function err(string $reason): Result
    {
        return Result::err(null, Diagnostics::of(new ChatUploadDiagnostic(
            'astrx.chat/' . $reason, DiagnosticLevel::NOTICE
        )));
    }
}
