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
    private bool   $allowAuthPosts    = true;   // logged-in users may post under their account (no captcha)
    // Post-author name colour per role, keyed by UserGroup case name. Stored as a
    // compact "ROLE:colour,ROLE:colour" string so ConfigWriter (scalars only) can
    // persist it. Roles are matched by NAME, so a role added later just needs an
    // entry here; unlisted roles fall back to the theme's default name colour.
    private string $roleColorsRaw     = 'ADMIN:red,MOD:purple,USER:white';
    private bool   $stripExif         = true;   // re-encode uploads to strip EXIF/metadata (opt-out)
    private int    $maxFilesPerPost   = 4;      // max attachments per post (multi-file)
    private string $tripcodeSalt      = 'astrx'; // per-site salt so tripcodes are unique to this deployment
    private string $posterIdSalt      = 'astrx-pid'; // per-site salt for per-thread poster IDs
    private string $boardFlagsRaw     = '';     // "code:Label,code:Label" self-select flag set ('' = none)
    private string $censorWordsRaw    = '';     // comma list of literal terms to censor in post bodies
    private string $censorMode        = 'replace'; // 'replace' | 'block'
    private string $censorReplacement = '***';
    private bool   $reverseImageSearch = false; // per-image iqdb/SauceNAO links (off: third-party leak on Tor)
    private bool   $videoEnabled      = false;  // allow webm/mp4 attachments (HTML5 <video>, no thumbnails)
    private string $videoTypesRaw     = 'webm,mp4'; // accepted video extensions
    private int    $videoMaxKb        = 8192;   // hard per-video size cap (KB)

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
    #[InjectConfig('allow_authenticated_posts')] public function setAllowAuthPosts(bool $v): void { $this->allowAuthPosts = $v; }
    #[InjectConfig('role_colors')]         public function setRoleColorsRaw(string $v): void  { $this->roleColorsRaw = trim($v); }
    #[InjectConfig('strip_exif')]          public function setStripExif(bool $v): void        { $this->stripExif = $v; }
    #[InjectConfig('max_files_per_post')]  public function setMaxFilesPerPost(int $v): void    { $this->maxFilesPerPost = max(1, min(10, $v)); }
    #[InjectConfig('tripcode_salt')]       public function setTripcodeSalt(string $v): void    { $this->tripcodeSalt = $v; }
    #[InjectConfig('poster_id_salt')]      public function setPosterIdSalt(string $v): void    { $this->posterIdSalt = $v; }
    #[InjectConfig('board_flags')]         public function setBoardFlagsRaw(string $v): void   { $this->boardFlagsRaw = trim($v); }
    #[InjectConfig('censor_words')]        public function setCensorWordsRaw(string $v): void  { $this->censorWordsRaw = trim($v); }
    #[InjectConfig('censor_mode')]         public function setCensorMode(string $v): void      { $this->censorMode = $v === 'block' ? 'block' : 'replace'; }
    #[InjectConfig('censor_replacement')]  public function setCensorReplacement(string $v): void { $this->censorReplacement = $v; }
    #[InjectConfig('reverse_image_search')] public function setReverseImageSearch(bool $v): void { $this->reverseImageSearch = $v; }
    #[InjectConfig('video_enabled')]       public function setVideoEnabled(bool $v): void      { $this->videoEnabled = $v; }
    #[InjectConfig('video_types')]         public function setVideoTypesRaw(string $v): void   { $this->videoTypesRaw = trim($v); }
    #[InjectConfig('video_max_kb')]        public function setVideoMaxKb(int $v): void         { $this->videoMaxKb = max(1, $v); }

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

    /** Logged-in users may post under their account identity without a captcha. */
    public function allowAuthenticatedPosts(): bool { return $this->allowAuthPosts; }

    /** Re-encode uploads to strip EXIF/metadata (default on; opt-out per config). */
    public function stripExif(): bool { return $this->stripExif; }

    public function maxFilesPerPost(): int      { return $this->maxFilesPerPost; }
    public function tripcodeSalt(): string      { return $this->tripcodeSalt; }
    public function posterIdSalt(): string      { return $this->posterIdSalt; }
    public function censorMode(): string        { return $this->censorMode; }
    public function censorReplacement(): string { return $this->censorReplacement; }
    public function reverseImageSearch(): bool  { return $this->reverseImageSearch; }
    public function videoEnabled(): bool        { return $this->videoEnabled; }
    public function videoMaxBytes(): int        { return $this->videoMaxKb * 1024; }

    /**
     * Literal terms to censor in post bodies (blank entries dropped).
     *
     * @return list<string>
     */
    public function censorWords(): array
    {
        $out = [];
        foreach (explode(',', $this->censorWordsRaw) as $w) {
            $w = trim($w);
            if ($w !== '') { $out[] = $w; }
        }
        return $out;
    }

    /**
     * Self-select flag set as code → label (e.g. 'eu' => 'European Union').
     * Codes are lower-cased, kept to [a-z0-9_-], so a code is a safe CSS/URL token.
     *
     * @return array<string,string>
     */
    public function boardFlags(): array
    {
        $out = [];
        foreach (explode(',', $this->boardFlagsRaw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) { continue; }
            [$code, $label] = explode(':', $pair, 2);
            $code  = strtolower(trim($code));
            $label = trim($label);
            if ($label !== '' && preg_match('/^[a-z0-9_-]{1,16}$/', $code) === 1) {
                $out[$code] = $label;
            }
        }
        return $out;
    }

    /**
     * Normalised, lower-cased list of allowed video extensions.
     *
     * @return list<string>
     */
    public function videoTypes(): array
    {
        $out = [];
        foreach (explode(',', strtolower($this->videoTypesRaw)) as $t) {
            $t = trim($t);
            if ($t !== '') { $out[] = $t; }
        }
        return $out;
    }

    /** Raw "ROLE:colour,ROLE:colour" string (for the admin editor). */
    public function roleColorsRaw(): string { return $this->roleColorsRaw; }

    /**
     * Parsed role → colour map (role name uppercased; colour is a CSS colour word
     * or #hex, validated). Malformed entries are skipped.
     *
     * @return array<string,string>
     */
    public function roleColors(): array
    {
        $out = [];
        foreach (explode(',', $this->roleColorsRaw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) { continue; }
            [$role, $color] = explode(':', $pair, 2);
            $role  = strtoupper(trim($role));
            $color = trim($color);
            if ($role !== '' && preg_match('/^#[0-9a-f]{3,6}$|^[a-z]{1,20}$/i', $color) === 1) {
                $out[$role] = $color;
            }
        }
        return $out;
    }

    /** The configured colour for a role name, or '' if none (use theme default). */
    public function roleColor(string $role): string
    {
        return $this->roleColors()[strtoupper($role)] ?? '';
    }

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
