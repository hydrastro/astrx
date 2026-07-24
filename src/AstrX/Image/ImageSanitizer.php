<?php
declare(strict_types=1);

namespace AstrX\Image;

use AstrX\Image\Diagnostic\ImageSanitizeDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * Shared, dependency-free image sanitizer used by every module that accepts an
 * uploaded image (avatars, chat attachments, imageboard posts).
 *
 * The re-encode IS the strip: an untrusted image is decoded to raw pixels with
 * GD and re-encoded, so ALL metadata (EXIF/GPS/comments) is discarded and any
 * trailing or polyglot payload is neutralised — only pixels survive. Optionally
 * it downscales the full image, produces a thumbnail, and computes an
 * average-hash / SHA-256 for de-duplication and blocklisting.
 *
 * Stateless: it takes raw bytes plus options and returns re-encoded bytes. The
 * caller owns transport-error handling (upload errors) and all storage/naming.
 */
final class ImageSanitizer
{
    /**
     * Validate, strip, and (optionally) downscale/thumbnail/hash an image.
     *
     * @return Result<SanitizedImage>
     */
    public function sanitize(string $raw, string $claimedExt, ImageSanitizeOptions $opts): Result
    {
        if ($opts->maxBytes > 0 && strlen($raw) > $opts->maxBytes) {
            return $this->fail(ImageSanitizeError::TOO_BIG, 'too_big');
        }
        $ext = strtolower(trim($claimedExt));
        if ($opts->allowedExtensions !== [] && !in_array($ext, $opts->allowedExtensions, true)) {
            return $this->fail(ImageSanitizeError::BAD_TYPE, 'bad_type');
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            return $this->fail(ImageSanitizeError::UNDECODABLE, 'undecodable');
        }
        $type = $info[2]; // IMAGETYPE_* (a guaranteed set int)

        $img = @imagecreatefromstring($raw);
        if (!$img instanceof \GdImage) {
            return $this->fail(ImageSanitizeError::UNDECODABLE, 'undecodable');
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($opts->maxDimension > 0 && ($w > $opts->maxDimension || $h > $opts->maxDimension)) {
            $scaled = $this->scaleWithin($img, $opts->maxDimension);
            if ($scaled instanceof \GdImage) {
                imagedestroy($img);
                $img = $scaled;
                $w   = imagesx($img);
                $h   = imagesy($img);
            }
        }

        $asJpeg = match ($opts->outputFormat) {
            ImageOutputFormat::JPEG => true,
            ImageOutputFormat::PNG  => false,
            ImageOutputFormat::AUTO => $type === IMAGETYPE_JPEG,
        };

        $full = $this->encode($img, $asJpeg, $opts->jpegQuality);
        if ($full === null) {
            imagedestroy($img);
            return $this->fail(ImageSanitizeError::ENCODE_FAILED, 'encode_failed');
        }

        $thumbBytes = null;
        $thumbW     = null;
        $thumbH     = null;
        if ($opts->makeThumbnail) {
            $thumb = $this->scaleWithin($img, $opts->thumbMaxDimension) ?? $img;
            $tb    = $this->encode($thumb, $asJpeg, $opts->jpegQuality);
            if ($tb !== null) {
                $thumbBytes = $tb;
                $thumbW     = imagesx($thumb);
                $thumbH     = imagesy($thumb);
            }
            if ($thumb !== $img) {
                imagedestroy($thumb);
            }
        }

        $ahash = $opts->computeAverageHash ? $this->averageHash($img) : null;
        imagedestroy($img);

        $sha = $opts->computeSha256 ? hash('sha256', $full) : null;

        return Result::ok(new SanitizedImage(
            fullBytes:   $full,
            mime:        $asJpeg ? 'image/jpeg' : 'image/png',
            ext:         $asJpeg ? 'jpg' : 'png',
            width:       $w,
            height:      $h,
            thumbBytes:  $thumbBytes,
            thumbWidth:  $thumbW,
            thumbHeight: $thumbH,
            averageHash: $ahash,
            sha256:      $sha,
        ));
    }

    /** Proportionally scale so the longest side ≤ $max; null if no scaling was needed/possible. */
    private function scaleWithin(\GdImage $img, int $max): ?\GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($max <= 0 || ($w <= $max && $h <= $max)) {
            return null;
        }
        $scale  = $max / max($w, $h);
        $nw     = max(1, (int) round($w * $scale));
        $nh     = max(1, (int) round($h * $scale));
        $scaled = imagescale($img, $nw, $nh);
        return $scaled instanceof \GdImage ? $scaled : null;
    }

    /** Encode to a byte string via output buffering. Null on failure. */
    private function encode(\GdImage $img, bool $asJpeg, int $quality): ?string
    {
        ob_start();
        $ok    = $asJpeg ? imagejpeg($img, null, $quality) : imagepng($img);
        $bytes = ob_get_clean();
        if ($ok !== true || $bytes === false || $bytes === '') {
            return null;
        }
        return $bytes;
    }

    /** 64-bit average hash (aHash) from an 8×8 greyscale reduction — near-duplicate detection. */
    private function averageHash(\GdImage $img): int
    {
        $small = imagescale($img, 8, 8);
        if (!$small instanceof \GdImage) {
            return 0;
        }
        $vals = [];
        $sum  = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                if ($rgb === false) {
                    $rgb = 0;
                }
                $g      = (int) round(((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3);
                $vals[] = $g;
                $sum   += $g;
            }
        }
        imagedestroy($small);
        $mean = $sum / 64;
        $bits = 0;
        foreach ($vals as $v) {
            $bits = ($bits << 1) | ($v >= $mean ? 1 : 0);
        }
        return $bits;
    }

    /** @return Result<never> */
    private function fail(ImageSanitizeError $reason, string $slug): Result
    {
        return Result::err($reason, Diagnostics::of(new ImageSanitizeDiagnostic(
            'astrx.image/' . $slug, DiagnosticLevel::NOTICE
        )));
    }
}
