<?php
declare(strict_types=1);

namespace AstrX\Media;

use AstrX\Http\UploadedFile;
use AstrX\I18n\Translator;
use AstrX\Image\ImageOutputFormat;
use AstrX\Image\ImageSanitizeOptions;
use AstrX\Image\ImageSanitizer;
use AstrX\Media\Diagnostic\MediaUploadDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;

/**
 * Media library service — a thin consumer of the shared ImageSanitizer, exactly
 * like the imageboard's ImageService.
 *
 * Every uploaded image is validated + RE-ENCODED through ImageSanitizer: the
 * re-encode IS the metadata strip (EXIF/GPS/comments discarded, polyglots
 * neutralised), the header pixel budget rejects decompression bombs before the
 * bitmap is decoded, and the byte cap bounds the work. Only the sniffed,
 * decodable set (jpg/jpeg/png/gif/webp) is accepted; the client MIME is never
 * trusted. Storage and naming live here: the stored `name` is always our own
 * path-safe token, never a client value.
 *
 * @phpstan-type MediaRow array{id:int,name:string,orig_name:string,mime:string,ext:string,size:int,sha256:string,width:int,height:int,created_by:?string,created_at:string}
 */
final class MediaService
{
    public function __construct(
        private readonly MediaRepository $repo,
        private readonly ImageSanitizer  $sanitizer,
        private readonly MediaConfig     $config,
        private readonly UrlGenerator    $urlGen,
        private readonly Translator      $t,
    ) {}

    /**
     * Validate + re-encode an uploaded image and persist it. Returns the stored
     * row (with its DB id, generated name and dimensions).
     *
     * @return Result<array{id:int,name:string,orig_name:string,mime:string,ext:string,size:int,sha256:string,width:int,height:int,created_by:?string,created_at:string}>
     */
    public function store(UploadedFile $file, ?string $adminHexId): Result
    {
        if ($file->hasError()) {
            return $this->fail();
        }
        $raw = @file_get_contents($file->tempPath());
        if ($raw === false) {
            return $this->fail();
        }
        $ext = strtolower(pathinfo($file->clientFilename(), PATHINFO_EXTENSION));

        // Validate + strip through the SAME shared sanitizer the imageboard uses.
        $res = $this->sanitizer->sanitize($raw, $ext, new ImageSanitizeOptions(
            allowedExtensions:  $this->config->uploadTypes(),
            maxBytes:           $this->config->uploadMaxBytes(),
            maxPixels:          $this->config->uploadMaxPixels(),
            maxDimension:       $this->config->fullMaxDimension(),
            outputFormat:       ImageOutputFormat::AUTO,
            jpegQuality:        85,
            makeThumbnail:      false,   // no thumb column: the admin grid previews the full image
            thumbMaxDimension:  250,
            computeAverageHash: false,
            computeSha256:      true,
            stripMetadata:      true,    // always strip: onion/privacy default
        ));
        if (!$res->isOk()) {
            return Result::err(null, $res->diagnostics());
        }
        $img = $res->unwrap();

        $dir = $this->config->uploadDir();
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0775, true))) {
            return $this->fail();
        }

        // Generate a path-safe, unique stored name from the original filename.
        // Retry on the (astronomically unlikely) random collision.
        $name = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $this->generateStoreName($file->clientFilename(), $img->ext);
            if (!is_file($dir . '/' . $candidate)) {
                $name = $candidate;
                break;
            }
        }
        if ($name === '') {
            return $this->fail();
        }

        if (@file_put_contents($dir . '/' . $name, $img->fullBytes) === false) {
            @unlink($dir . '/' . $name);
            return $this->fail();
        }

        $insert = $this->repo->insert([
            'name'       => $name,
            'orig_name'  => $this->trimTo($file->clientFilename(), 255),
            'mime'       => $img->mime,
            'ext'        => $img->ext,
            'size'       => strlen($img->fullBytes),
            'sha256'     => $img->sha256 ?? '',
            'width'      => $img->width,
            'height'     => $img->height,
            'created_by' => $adminHexId,
        ]);
        if (!$insert->isOk()) {
            @unlink($dir . '/' . $name); // roll back the orphaned file
            return Result::err(null, $insert->diagnostics());
        }

        $id   = $insert->unwrap();
        $read = $this->repo->byId($id);
        if ($read->isOk()) {
            $out = $read->unwrap();
            if (is_array($out)) {
                return Result::ok($out);
            }
        }

        // Fall back to a synthesised row if the read-back failed (write succeeded).
        return Result::ok([
            'id'         => $id,
            'name'       => $name,
            'orig_name'  => $this->trimTo($file->clientFilename(), 255),
            'mime'       => $img->mime,
            'ext'        => $img->ext,
            'size'       => strlen($img->fullBytes),
            'sha256'     => $img->sha256 ?? '',
            'width'      => $img->width,
            'height'     => $img->height,
            'created_by' => $adminHexId,
            'created_at' => '',
        ]);
    }

    /**
     * All stored media, newest first.
     *
     * @return Result<list<array{id:int,name:string,orig_name:string,mime:string,ext:string,size:int,sha256:string,width:int,height:int,created_by:?string,created_at:string}>>
     */
    public function list(): Result
    {
        return $this->repo->all();
    }

    /**
     * Rename a media item. The admin edits only the base name — the stored
     * extension is preserved so the file stays servable and its MIME still
     * matches. The DB is updated first (UNIQUE-enforced) then the on-disk file
     * is renamed; a failed disk rename rolls the DB change back.
     *
     * ok(true) success, ok(false) rejected (missing id / empty or taken name),
     * err(false) on a DB/IO error.
     *
     * @return Result<bool>
     */
    public function rename(int $id, string $newBase): Result
    {
        $found = $this->repo->byId($id);
        if (!$found->isOk()) {
            return Result::err(false, $found->diagnostics());
        }
        $row = $found->unwrap();
        if (!is_array($row)) {
            return Result::ok(false); // no such id
        }
        /** @var array{name:string,ext:string} $row */
        $oldName = $row['name'];
        $newName = $this->safeRenameName($newBase, $row['ext']);
        if ($newName === '') {
            return Result::ok(false); // empty / invalid after slugify
        }
        if ($newName === $oldName) {
            return Result::ok(true);  // no change
        }

        $r = $this->repo->rename($id, $newName);
        if (!$r->isOk()) {
            return Result::err(false, $r->diagnostics());
        }
        if ($r->unwrap() === false) {
            return Result::ok(false); // name already taken
        }

        // DB renamed — now move the file to match. On failure, roll the DB back
        // so the row keeps pointing at the file that is still on disk.
        $oldPath = $this->filePath($oldName);
        $newPath = $this->filePath($newName);
        if ($oldPath !== '' && $newPath !== '' && is_file($oldPath)) {
            if (!@rename($oldPath, $newPath)) {
                $this->repo->rename($id, $oldName);
                return Result::err(false, $this->uploadDiag());
            }
        }
        return Result::ok(true);
    }

    /**
     * Delete a media item: remove the DB row, then unlink its file from disk.
     * ok(true) deleted, ok(false) no such id, err(false) on a DB error.
     *
     * @return Result<bool>
     */
    public function delete(int $id): Result
    {
        $r = $this->repo->delete($id);
        if (!$r->isOk()) {
            return Result::err(false, $r->diagnostics());
        }
        $row = $r->unwrap();
        if (!is_array($row)) {
            return Result::ok(false); // nothing to delete
        }
        /** @var array{name:string} $row */
        $path = $this->filePath($row['name']);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        return Result::ok(true);
    }

    /** Public URL to the media-file route for a stored name (query-param, mode-safe). */
    public function fileUrl(string $name): string
    {
        if (!$this->isSafeName($name)) {
            return '';
        }
        return $this->urlGen->toPage($this->t->t('WORDING_MEDIA_FILE', fallback: 'media'), ['name' => $name]);
    }

    /**
     * Confined absolute path to a stored file. Returns '' for any name that is
     * not a valid stored token — the strict pattern makes path traversal
     * impossible (no '/', no '..', no leading dot).
     */
    public function filePath(string $name): string
    {
        if (!$this->isSafeName($name)) {
            return '';
        }
        $dir = $this->config->uploadDir();
        if ($dir === '') {
            return '';
        }
        return $dir . '/' . $name;
    }

    // -------------------------------------------------------------------------

    /** Build a unique, path-safe stored name from an original filename. */
    private function generateStoreName(string $origFilename, string $ext): string
    {
        $base = $this->slugifyBase(pathinfo($origFilename, PATHINFO_FILENAME));
        $base = rtrim(substr($base, 0, 60), '-');
        $rand = bin2hex(random_bytes(6)); // 12 hex chars → collision-safe
        $stem = $base !== '' ? $base . '-' . $rand : $rand;
        return $stem . '.' . $this->safeExt($ext);
    }

    /** Build a path-safe rename target from the admin's base + the stored ext. */
    private function safeRenameName(string $base, string $ext): string
    {
        $slug = $this->slugifyBase(pathinfo($base, PATHINFO_FILENAME));
        $slug = rtrim(substr($slug, 0, 80), '-');
        if ($slug === '') {
            return '';
        }
        $name = $slug . '.' . $this->safeExt($ext);
        return $this->isSafeName($name) ? $name : '';
    }

    /** Lower-case a title/filename stem to a [a-z0-9-] slug. */
    private function slugifyBase(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** Clamp an extension to the servable set (re-encode only ever emits jpg/png). */
    private function safeExt(string $ext): string
    {
        $ext = strtolower(trim($ext));
        return in_array($ext, ['jpg', 'png', 'gif', 'webp'], true) ? $ext : 'jpg';
    }

    /** Strict stored-name whitelist: base of [a-z0-9-] + a servable extension. */
    private function isSafeName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,79}\.(?:jpg|png|gif|webp)$/', $name) === 1;
    }

    private function trimTo(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }

    /** @return Result<never> */
    private function fail(): Result
    {
        return Result::err(null, $this->uploadDiag());
    }

    private function uploadDiag(): Diagnostics
    {
        return Diagnostics::of(new MediaUploadDiagnostic(
            'astrx.media/upload_failed', DiagnosticLevel::WARNING
        ));
    }
}
