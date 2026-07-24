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
        private readonly ImageRepository  $repo,
        private readonly ImageSanitizer   $sanitizer,
        private readonly ImageboardConfig $config,
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

        $res = $this->sanitizer->sanitize($raw, $ext, new ImageSanitizeOptions(
            allowedExtensions:  $this->config->uploadTypes(),
            maxBytes:           $this->config->uploadMaxBytes(),
            maxDimension:       $this->config->fullMaxDimension(),
            outputFormat:       ImageOutputFormat::AUTO,
            jpegQuality:        85,
            makeThumbnail:      true,
            thumbMaxDimension:  $this->config->thumbMaxDimension(),
            computeAverageHash: true,
            computeSha256:      true,
        ));
        if (!$res->isOk()) {
            return Result::err($res->error(), $res->diagnostics());
        }
        $img = $res->unwrap();

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
