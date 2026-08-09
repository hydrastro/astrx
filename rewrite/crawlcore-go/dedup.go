package crawlcore

import "math/bits"

// DefaultBits is the SimHash width (SQLite stores a signed 64-bit integer).
const DefaultBits = 64

// WeightedHash is one (token hash, weight) contribution to a SimHash. Feeding
// (h, 3) is identical to feeding (h, 1) three times, so a weight-by-count caller
// and a per-occurrence caller produce the same fingerprint.
type WeightedHash struct {
	Hash   uint64
	Weight int
}

// SimhashVector folds (token hash, weight) pairs into an unsigned 64-bit SimHash:
// each bit a token sets votes +weight, each bit it clears votes -weight, and an
// output bit is 1 iff its column sum is strictly positive. Returns 0 for empty
// input — a page with no content has no fingerprint and must never be treated as
// a mirror of another empty page.
func SimhashVector(items []WeightedHash) uint64 {
	var acc [DefaultBits]int
	seen := false
	for _, it := range items {
		seen = true
		for i := 0; i < DefaultBits; i++ {
			if (it.Hash>>uint(i))&1 == 1 {
				acc[i] += it.Weight
			} else {
				acc[i] -= it.Weight
			}
		}
	}
	if !seen {
		return 0
	}
	var out uint64
	for i := 0; i < DefaultBits; i++ {
		if acc[i] > 0 {
			out |= 1 << uint(i)
		}
	}
	return out
}

// Signed64 reinterprets an unsigned fingerprint as signed two's-complement (the
// same bit pattern), so it fits SQLite's signed INTEGER column. Hamming distance
// is identical for the signed and unsigned forms.
func Signed64(value uint64) int64 { return int64(value) }

// Hamming is the bit distance between two fingerprints (signed or unsigned bits).
func Hamming(a, b uint64) int { return bits.OnesCount64(a ^ b) }

// Near reports whether a and b are both non-zero and within threshold bits. A
// zero fingerprint means "no content" and never matches.
func Near(a, b uint64, threshold int) bool {
	if a == 0 || b == 0 {
		return false
	}
	return Hamming(a, b) <= threshold
}
