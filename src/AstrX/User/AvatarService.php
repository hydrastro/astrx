<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Config\InjectConfig;
use AstrX\Http\UploadedFile;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\User\Diagnostic\UserAvatarSizeDiagnostic;
use AstrX\User\Diagnostic\UserAvatarExtensionDiagnostic;
use AstrX\User\Diagnostic\UserAvatarInvalidDiagnostic;
use AstrX\User\Diagnostic\UserAvatarUploadErrorDiagnostic;
use AstrX\User\Diagnostic\UserAvatarMoveFailedDiagnostic;

/**
 * Filesystem avatar operations.
 *
 * Avatars are stored as PNG files in a configurable directory.
 * Path pattern: {avatar_dir}/{hex_user_id}.png
 *
 * When a user has no custom avatar and identicons are enabled,
 * callers should use IdenticonRenderer::render($hexId) for display.
 */
final class AvatarService
{
    private string $avatarDir   = '';
    private int    $maxSize     = 1048576; // 1 MB
    private bool   $useIdenticons = false;

    #[InjectConfig('avatar_dir')]
    public function setAvatarDir(string $v): void
    {
        $this->avatarDir = rtrim($v, '/\\');
    }

    #[InjectConfig('avatar_file_size')]
    public function setMaxSize(int $v): void { $this->maxSize = max(1024, $v); }

    #[InjectConfig('use_identicons')]
    public function setUseIdenticons(bool $v): void { $this->useIdenticons = $v; }

    public function useIdenticons(): bool { return $this->useIdenticons; }

    // -------------------------------------------------------------------------

    public function __construct(private readonly UserRepository $repo) {}

    /**
     * Upload and store a new avatar for the given user.
     *
     * @return Result<true>
     */
    public function setAvatar(string $hexId, UploadedFile $file): Result
    {
        if ($file->hasError()) {
            return $this->opErr('avatar_upload_error', (string) $file->error());
        }

        if ($file->size() > $this->maxSize) {
            return $this->opErr('avatar_size', (string) $this->maxSize);
        }

        $ext = strtolower(pathinfo($file->clientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['gif', 'png', 'jpeg', 'jpg', 'webp'], true)) {
            return $this->opErr('avatar_extension', $ext);
        }

        // Verify it is actually an image using getimagesize() — part of GD,
        // no separate PHP extension required (unlike exif_imagetype).
        if (@getimagesize($file->tempPath()) === false) {
            return $this->opErr('avatar_invalid');
        }

        // Re-encode as PNG to strip any metadata / malicious payloads
        $srcImage = imagecreatefromstring((string) file_get_contents($file->tempPath()));
        if ($srcImage === false) {
            return $this->opErr('avatar_invalid');
        }

        $destPath = $this->pathFor($hexId);

        // Ensure the avatar directory exists and is writable.
        if (!is_dir($this->avatarDir)) {
            if (!mkdir($this->avatarDir, 0775, true)) {
                imagedestroy($srcImage);
                return $this->opErr('avatar_move_failed', $this->avatarDir);
            }
        }

        if (!imagepng($srcImage, $destPath)) {
            imagedestroy($srcImage);
            return $this->opErr('avatar_move_failed', $destPath);
        }
        imagedestroy($srcImage);

        return $this->repo->setAvatar($hexId, true);
    }

    /**
     * Remove the custom avatar file and update the DB flag.
     *
     * @return Result<true>
     */
    public function removeAvatar(string $hexId): Result
    {
        $path = $this->pathFor($hexId);
        if (file_exists($path)) {
            @unlink($path);
        }
        return $this->repo->setAvatar($hexId, false);
    }

    /**
     * Full filesystem path for a user's avatar PNG.
     */
    public function pathFor(string $hexId): string
    {
        return $this->avatarDir . '/' . $hexId . '.png';
    }

    /**
     * Whether the avatar file exists on disk.
     */
    public function exists(string $hexId): bool
    {
        return file_exists($this->pathFor($hexId));
    }

    // -------------------------------------------------------------------------

    private function opErr(string $op, string $detail = ''): Result
    {
        $diagnostic = match ($op) {
            'avatar_size'         => new UserAvatarSizeDiagnostic('astrx.user/avatar_size', DiagnosticLevel::NOTICE),
            'avatar_extension'    => new UserAvatarExtensionDiagnostic('astrx.user/avatar_extension', DiagnosticLevel::NOTICE),
            'avatar_invalid'      => new UserAvatarInvalidDiagnostic('astrx.user/avatar_invalid', DiagnosticLevel::NOTICE),
            'avatar_upload_error' => new UserAvatarUploadErrorDiagnostic('astrx.user/avatar_upload_error', DiagnosticLevel::ERROR, $detail),
            'avatar_move_failed'  => new UserAvatarMoveFailedDiagnostic('astrx.user/avatar_move_failed', DiagnosticLevel::ERROR),
            default               => new UserAvatarInvalidDiagnostic('astrx.user/avatar_unknown', DiagnosticLevel::ERROR),
        };
        return Result::err(false, Diagnostics::of($diagnostic));
    }
}