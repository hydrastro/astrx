<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Config\InjectConfig;
use AstrX\Http\UploadedFile;
use AstrX\Image\ImageOutputFormat;
use AstrX\Image\ImageSanitizeOptions;
use AstrX\Image\ImageSanitizer;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Session\ServerSecret;
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
 * When a user has no custom avatar and identicons are enabled, callers render
 * IdenticonRenderer::render(identiconSeed(...)) — never render($hexId) or any
 * other value an outsider can reconstruct. See identiconSeed().
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

    public function __construct(
        private readonly UserRepository $repo,
        private readonly ImageSanitizer $sanitizer,
        /**
         * REQUIRED on purpose — do not give this a default. Injector::createClass()
         * SKIPS optional parameters, so a default would hand this class a private
         * ServerSecret that never receives Session.config.php's `server_secret`,
         * and identicons would be keyed differently from sessions on any install
         * that configures one.
         */
        private readonly ServerSecret   $secret,
    ) {}

    /**
     * The seed for a registered user's fallback identicon.
     *
     * Keyed with the install's server secret, because the identicon is otherwise
     * an oracle for the user's recovery e-mail. The previous seed was the plain
     * concatenation $hexId . $email, and IdenticonRenderer::render() is a bare
     * sha256() of its input, so the PNG is a pure function of public data plus
     * one guess: an attacker reads alice's 32-hex uid from any page that renders
     * her avatar, fetches /avatar/<uid>, calibrates the renderer's geometry
     * against the public ?seed= endpoint, then renders sha256(uid . guess)
     * locally for each address they suspect. A byte-equal PNG CONFIRMS the
     * address — on a hidden service whose premise is anonymity, that turns a
     * guess into a certainty. With an HMAC the attacker cannot compute the seed
     * without the install secret, so no local render can ever match.
     *
     * Still deterministic per install, so a user's identicon is stable, and
     * still distinct per e-mail, so two users who end up sharing a username
     * after a rename keep different pictures.
     */
    public function identiconSeed(string $hexId, string $email): string
    {
        // NUL separator: without it (hexId='ab', email='cd') and (hexId='abc',
        // email='d') would hash the same input. hexIds are fixed-length today,
        // but the separator makes that not matter.
        return hash_hmac('sha256', $hexId . "\0" . $email, $this->secret->bytes());
    }

    /**
     * Upload and store a new avatar for the given user.
     *
     * @return Result<bool>
     */
    public function setAvatar(string $hexId, UploadedFile $file): Result
    {
        if ($file->hasError()) {
            return $this->opErr('avatar_upload_error', (string) $file->error());
        }
        $raw = @file_get_contents($file->tempPath());
        if ($raw === false) {
            return $this->opErr('avatar_invalid');
        }
        $ext = strtolower(pathinfo($file->clientFilename(), PATHINFO_EXTENSION));

        // Validate + strip metadata via the shared image sanitizer, re-encoding
        // to PNG with no downscale (the historical avatar behaviour). Size / type
        // / decode rejections surface as astrx.image/* diagnostics.
        $res = $this->sanitizer->sanitize($raw, $ext, new ImageSanitizeOptions(
            allowedExtensions: ['gif', 'png', 'jpeg', 'jpg', 'webp'],
            maxBytes:          $this->maxSize,
            maxDimension:      0,
            outputFormat:      ImageOutputFormat::PNG,
        ));
        if (!$res->isOk()) {
            return Result::err(false, $res->diagnostics());
        }
        $png = $res->unwrap();

        $dir      = $this->avatarDir();
        $destPath = $dir . '/' . $hexId . '.png';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->opErr('avatar_move_failed', $dir);
        }
        if (@file_put_contents($destPath, $png->fullBytes) === false) {
            return $this->opErr('avatar_move_failed', $destPath);
        }

        return $this->repo->setAvatar($hexId, true);
    }

    /**
     * Remove the custom avatar file and update the DB flag.
     *
     * @return Result<bool>
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
     * Resolve the avatar storage directory portably: prefer the configured path
     * (e.g. Docker "/app/resources/avatar"), else fall back to RESOURCES_DIR/avatar
     * so a non-Docker deploy doesn't fail every avatar write.
     */
    private function avatarDir(): string
    {
        return \AstrX\Support\resourceStorageDir($this->avatarDir, 'avatar');
    }

    /**
     * Full filesystem path for a user's avatar PNG.
     */
    public function pathFor(string $hexId): string
    {
        return $this->avatarDir() . '/' . $hexId . '.png';
    }

    /**
     * Whether the avatar file exists on disk.
     */
    public function exists(string $hexId): bool
    {
        return file_exists($this->pathFor($hexId));
    }

    // -------------------------------------------------------------------------

    /** @return Result<never> */
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
