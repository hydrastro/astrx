<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Config\InjectConfig;

/**
 * Central, admin-editable imageboard configuration — GLOBAL defaults only.
 *
 * Per-BOARD settings (limits, flags mode, lifecycle, forced-anon, …) live on
 * the `board` DB row, because a config holder is a singleton and cannot vary
 * per board. This holder carries the module-wide defaults: image-upload
 * constraints, the anonymous label, the anonymous-post captcha policy, the flag
 * asset base path, and index pagination. Bound to the 'ImageboardConfig'
 * section of Imageboard.config.php via #[InjectConfig]; setters clamp to safe
 * ranges so a bad edit cannot produce a zero dimension or negative cap.
 */
final class ImageboardConfig
{
    private bool   $enabled           = true;
    private string $uploadDir         = '/app/resources/board_uploads';
    private int    $uploadMaxKb       = 4096;
    private int    $uploadMaxPixels   = 16_000_000; // header pixel-budget: reject decompression bombs pre-decode
    private int    $fullMaxDimension  = 1600;   // full image downscaled to fit this box
    private int    $thumbMaxDimension = 250;    // thumbnail longest side
    private string $uploadTypesRaw    = 'jpg,jpeg,png,gif,webp';
    private string $anonName          = 'Anonymous';
    private bool   $guestCaptcha      = true;   // captcha-gate anonymous posts
    private bool   $storePosterIp     = false;  // onion-first: don't persist poster IP at rest by default
    private int    $defaultMaxReplies = 500;    // thread auto-locks past this (0 = unlimited)
    private string $flagBasePath      = '/flags';
    private int    $threadsPerPage    = 10;
    private int    $previewReplies    = 5;      // replies shown under each thread on the index

    #[InjectConfig('enabled')]             public function setEnabled(bool $v): void          { $this->enabled = $v; }
    #[InjectConfig('upload_dir')]          public function setUploadDir(string $v): void      { $this->uploadDir = rtrim(trim($v), '/\\'); }
    #[InjectConfig('upload_max_kb')]       public function setUploadMaxKb(int $v): void       { $this->uploadMaxKb = max(1, $v); }
    #[InjectConfig('upload_max_pixels')]   public function setUploadMaxPixels(int $v): void   { $this->uploadMaxPixels = max(1_000_000, $v); }
    #[InjectConfig('full_max_dimension')]  public function setFullMaxDimension(int $v): void  { $this->fullMaxDimension = max(64, $v); }
    #[InjectConfig('thumb_max_dimension')] public function setThumbMaxDimension(int $v): void { $this->thumbMaxDimension = max(32, $v); }
    #[InjectConfig('upload_types')]        public function setUploadTypesRaw(string $v): void { $this->uploadTypesRaw = trim($v); }
    #[InjectConfig('anon_name')]           public function setAnonName(string $v): void       { $t = trim($v); $this->anonName = $t !== '' ? $t : 'Anonymous'; }
    #[InjectConfig('guest_captcha')]       public function setGuestCaptcha(bool $v): void     { $this->guestCaptcha = $v; }
    #[InjectConfig('store_poster_ip')]     public function setStorePosterIp(bool $v): void    { $this->storePosterIp = $v; }
    #[InjectConfig('default_max_replies')] public function setDefaultMaxReplies(int $v): void { $this->defaultMaxReplies = max(0, $v); }
    #[InjectConfig('flag_base_path')]      public function setFlagBasePath(string $v): void   { $this->flagBasePath = rtrim(trim($v), '/'); }
    #[InjectConfig('threads_per_page')]    public function setThreadsPerPage(int $v): void    { $this->threadsPerPage = max(1, $v); }
    #[InjectConfig('preview_replies')]     public function setPreviewReplies(int $v): void    { $this->previewReplies = max(0, $v); }

    public function enabled(): bool          { return $this->enabled; }
    public function uploadDir(): string      { return $this->uploadDir; }
    public function uploadMaxKb(): int       { return $this->uploadMaxKb; }
    public function uploadMaxBytes(): int    { return $this->uploadMaxKb * 1024; }
    public function uploadMaxPixels(): int   { return $this->uploadMaxPixels; }
    public function fullMaxDimension(): int  { return $this->fullMaxDimension; }
    public function thumbMaxDimension(): int { return $this->thumbMaxDimension; }
    public function anonName(): string       { return $this->anonName; }
    public function guestCaptcha(): bool     { return $this->guestCaptcha; }
    public function storePosterIp(): bool    { return $this->storePosterIp; }
    public function defaultMaxReplies(): int { return $this->defaultMaxReplies; }
    public function flagBasePath(): string   { return $this->flagBasePath; }
    public function threadsPerPage(): int    { return $this->threadsPerPage; }
    public function previewReplies(): int    { return $this->previewReplies; }

    /**
     * Normalised, lower-cased list of allowed upload extensions.
     *
     * @return list<string>
     */
    public function uploadTypes(): array
    {
        $out = [];
        foreach (explode(',', strtolower($this->uploadTypesRaw)) as $t) {
            $t = trim($t);
            if ($t !== '') { $out[] = $t; }
        }
        return $out;
    }
}
