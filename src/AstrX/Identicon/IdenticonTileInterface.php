<?php

declare(strict_types = 1);

namespace AstrX\Identicon;

/**
 * Contract for identicon tile implementations.
 * A tile is responsible for:
 *   - allocating a GD canvas of the requested size
 *   - mapping RGB values to GD colors (with contrast adjustment)
 *   - drawing a single geometric pattern onto that canvas
 * Custom tile implementations can be swapped in via IdenticonRenderer config
 * to produce different visual styles while keeping the same tiling algorithm.
 */
interface IdenticonTileInterface
{
    /**
     * Minimum tile canvas size in pixels required by this tile's pattern set.
     * Used by IdenticonRenderer to compute the actual tile render size.
     */
    public function getMinimumSize()
    : int;

    /**
     * Allocate (or re-use) the GD canvas for a tile of the given size.
     * Called once per render pass before any draw() calls.
     */
    public function allocate(int $size)
    : void;

    /**
     * Free internal GD resources. Call after rendering is complete.
     */
    public function free()
    : void;

    /**
     * Allocate a GD color on the tile's canvas.
     * Applies a contrast adjustment: if all channels are >= 0xC0 (near-white),
     * bits from the channel sum are used to invert individual channels so the
     * color is always distinguishable from the white background.
     * @return int GD color identifier
     */
    public function getColor(int $r, int $g, int $b)
    : int;

    /**
     * Draw pattern $type onto the tile canvas using $color.
     *
     * @param int $type  Pattern index (wraps modulo pattern count).
     * @param int $color GD color from getColor().
     *
     * @return \GdImage            The rendered tile image (may be rotated/flipped).
     */
    public function draw(int $type, int $color)
    : \GdImage;
}