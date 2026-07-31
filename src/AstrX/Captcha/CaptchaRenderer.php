<?php
declare(strict_types=1);

namespace AstrX\Captcha;

use AstrX\Config\InjectConfig;

/**
 * GD-based captcha image renderer.
 *
 * Returns a base64-encoded GIF string ready to embed as:
 *   <img src="data:image/gif;base64,{{captcha_image}}">
 *
 * Three difficulty levels:
 *
 *   EASY   — single straight text render, no rotation, no distortion.
 *            Best for accessibility or low-friction contexts.
 *
 *   MEDIUM — chars placed alternately from left and right edges inward,
 *            each at a random y position and angle.
 *
 *   HARD   — fully random char placement, configurable number of decoy
 *            chars, and a red trace line connecting the real chars in
 *            order. The user must read the chars along the trace line.
 *
 * All visual settings are injectable via #[InjectConfig].
 */
final class CaptchaRenderer
{
    // -------------------------------------------------------------------------
    // Configuration (all injectable via #[InjectConfig])
    // -------------------------------------------------------------------------

    private int    $imageWidth  = 1;       // 0/too-small → auto-sized
    private int    $imageHeight = 1;

    private string $backgroundColor = '000000';
    private string $textColor       = 'ffffff';
    private string $linesColor      = 'ffffff';
    private string $dotsColor       = 'ffffff';

    private bool   $textColorRandom  = false;
    private bool   $linesColorRandom = false;
    private bool   $dotsColorRandom  = false;

    private int    $linesNumber          = 30;
    private bool   $linesStartFromBorder = true;
    private int    $dotsNumber           = 1000;

    private string $charList      = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    private int    $captchaLength = 5;

    private CaptchaType $captchaType  = CaptchaType::MEDIUM;
    private int         $fontSize     = 15;
    private string      $fontFile     = 'fonts/FSEX300.ttf';

    /** Memoized, portably-resolved absolute font path (see fontPath()). */
    private ?string $resolvedFont = null;

    private int $fontMinDistance = 0;
    private int $fontMaxDistance = 10;
    private int $fontMinAngle    = -45;
    private int $fontMaxAngle    = 45;
    private int $fontXBorder     = 5;
    private int $fontYBorder     = 5;

    private string $traceLineColor       = 'ff0000';
    private int    $nonCaptchaCharNumber = 5;

    private bool   $useBorderLinearRandomness = true;
    private int    $maxRoundsNumber           = 5000;

    // Opt-in: when true the HARD renderer runs the collision-avoidance search
    // (findFreePosition) so characters never overlap — but it can iterate up to
    // max_rounds_number times per character. Default false = a single fast
    // placement per char (chars stay inside the image but may occasionally
    // overlap), trading rare unreadable captchas for much better performance.
    private bool   $avoidCollisions           = false;

    // -------------------------------------------------------------------------
    // Config setters
    // -------------------------------------------------------------------------

    // Config values are admin-supplied; clamp to sane bounds so a typo (or a
    // compromised admin) can't request a multi-gigapixel canvas or an infinite
    // draw loop and OOM/hang the captcha endpoint (defence in depth).
    #[InjectConfig('image_width')]
    public function setImageWidth(int $v): void { $this->imageWidth = max(1, min(4096, $v)); }
    #[InjectConfig('image_height')]
    public function setImageHeight(int $v): void { $this->imageHeight = max(1, min(4096, $v)); }
    #[InjectConfig('background_color')]
    public function setBackgroundColor(string $v): void { $this->backgroundColor = ltrim($v, '#'); }
    #[InjectConfig('text_color')]
    public function setTextColor(string $v): void { $this->textColor = ltrim($v, '#'); }
    #[InjectConfig('lines_color')]
    public function setLinesColor(string $v): void { $this->linesColor = ltrim($v, '#'); }
    #[InjectConfig('dots_color')]
    public function setDotsColor(string $v): void { $this->dotsColor = ltrim($v, '#'); }
    #[InjectConfig('text_color_random')]
    public function setTextColorRandom(bool $v): void { $this->textColorRandom = $v; }
    #[InjectConfig('lines_color_random')]
    public function setLinesColorRandom(bool $v): void { $this->linesColorRandom = $v; }
    #[InjectConfig('dots_color_random')]
    public function setDotsColorRandom(bool $v): void { $this->dotsColorRandom = $v; }
    #[InjectConfig('lines_number')]
    public function setLinesNumber(int $v): void { $this->linesNumber = max(0, min(100000, $v)); }
    #[InjectConfig('lines_start_from_border')]
    public function setLinesStartFromBorder(bool $v): void { $this->linesStartFromBorder = $v; }
    #[InjectConfig('dots_number')]
    public function setDotsNumber(int $v): void { $this->dotsNumber = max(0, min(100000, $v)); }
    #[InjectConfig('char_list')]
    public function setCharList(string $v): void { if ($v !== '') $this->charList = $v; }
    #[InjectConfig('captcha_length')]
    public function setCaptchaLength(int $v): void { if ($v > 0) $this->captchaLength = min(64, $v); }
    #[InjectConfig('captcha_type')]
    public function setCaptchaType(int $v): void { $this->captchaType = CaptchaType::tryFrom($v) ?? CaptchaType::MEDIUM; }
    #[InjectConfig('font_size')]
    public function setFontSize(int $v): void { if ($v > 0) $this->fontSize = min(512, $v); }
    #[InjectConfig('font_file')]
    public function setFontFile(string $v): void
    {
        // Store the configured value verbatim (do NOT reject non-existent paths
        // here): a config may carry a deploy-specific absolute path — e.g. a
        // Docker "/app/resources/fonts/…" — that is absent on a bare CI runner.
        // fontPath() does the real resolution and falls back to the bundled
        // font by basename, so a wrong absolute path no longer breaks rendering.
        if ($v !== '') {
            $this->fontFile = $v;
        }
        $this->resolvedFont = null; // invalidate memo
    }
    #[InjectConfig('font_min_distance')]
    public function setFontMinDistance(int $v): void { $this->fontMinDistance = $v; }
    #[InjectConfig('font_max_distance')]
    public function setFontMaxDistance(int $v): void { $this->fontMaxDistance = $v; }
    #[InjectConfig('font_min_angle')]
    public function setFontMinAngle(int $v): void { $this->fontMinAngle = $v; }
    #[InjectConfig('font_max_angle')]
    public function setFontMaxAngle(int $v): void { $this->fontMaxAngle = $v; }
    #[InjectConfig('font_x_border')]
    public function setFontXBorder(int $v): void { $this->fontXBorder = $v; }
    #[InjectConfig('font_y_border')]
    public function setFontYBorder(int $v): void { $this->fontYBorder = $v; }
    #[InjectConfig('trace_line_color')]
    public function setTraceLineColor(string $v): void { $this->traceLineColor = ltrim($v, '#'); }
    #[InjectConfig('non_captcha_char_number')]
    public function setNonCaptchaCharNumber(int $v): void { $this->nonCaptchaCharNumber = max(0, $v); }
    #[InjectConfig('use_border_linear_randomness')]
    public function setUseBorderLinearRandomness(bool $v): void { $this->useBorderLinearRandomness = $v; }
    #[InjectConfig('max_rounds_number')]
    public function setMaxRoundsNumber(int $v): void { $this->maxRoundsNumber = max(1, $v); }
    #[InjectConfig('avoid_collisions')]
    public function setAvoidCollisions(bool $v): void { $this->avoidCollisions = $v; }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate a random captcha text string.
     * Uses random_int() — cryptographically secure, no bias.
     */
    public function generateText(): string
    {
        $chars  = $this->charList;
        $len    = strlen($chars) - 1;
        $result = '';

        while (strlen($result) < $this->captchaLength) {
            $result .= $chars[random_int(0, max(0, $len))];
        }

        return $result;
    }

    /**
     * Render a captcha image for the given text.
     * Returns a base64-encoded GIF string.
     */
    public function render(string $text): string
    {
        $image = match ($this->captchaType) {
            CaptchaType::EASY   => $this->renderEasy($text),
            CaptchaType::MEDIUM => $this->renderMedium($text),
            CaptchaType::HARD   => $this->renderHard($text),
        };

        $this->addLines($image);
        $this->addDots($image);

        ob_start();
        imagegif($image);
        imagedestroy($image);

        return base64_encode((string) ob_get_clean());
    }

    // -------------------------------------------------------------------------
    // Renderers
    // -------------------------------------------------------------------------

    /** @return \GdImage */
    private function renderEasy(string $text): \GdImage
    {
        $bbox = $this->bbox($text);
        $strW = abs($bbox[2]);
        $strH = abs($bbox[5]);

        $w = max($this->imageWidth,  $strW + 2 * $this->fontXBorder);
        $h = max($this->imageHeight, $strH + 2 * $this->fontYBorder);

        $image = $this->createBackground($w, $h);
        $color = $this->allocateColor($image, $this->textColor);

        imagettftext(
            $image,
            $this->fontSize,
            0,
            $this->fontXBorder,
            $this->fontYBorder + $strH,
            $color,
            $this->fontPath(),
            $text,
        );

        return $image;
    }

    /** @return \GdImage */
    private function renderMedium(string $text): \GdImage
    {
        $charCount = strlen($text);
        $diagonal  = 0.0;

        for ($i = 0; $i < $charCount; $i++) {
            $bb = $this->bbox($text[$i]);
            $d  = sqrt($bb[2] ** 2 + abs($bb[5]) ** 2);
            if ($d > $diagonal) {
                $diagonal = $d;
            }
        }
        $diagonal = (int) ceil($diagonal);

        $minW = 2 * $this->fontXBorder
              + $charCount * $diagonal * 2
              + ($charCount - 1) * $this->fontMaxDistance;
        $minH = 2 * $this->fontYBorder + 2 * $diagonal;

        $w = max($this->imageWidth,  $minW);
        $h = max($this->imageHeight, $minH);

        $image     = $this->createBackground($w, $h);
        $fontColor = $this->allocateColor($image, $this->textColor);

        // Place chars alternately from the left and right edges inward.
        // xl advances rightward, xr advances leftward, each by its own
        // independent random jump (fixed: original cross-coupled the jumps).
        $xl = $this->fontXBorder + $diagonal;
        $xr = $w - $this->fontXBorder - $diagonal;

        for ($i = 0; $i < (int) ceil($charCount / 2); $i++) {
            if ($this->textColorRandom) {
                $fontColor = $this->allocateColor($image);
            }

            $angleL = random_int($this->fontMinAngle, $this->fontMaxAngle);
            $angleR = random_int($this->fontMinAngle, $this->fontMaxAngle);
            $jumpL  = random_int($this->fontMinDistance, $this->fontMaxDistance);
            $jumpR  = random_int($this->fontMinDistance, $this->fontMaxDistance);

            if ($i > 0) {
                // Advance each side by its own jump + one char-width
                $xl += $jumpL + $diagonal * 2;
                $xr -= $jumpR + $diagonal * 2;
            }

            $yL = random_int(
                $this->fontYBorder + $diagonal,
                $h - $this->fontYBorder - $diagonal,
            );
            $yR = random_int(
                $this->fontYBorder + $diagonal,
                $h - $this->fontYBorder - $diagonal,
            );

            imagettftext($image, $this->fontSize, $angleL, $xl, $yL, $fontColor, $this->fontPath(), $text[$i]);

            $mirrorIdx = $charCount - $i - 1;
            if ($mirrorIdx !== $i) {
                imagettftext($image, $this->fontSize, $angleR, $xr, $yR, $fontColor, $this->fontPath(), $text[$mirrorIdx]);
            }
        }

        return $image;
    }

    /** @return \GdImage */
    private function renderHard(string $text): \GdImage
    {
        $charCount = strlen($text);
        $diagonal  = 0.0;

        for ($i = 0; $i < $charCount; $i++) {
            $bb = $this->bbox($text[$i]);
            $d  = sqrt($bb[2] ** 2 + abs($bb[5]) ** 2);
            if ($d > $diagonal) {
                $diagonal = $d;
            }
        }
        $diagonal = (int) ceil($diagonal);

        $totalChars = $charCount + $this->nonCaptchaCharNumber;
        $gridSize   = (int) ceil(sqrt($totalChars)) * 2 * 2 * $diagonal;

        $minW = $gridSize + 2 * $this->fontXBorder;
        $minH = $gridSize + 2 * $this->fontYBorder;
        $w    = max($this->imageWidth,  $minW);
        $h    = max($this->imageHeight, $minH);

        $image      = $this->createBackground($w, $h);
        $fontColor  = $this->allocateColor($image, $this->textColor);
        $traceColor = $this->allocateColor($image, $this->traceLineColor);

        $xMin = $this->fontXBorder + $diagonal;
        $xMax = $w - $this->fontXBorder - $diagonal;
        $yMin = $this->fontYBorder + $diagonal;
        $yMax = $h - $this->fontYBorder - $diagonal;

        $placed = [];

        // Place real captcha chars and draw the trace line connecting them.
        for ($i = 0; $i < $charCount; $i++) {
            if ($this->textColorRandom) {
                $fontColor = $this->allocateColor($image);
            }

            [$cx, $cy] = $this->findFreePosition($placed, $diagonal, $xMin, $xMax, $yMin, $yMax);

            if ($cx === null) {
                // Too crowded — fall back to MEDIUM
                return $this->renderMedium($text);
            }
            assert($cy !== null);

            $angle = random_int($this->fontMinAngle, $this->fontMaxAngle);
            imagettftext($image, $this->fontSize, $angle, $cx, $cy, $fontColor, $this->fontPath(), $text[$i]);

            if ($i === 0) {
                imagearc($image, $cx, $cy, $diagonal * 2, $diagonal * 2, 0, 360, $traceColor);
            } else {
                $prev = $placed[count($placed) - 1];
                imageline($image, $cx, $cy, $prev[0], $prev[1], $traceColor);
            }

            $placed[] = [$cx, $cy];
        }

        // Place decoy chars (no trace line).
        $charLen = strlen($this->charList) - 1;
        for ($i = 0; $i < $this->nonCaptchaCharNumber; $i++) {
            if ($this->textColorRandom) {
                $fontColor = $this->allocateColor($image);
            }

            [$cx, $cy] = $this->findFreePosition($placed, $diagonal, $xMin, $xMax, $yMin, $yMax);

            if ($cx === null) {
                break; // skip remaining decoys if no space
            }
            assert($cy !== null);

            $angle     = random_int($this->fontMinAngle, $this->fontMaxAngle);
            $decoyChar = $this->charList[random_int(0, max(0, $charLen))];
            imagettftext($image, $this->fontSize, $angle, $cx, $cy, $fontColor, $this->fontPath(), $decoyChar);

            $placed[] = [$cx, $cy];
        }

        return $image;
    }

    // -------------------------------------------------------------------------
    // Overlays
    // -------------------------------------------------------------------------

    /** @param \GdImage $image */
    private function addLines(\GdImage $image): void
    {
        if ($this->linesNumber === 0) {
            return;
        }

        $w     = imagesx($image);
        $h     = imagesy($image);
        $color = $this->allocateColor($image, $this->linesColor);

        for ($i = 0; $i < $this->linesNumber; $i++) {
            if ($this->linesColorRandom) {
                $color = $this->allocateColor($image);
            }

            $start  = $this->randomBorder($w, $h);
            $finish = $this->linesStartFromBorder
                ? $this->randomBorder($w, $h)
                : [random_int(0, $w - 1), random_int(0, $h - 1)];
            imageline($image, $start[0], $start[1], $finish[0], $finish[1], $color);
        }
    }

    /** @param \GdImage $image */
    private function addDots(\GdImage $image): void
    {
        if ($this->dotsNumber === 0) {
            return;
        }

        $w     = imagesx($image);
        $h     = imagesy($image);
        $color = $this->allocateColor($image, $this->dotsColor);

        for ($i = 0; $i < $this->dotsNumber; $i++) {
            if ($this->dotsColorRandom) {
                $color = $this->allocateColor($image);
            }

            imagesetpixel($image, random_int(0, $w - 1), random_int(0, $h - 1), $color);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return \GdImage */
    private function createBackground(int $w, int $h): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $w), max(1, $h));
        assert($image !== false);
        $bg = $this->allocateColor($image, $this->backgroundColor);
        imagefill($image, 0, 0, $bg);
        return $image;
    }

    /**
     * Allocate a color on the image. If $hex is null a random color is used.
     *
     * @param \GdImage $image
     */
    private function allocateColor(\GdImage $image, ?string $hex = null): int
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $color = imagecolorallocate($image, $r & 0xFF, $g & 0xFF, $b & 0xFF);
        assert($color !== false);
        return $color;
    }

    /**
     * Convert a 3- or 6-char hex string to [r, g, b].
     * Passing null generates a fully random color.
     *
     * @return array{int, int, int}
     */
    private function hexToRgb(?string $hex): array
    {
        if ($hex === null || $hex === '') {
            return [random_int(0, 255), random_int(0, 255), random_int(0, 255)];
        }

        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            return [
                (int) hexdec($hex[0] . $hex[0]),
                (int) hexdec($hex[1] . $hex[1]),
                (int) hexdec($hex[2] . $hex[2]),
            ];
        }

        if (strlen($hex) === 6) {
            return [
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
            ];
        }

        // Malformed — return white rather than crashing
        return [255, 255, 255];
    }

    /**
     * Resolve the TrueType font path portably, trying (in order):
     *
     *   1. the configured value as-is (absolute or CWD-relative), if it exists;
     *   2. the bundled font under the resolved RESOURCES_DIR, by basename;
     *   3. a path derived from this file's location (<repo-root>/resources/fonts).
     *
     * The configured value may be a deploy-specific absolute path (e.g. a
     * Docker "/app/resources/fonts/FSEX300.ttf") that does not exist on a bare
     * CI runner or a differently-rooted install. In that case we still find the
     * git-tracked bundled font by basename, so the captcha renders everywhere.
     * The result is memoized; setFontFile() invalidates it.
     *
     * @return string absolute/loadable font path (or the configured value as a
     *                last resort, so callers still receive a string)
     */
    private function fontPath(): string
    {
        if ($this->resolvedFont !== null) {
            return $this->resolvedFont;
        }

        $base       = basename($this->fontFile !== '' ? $this->fontFile : 'FSEX300.ttf');
        $candidates = [];

        // 1. The configured value, exactly as given.
        if ($this->fontFile !== '') {
            $candidates[] = $this->fontFile;
        }

        // 2. The bundled font under the resolved resources dir.
        if (defined('RESOURCES_DIR')) {
            $res = \constant('RESOURCES_DIR');
            if (is_string($res) && $res !== '') {
                $candidates[] = rtrim($res, '/\\') . DIRECTORY_SEPARATOR
                    . 'fonts' . DIRECTORY_SEPARATOR . $base;
            }
        }

        // 3. Derived from this file's location: <repo-root>/resources/fonts/<base>.
        //    __DIR__ = src/AstrX/Captcha → three levels up is the repo root.
        $candidates[] = dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'fonts'
            . DIRECTORY_SEPARATOR . $base;

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $this->resolvedFont = $path;
            }
        }

        // Last resort: hand back the configured value so GD receives a string.
        // If it is unloadable, imagettftext emits a warning and bbox() below
        // degrades to a zero box rather than throwing.
        return $this->resolvedFont = $this->fontFile;
    }

    /**
     * Get imagettfbbox for a string at the configured font/size.
     *
     * Degrades to a zero-filled box when the font cannot be loaded, so a
     * missing/unreadable font never turns the sizing math into a TypeError
     * (imagettfbbox returns false) — the captcha still renders, just plainer.
     *
     * @return array<int, int>
     */
    private function bbox(string $text): array
    {
        $box = imagettfbbox($this->fontSize, 0, $this->fontPath(), $text);
        if ($box === false) {
            return [0, 0, 0, 0, 0, 0, 0, 0];
        }
        /** @var array<int, int> $box */
        return $box;
    }

    /**
     * Find a free position for a character, keeping at least $diagonal
     * pixels away from every already-placed character.
     *
     * Returns [x, y] on success or [null, null] if max rounds exceeded.
     *
     * @param  list<array{int, int}> $placed
     * @return array{int|null, int|null}
     */
    private function findFreePosition(
        array $placed,
        int   $diagonal,
        int   $xMin,
        int   $xMax,
        int   $yMin,
        int   $yMax,
    ): array {
        // Performant default: a single random placement, no collision search.
        // The [xMin..xMax]/[yMin..yMax] bounds keep the char inside the image;
        // it may overlap a neighbour (rare, and cheap). Opt into avoid_collisions
        // for the spacing search that can retry up to max_rounds_number times.
        if (!$this->avoidCollisions) {
            return [random_int($xMin, $xMax), random_int($yMin, $yMax)];
        }

        for ($round = 0; $round < $this->maxRoundsNumber; $round++) {
            $cx = random_int($xMin, $xMax);
            $cy = random_int($yMin, $yMax);
            $ok = true;

            foreach ($placed as [$px, $py]) {
                if (abs($px - $cx) < $diagonal || abs($py - $cy) < $diagonal) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return [$cx, $cy];
            }
        }

        return [null, null];
    }

    /**
     * Pick a random point on the image border.
     *
     * @return array{int, int}
     */
    private function randomBorder(int $w, int $h): array
    {
        if ($this->useBorderLinearRandomness) {
            $r = random_int(0, $w + $h);
            if ($r <= $w) {
                return [$r, random_int(0, 1) === 0 ? 0 : $h];
            }
            return [random_int(0, 1) === 0 ? 0 : $w, $r - $w];
        }

        if (random_int(0, 1) === 0) {
            return [random_int(0, 1) === 0 ? 0 : $w, random_int(0, $h)];
        }
        return [random_int(0, $w), random_int(0, 1) === 0 ? 0 : $h];
    }
}
