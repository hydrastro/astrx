<?php

declare(strict_types = 1);

namespace AstrX\Identicon;

/**
 * Default identicon tile renderer.
 * Pattern set (same as the original Ranvis/Identicon Tile):
 *   empty, regular triangle (×4 rotations), isosceles right triangle (×4),
 *   isosceles triangle (×4), bowknot, rotated bowknot, parallelogram (×3),
 *   rotated square, fill.
 * The tile canvas is reused across draw() calls via allocate(); free() releases
 * the GD resource when rendering is complete.
 * Background color is white by default and is configurable via the constructor.
 */
final class IdenticonTile implements IdenticonTileInterface
{
    private const PATTERN_ANGLE_MASK = 3;
    private const PATTERN_FLIP_H = 4;
    private const PATTERN_FLIP_V = 8;
    /**
     * Tile patterns.
     * A float array defines polygon vertices as fractions of (tileSize - 1).
     * An int following an array is a rotation/flip flag applied to that pattern.
     * Multiple ints after an array = multiple rotated variants of the same shape.
     */
    private const PATTERNS
        = [
            null,
            // empty tile
            [0, 0, 1, 0, .5, 1],
            1,
            2,
            3,
            // regular triangle
            [0, 0, 1, 0, 0, 1],
            1,
            2,
            3,
            // isosceles right triangle
            [0, 0, 1, 0.5, 0.5, 1],
            1,
            2,
            3,
            // isosceles triangle
            [0, 0, 1, 1, 1, 0, 0, 1],
            1,
            // bowknot
            [.5, 0, .5, 1, 1, .5, 0, .5],
            1,
            // rotated bowknot
            [0, 0, .5, 0, 1, 1, .5, 1],
            1,
            4,
            5,
            // parallelogram
            [.5, 0, 1, .5, .5, 1, 0, .5],
            // rotated square
            [0, 0, 1, 0, 1, 1],
            // fill (triangle = right corner)
        ];
    private int $size = 0;
    private int $bgColorValue = 0;
    private ?\GdImage $image = null;

    /**
     * @param array{int,int,int} $bgColor Background RGB — defaults to white.
     */
    public function __construct(
        private readonly array $bgColor = [255, 255, 255],
    ) {
    }

    // -------------------------------------------------------------------------
    // IdenticonTileInterface
    // -------------------------------------------------------------------------

    public function getMinimumSize()
    : int
    {
        return 3;
    }

    public function allocate(int $size)
    : void {
        if ($this->size === $size && $this->image !== null) {
            return; // already allocated at this size — reuse
        }

        $this->size = $size;
        $image = imagecreatetruecolor(max(1, $size), max(1, $size));
        assert($image !== false);
        $this->image = $image;

        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }

        [$r, $g, $b] = $this->bgColor;
        $bgResult = imagecolorallocate($image, $r & 0xFF, $g & 0xFF, $b & 0xFF);
        $this->bgColorValue = $bgResult !== false ? $bgResult : 0;
    }

    public function free()
    : void
    {
        $this->size = 0;
        $this->image = null;
        $this->bgColorValue = 0;
    }

    public function getColor(int $r, int $g, int $b)
    : int {
        assert(
            $this->image !== null,
            'allocate() must be called before getColor()'
        );

        // If all channels are near-white (>= 0xC0), derive flip bits from the
        // channel sum to invert individual channels and guarantee contrast.
        if (($r & $g & $b & 0xC0) === 0xC0) {
            $flags = ($r + $g + $b) & 0x7;
            if ($flags === 0) {
                $flags = 0x7;
            }
            if ($flags & 1) {
                $r ^= 0xFF;
            }
            if ($flags & 3) {
                $g ^= 0xFF;
            }  // note: intentional overlap with bit 1
            if ($flags & 5) {
                $b ^= 0xFF;
            }  // note: intentional overlap with bit 1
        }

        $color = imagecolorallocate($this->image, $r & 0xFF, $g & 0xFF, $b & 0xFF);
        assert($color !== false);

        return $color;
    }

    public function draw(int $type, int $color)
    : \GdImage {
        assert(
            $this->image !== null,
            'allocate() must be called before draw()'
        );

        $size = $this->size;
        $image = $this->image;

        [$poly, $rotation] = $this->getPattern($type);

        // Clear canvas to background color
        imagefilledrectangle($image, 0, 0, $size, $size, $this->bgColorValue);

        if ($poly !== null) {
            // Scale vertex fractions to pixel coordinates
            $scaled = array_map(static fn(float $pt)
            : float => $pt * ($size - 1), $poly);

            // PHP 8.1+ dropped the $num_points parameter from imagefilledpolygon
            imagefilledpolygon($image, $scaled, $color);

            $image = $this->applyRotation($image, $rotation);
        }

        return $image;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve pattern index → [vertex_array|null, rotation_flags].
     * Pattern entries are either a float[] (polygon vertices) or an int (rotation
     * flags applying to the nearest preceding float[] entry). This allows compact
     * expression of multiple orientations of the same shape.
     * @return array{array<float>|null, int}
     */
    private function getPattern(int $type)
    : array {
        $patterns = self::PATTERNS;
        $count = count($patterns);
        $type = $type % $count;
        $entry = $patterns[$type];
        $rotation = 0;

        if (is_int($entry)) {
            $rotation = $entry;
            while (is_int($patterns[--$type])) {
                // Walk backwards to find the base polygon
            }
            $entry = $patterns[$type];
        }

        /** @var array<float>|null $entry */
        return [$entry, $rotation];
    }

    /**
     * Apply rotation and/or flip flags to a GD image resource.
     * Flag bits:
     *   PATTERN_ANGLE_MASK (0x3) — rotation in 90° increments
     *   PATTERN_FLIP_H     (0x4) — horizontal flip
     *   PATTERN_FLIP_V     (0x8) — vertical flip
     */
    private function applyRotation(\GdImage $image, int $rotation)
    : \GdImage {
        if ($rotation & self::PATTERN_FLIP_H) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($rotation & self::PATTERN_FLIP_V) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        if ($rotation & self::PATTERN_ANGLE_MASK) {
            $image = imagerotate(
                $image,
                ($rotation & self::PATTERN_ANGLE_MASK) * 90,
                $this->bgColorValue
            );
            assert($image !== false);
        }

        return $image;
    }
}
