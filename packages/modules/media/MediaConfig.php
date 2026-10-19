<?php
declare(strict_types=1);

namespace AstrX\Media;

use AstrX\Config\InjectConfig;

/**
 * Central, admin-editable media-library configuration.
 *
 * Carries the upload constraints for the general-purpose media manager: the
 * on-disk upload directory, the byte/pixel caps that bound a re-encode (and stop
 * a decompression bomb), the full-image downscale ceiling, and the accepted
 * upload extensions. Bound to the 'MediaConfig' section of Media.config.php via
 * #[InjectConfig]; setters clamp to safe ranges so a bad edit can never yield a
 * zero dimension or a negative cap.
 *
 * Mirrors ImageboardConfig — the media library re-uses the exact same shared
 * ImageSanitizer, so its knobs map 1:1 onto ImageSanitizeOptions.
 */
final class MediaConfig
{
    private string $uploadDir        = '/app/resources/media_uploads';
    private int    $uploadMaxKb      = 4096;
    private int    $uploadMaxPixels  = 16_000_000; // header pixel-budget: reject decompression bombs pre-decode
    private int    $fullMaxDimension = 2048;       // full image downscaled to fit this box on re-encode
    private string $uploadTypesRaw   = 'jpg,jpeg,png,gif,webp';

    #[InjectConfig('upload_dir')]         public function setUploadDir(string $v): void      { $this->uploadDir = rtrim(trim($v), '/\\'); }
    #[InjectConfig('upload_max_kb')]      public function setUploadMaxKb(int $v): void       { $this->uploadMaxKb = max(1, $v); }
    #[InjectConfig('upload_max_pixels')]  public function setUploadMaxPixels(int $v): void   { $this->uploadMaxPixels = max(1_000_000, $v); }
    #[InjectConfig('full_max_dimension')] public function setFullMaxDimension(int $v): void  { $this->fullMaxDimension = max(64, $v); }
    #[InjectConfig('upload_types')]       public function setUploadTypesRaw(string $v): void { $this->uploadTypesRaw = trim($v); }

    public function uploadDir(): string     { return \AstrX\Support\resourceStorageDir($this->uploadDir, 'media_uploads'); }
    public function uploadMaxKb(): int      { return $this->uploadMaxKb; }
    public function uploadMaxBytes(): int   { return $this->uploadMaxKb * 1024; }
    public function uploadMaxPixels(): int  { return $this->uploadMaxPixels; }
    public function fullMaxDimension(): int { return $this->fullMaxDimension; }

    /**
     * The image extensions the media file controller can always serve — the
     * re-encode only ever emits jpg or png, but gif/webp are accepted as INPUT
     * and re-encoded. 'jpeg' is an accepted alias (relabelled to jpg).
     */
    private const SERVABLE_UPLOAD_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Normalised, lower-cased list of allowed upload extensions.
     *
     * Constrained to the SERVABLE set: an admin-added type outside it (e.g.
     * bmp/tiff) is dropped here rather than accepted and then stored unservable.
     * Never returned empty — the sanitizer treats [] as "allow any extension",
     * which would re-open the hole — so a config with no servable type falls back
     * to the full servable set. Mirrors ImageboardConfig::uploadTypes().
     *
     * @return list<string>
     */
    public function uploadTypes(): array
    {
        $out = [];
        foreach (explode(',', strtolower($this->uploadTypesRaw)) as $t) {
            $t = trim($t);
            if ($t !== '' && in_array($t, self::SERVABLE_UPLOAD_TYPES, true) && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out !== [] ? $out : self::SERVABLE_UPLOAD_TYPES;
    }
}
