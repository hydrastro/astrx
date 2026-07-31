<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Http\UploadedFile;
use AstrX\Image\ImageOutputFormat;
use AstrX\Image\ImageSanitizeOptions;
use AstrX\Image\ImageSanitizer;
use AstrX\Imageboard\Diagnostic\ImageboardPostDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * Imageboard image handling — a thin consumer of the shared ImageSanitizer.
 * It writes a stripped full image plus a thumbnail to disk and returns metadata
 * (including a perceptual + SHA-256 hash) for board_image. The strip, downscale
 * and hashing all live in AstrX\Image; only storage/token live here.
 */
final class ImageService
{
    public function __construct(
        private readonly ImageRepository     $repo,
        private readonly ImageSanitizer      $sanitizer,
        private readonly ImageboardConfig    $config,
        private readonly ImageBlockRepository $blocks,
    ) {}

    /**
     * Validate + strip + write the full image and a thumbnail. Returns metadata
     * only (no DB row — persist() writes that once the post exists).
     *
     * @return Result<array{token:string,full_name:string,thumb_name:string,mime:string,size:int,width:int,height:int,thumb_w:int,thumb_h:int,ahash:int,sha256:string,orig_name:string,spoiler:bool}>
     */
    public function store(UploadedFile $file, bool $spoiler): Result
    {
        if ($file->hasError()) {
            return $this->fail();
        }
        $raw = @file_get_contents($file->tempPath());
        if ($raw === false) {
            return $this->fail();
        }
        $ext = strtolower(pathinfo($file->clientFilename(), PATHINFO_EXTENSION));

        // Video attachments take a separate, zero-dependency path: no GD decode,
        // no re-encode, no thumbnail — validated by magic number + size, stored
        // as-is, and rendered as an HTML5 <video>. Only when the operator enables
        // it (video metadata is NOT stripped without ffmpeg — an accepted trade).
        if ($this->config->videoEnabled() && in_array($ext, $this->config->videoTypes(), true)) {
            return $this->storeVideo($file, $raw, $ext, $spoiler);
        }

        $res = $this->sanitizer->sanitize($raw, $ext, new ImageSanitizeOptions(
            allowedExtensions:  $this->config->uploadTypes(),
            maxBytes:           $this->config->uploadMaxBytes(),
            maxPixels:          $this->config->uploadMaxPixels(),
            maxDimension:       $this->config->fullMaxDimension(),
            outputFormat:       ImageOutputFormat::AUTO,
            jpegQuality:        85,
            makeThumbnail:      true,
            thumbMaxDimension:  $this->config->thumbMaxDimension(),
            computeAverageHash: true,
            computeSha256:      true,
            stripMetadata:      $this->config->stripExif(),
        ));
        if (!$res->isOk()) {
            return Result::err($res->error(), $res->diagnostics());
        }
        $img = $res->unwrap();

        // Moderator blocklist: reject a known-bad image by exact (sha256) or
        // perceptual (ahash) hash before it is written to disk. A DB error is
        // fail-open (the lookup Result is not ok) so a blocklist outage does not
        // take posting down; a positive match is a hard reject.
        $blockR = $this->blocks->isBlocked($img->sha256 ?? '', $img->averageHash ?? 0);
        if ($blockR->isOk() && $blockR->unwrap() === true) {
            return $this->fail();
        }

        $dir = $this->config->uploadDir();
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true))) {
            return $this->fail();
        }
        $fullName   = bin2hex(random_bytes(16)) . '.' . $img->ext;
        $thumbName  = bin2hex(random_bytes(16)) . '.' . $img->ext;
        $thumbBytes = $img->thumbBytes ?? $img->fullBytes;

        if (@file_put_contents($dir . '/' . $fullName, $img->fullBytes) === false
            || @file_put_contents($dir . '/' . $thumbName, $thumbBytes) === false) {
            @unlink($dir . '/' . $fullName);
            @unlink($dir . '/' . $thumbName);
            return $this->fail();
        }

        return Result::ok([
            'token'      => bin2hex(random_bytes(16)),
            'full_name'  => $fullName,
            'thumb_name' => $thumbName,
            'mime'       => $img->mime,
            'size'       => strlen($img->fullBytes),
            'width'      => $img->width,
            'height'     => $img->height,
            'thumb_w'    => $img->thumbWidth  ?? $img->width,
            'thumb_h'    => $img->thumbHeight ?? $img->height,
            'ahash'      => $img->averageHash ?? 0,
            'sha256'     => $img->sha256 ?? '',
            'orig_name'  => $file->clientFilename(),
            'spoiler'    => $spoiler,
        ]);
    }

    /**
     * Store a validated video attachment as-is (no re-encode, no thumbnail).
     *
     * @return Result<array{token:string,full_name:string,thumb_name:string,mime:string,size:int,width:int,height:int,thumb_w:int,thumb_h:int,ahash:int,sha256:string,orig_name:string,spoiler:bool}>
     */
    private function storeVideo(UploadedFile $file, string $raw, string $ext, bool $spoiler): Result
    {
        if (strlen($raw) > $this->config->videoMaxBytes()) {
            return $this->fail();
        }
        $mime = $this->videoMime($raw, $ext);
        if ($mime === '') {
            return $this->fail();   // magic-number check failed → not a real webm/mp4
        }

        // Moderator blocklist: reject a known-bad video by exact (sha256) hash
        // before it is written to disk, mirroring the image path in store(). A
        // video carries no perceptual ahash, so pass 0 — isBlocked() treats a
        // zero ahash as "not set" and matches on sha256 alone. A DB error is
        // fail-open (the lookup Result is not ok) so a blocklist outage does not
        // take posting down; a positive match is a hard reject.
        $sha256 = hash('sha256', $raw);
        $blockR = $this->blocks->isBlocked($sha256, 0);
        if ($blockR->isOk() && $blockR->unwrap() === true) {
            return $this->fail();
        }

        $dir = $this->config->uploadDir();
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true))) {
            return $this->fail();
        }
        $fullName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $fullName, $raw) === false) {
            return $this->fail();
        }

        return Result::ok([
            'token'      => bin2hex(random_bytes(16)),
            'full_name'  => $fullName,
            'thumb_name' => '',
            'mime'       => $mime,
            'size'       => strlen($raw),
            'width'      => 0,
            'height'     => 0,
            'thumb_w'    => 0,
            'thumb_h'    => 0,
            'ahash'      => 0,
            'sha256'     => $sha256,
            'orig_name'  => $file->clientFilename(),
            'spoiler'    => $spoiler,
        ]);
    }

    /** Validate a video by magic number; returns the MIME, or '' if it doesn't match. */
    private function videoMime(string $raw, string $ext): string
    {
        if ($ext === 'webm' && str_starts_with($raw, "\x1A\x45\xDF\xA3")) {
            return 'video/webm';   // EBML/Matroska header
        }
        if ($ext === 'mp4' && strlen($raw) >= 12 && substr($raw, 4, 4) === 'ftyp') {
            return 'video/mp4';    // ISO base media 'ftyp' box
        }
        return '';
    }

    /**
     * @param array{token:string,full_name:string,thumb_name:string,mime:string,size:int,width:int,height:int,thumb_w:int,thumb_h:int,ahash:int,sha256:string,orig_name:string,spoiler:bool} $meta
     * @return Result<int>
     */
    public function persist(int $postId, array $meta): Result
    {
        return $this->repo->create($postId, $meta);
    }

    /**
     * Delete the stored files (rollback when the owning post fails to save).
     *
     * @param array{full_name:string,thumb_name:string} $meta
     */
    public function discard(array $meta): void
    {
        $dir = $this->config->uploadDir();
        if ($dir === '') {
            return;
        }
        @unlink($dir . '/' . $meta['full_name']);
        @unlink($dir . '/' . $meta['thumb_name']);
    }

    /**
     * Images grouped by post id (for thread/catalog rendering).
     *
     * @param list<int> $postIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function forPosts(array $postIds): array
    {
        $r = $this->repo->forPosts($postIds);
        return $r->isOk() ? $r->unwrap() : [];
    }

    /** @return Result<never> */
    private function fail(): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardPostDiagnostic(
            'astrx.imageboard/image_failed', DiagnosticLevel::WARNING
        )));
    }
}
