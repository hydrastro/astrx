<?php

declare(strict_types = 1);

namespace AstrX\Identicon;

use AstrX\Config\InjectConfig;

/**
 * Identicon generator.
 * Produces deterministic, symmetrical identicons from any input string.
 * The input is hashed with SHA-256 internally — callers pass raw strings
 * (usernames, IDs, emails) and get consistent images back.
 * Returns a base64-encoded PNG string suitable for embedding directly:
 *   <img src="data:image/png;base64,{{identicon}}">
 * All visual settings are configurable via #[InjectConfig] so the appearance
 * can be adjusted per-deployment without changing code.
 * The tile implementation is injected, allowing custom tile styles to be
 * swapped in while reusing the tiling algorithm. The default is IdenticonTile.
 * Algorithm (by SATO Kentaro, BSD-2):
 *   1. Derive color(s) and pattern seeds from the hash.
 *   2. Divide the canvas into an NxN tile grid.
 *   3. Fill the grid with rotated/flipped copies of geometric tile patterns,
 *      placing them symmetrically around both axes (and the centre if N is odd).
 *   4. Scale the rendered grid down to the requested output size.
 */
final class IdenticonRenderer
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Output image size in pixels (square). */
    private int $size = 256;
    /** Number of tiles along each axis. More tiles → more complex pattern. */
    private int $tiles = 6;
    /** Number of distinct colors to derive from the hash. */
    private int $colors = 1;
    /**
     * High-quality mode: render at a larger internal size and downsample.
     * Produces smoother edges at the cost of memory and CPU.
     */
    private bool $highQuality = true;

    #[InjectConfig('size')]
    public function setSize(int $v)
    : void {
        if ($v > 0) {
            $this->size = $v;
        }
    }

    #[InjectConfig('tiles')]
    public function setTiles(int $v)
    : void {
        if ($v >= 2) {
            $this->tiles = $v;
        }
    }

    #[InjectConfig('colors')]
    public function setColors(int $v)
    : void {
        if ($v >= 1) {
            $this->colors = $v;
        }
    }

    #[InjectConfig('high_quality')]
    public function setHighQuality(bool $v)
    : void {
        $this->highQuality = $v;
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(
        private readonly IdenticonTileInterface $tile = new IdenticonTile(),
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate an identicon for the given input string.
     * The input is hashed with SHA-256; the hex digest feeds the drawing
     * algorithm. SHA-256 always produces 64 hex chars which satisfies the
     * minimum hash length for any sensible tiles/colors combination.
     * @return string Base64-encoded PNG.
     */
    public function render(string $input)
    : string {
        $hash = hash('sha256', $input);
        $image = $this->draw($hash);

        ob_start();
        imagepng($image, null, -1, -1);
        imagedestroy($image);
        $this->tile->free();

        return base64_encode((string)ob_get_clean());
    }

    /**
     * Minimum number of hex characters required by the current settings.
     * Provided as a diagnostic aid — render() handles this automatically.
     */
    public function getMinimumHashLength()
    : int
    {
        $xEnd = ($this->tiles + 1) >> 1;

        return $this->colors * 6 + 3 + (int)(($xEnd + 1) * $xEnd / 2);
    }

    // -------------------------------------------------------------------------
    // Drawing
    // -------------------------------------------------------------------------

    /**
     * Run the tiling algorithm on a hex hash string.
     * @return \GdImage Resampled output image at $this->size × $this->size.
     */
    private function draw(string $hash)
    : \GdImage {
        $tiles = $this->tiles;
        $numColors = $this->colors;

        // In high-quality mode the tile is rendered at a larger size and
        // imagecopyresampled smooths it back to $maxSize.
        $renderSize = $this->size;
        if ($this->highQuality && !function_exists('imageantialias')) {
            $renderSize *= 2;
        }

        $minSize = $this->tile->getMinimumSize();
        $res = $tiles * $minSize;
        $tileSize = max(
            1,
            $this->highQuality ?
                (int)(ceil($renderSize / $tiles / $res) * $res) :
                (int)ceil($renderSize / $tiles)
        );

        $this->tile->allocate($tileSize);

        $canvasSize = $tileSize * $tiles;
        $canvas = imagecreatetruecolor(max(1, $canvasSize), max(1, $canvasSize));
        assert($canvas !== false);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        assert($white !== false);

        $br = $tileSize * ($tiles - 1);

        // Offset table: each entry is [x, y, innerMoveX, innerMoveY, outerMoveX, outerMoveY]
        // The 8 entries cover 4 corners + 4 "opposite side" mirror positions.
        $offsets = [
            [0, 0, 0, 1, 1, 0],
            [$br, 0, -1, 0, 0, 1],
            [$br, $br, 0, -1, -1, 0],
            [0, $br, 1, 0, 0, -1],
            [$br, 0, 0, 1, -1, 0],
            [$br, $br, -1, 0, 0, -1],
            [0, $br, 0, -1, 1, 0],
            [0, 0, 1, 0, 0, 1],
        ];

        $xEnd = ($tiles + 1) >> 1;
        $xMid = ($xEnd + 1) >> 1;
        $center = ($tiles & 1) ? ($tiles >> 1) : -1;

        $index = 0;
        $colors = [];

        // Derive colors from the first (numColors * 6) hex chars
        for ($i = 0; $i < $numColors; $i++) {
            $r = hexdec(substr($hash, $index, 2));
            $index += 2;
            $g = hexdec(substr($hash, $index, 2));
            $index += 2;
            $b = hexdec(substr($hash, $index, 2));
            $index += 2;
            $colors[] = $this->tile->getColor((int)$r, (int)$g, (int)$b);
        }

        $baseColor = hexdec($hash[$index++]);
        $colorPattern = hexdec($hash[$index++]);
        $type = hexdec($hash[$index++]);

        // Tile placement loop — fills one quadrant; the offset table mirrors
        // each tile to all symmetric positions automatically.
        for ($x = 0; $x < $xEnd; $x++) {
            $xOffsets = $offsets;

            for ($y = 0; $y <= $x; $y++) {
                // Compute color index for this position using the color pattern bits
                $color = (int)$baseColor;
                if ($colorPattern & 1) {
                    $color++;
                }
                if ($colorPattern & 2) {
                    $color += $x;
                }
                if ($colorPattern & 4) {
                    $color += $y;
                }
                if ($colorPattern & 8) {
                    $color += (int)($x <= $xMid);
                }

                $type = (int)(($type + hexdec($hash[$index++])) % PHP_INT_MAX);
                $tileImage = $this->tile->draw(
                    $type,
                    $colors[$color % $numColors]
                );

                // Stamp the tile (and its mirrors) onto the canvas
                for ($i = 0; $i < 8; $i++) {
                    if ($i === 4 && ($y === $x || $x === 0)) {
                        break;
                    }

                    $offset = $xOffsets[$i];

                    if ($i < 4 || $x !== $center) {
                        if ($i === 4) {
                            imageflip($tileImage, IMG_FLIP_HORIZONTAL);
                        }

                        imagecopy(
                            $canvas,
                            $tileImage,
                            $offset[0],
                            $offset[1],
                            0,
                            0,
                            $tileSize,
                            $tileSize,
                        );

                        if ($x === $center && $y === $center) {
                            break;
                        }

                        if ($i !== 7) {
                            $rotated = imagerotate($tileImage, 270, $white);
                            assert($rotated !== false);
                            $tileImage = $rotated;
                        }
                    }

                    $xOffsets[$i][0] += $offset[2] * $tileSize;
                    $xOffsets[$i][1] += $offset[3] * $tileSize;
                }
            }

            // Advance outer offsets for the next column
            for ($i = 0; $i < 8; $i++) {
                $offsets[$i][0] += $offsets[$i][4] * $tileSize;
                $offsets[$i][1] += $offsets[$i][5] * $tileSize;
            }
        }

        // Resample to the requested output size
        $output = imagecreatetruecolor(max(1, $this->size), max(1, $this->size));
        assert($output !== false);
        imagecopyresampled(
            $output,
            $canvas,
            0,
            0,
            0,
            0,
            $this->size,
            $this->size,
            $canvasSize,
            $canvasSize
        );
        imagedestroy($canvas);

        return $output;
    }
}
