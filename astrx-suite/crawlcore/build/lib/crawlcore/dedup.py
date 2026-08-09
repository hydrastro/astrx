"""Near-duplicate bit-math shared by both crawlers' SimHash implementations.

Only the *arithmetic* lives here, never a fingerprint policy: each crawler keeps
its own tokenizer and token-hash (onioncrawler: blake2b over unigram tokens;
websearch: FNV-1a over word-bigram shingles) and feeds the resulting per-token
64-bit hashes into :func:`simhash_vector`. That keeps the stored fingerprints
byte-for-byte identical to before this extraction while removing the duplicated
column-sum / Hamming / signed-wrap code.

The column-sum rule is the standard SimHash: each contributing token hash votes
``+weight`` on the bit positions it sets and ``-weight`` on those it clears; an
output bit is 1 iff its column sum is strictly positive. This is identical
whether a repeated token is fed once with its count as *weight* or fed *count*
times with weight 1, so both callers' historical shapes reproduce exactly.
"""

from __future__ import annotations

DEFAULT_BITS = 64


def _mask(bits: int) -> int:
    return (1 << bits) - 1


def simhash_vector(weighted_hashes, bits: int = DEFAULT_BITS) -> int:
    """Fold an iterable of ``(token_hash, weight)`` pairs into an UNSIGNED
    SimHash of *bits* bits.

    Returns 0 when the iterable is empty (a page with no tokenizable content has
    no fingerprint and must never be treated as a mirror of another empty page).
    The caller decides the sign representation (see :func:`signed64`).
    """
    acc = [0] * bits
    seen = False
    for h, weight in weighted_hashes:
        seen = True
        for i in range(bits):
            if (h >> i) & 1:
                acc[i] += weight
            else:
                acc[i] -= weight
    if not seen:
        return 0
    out = 0
    for i in range(bits):
        if acc[i] > 0:
            out |= (1 << i)
    return out


def signed64(value: int, bits: int = DEFAULT_BITS) -> int:
    """Map an unsigned *bits*-bit fingerprint into signed two's-complement range.

    SQLite's INTEGER column is signed 64-bit, so a fingerprint >= 2**63 must be
    wrapped before storage. :func:`hamming` masks before counting, so the signed
    and unsigned forms compare identically - only persistence needs the wrap.
    """
    v = int(value) & _mask(bits)
    return v - (1 << bits) if v >= (1 << (bits - 1)) else v


def hamming(a: int, b: int, bits: int = DEFAULT_BITS) -> int:
    """Hamming distance between two fingerprints (signed or unsigned both work)."""
    return bin((int(a) ^ int(b)) & _mask(bits)).count("1")


def near(a: int, b: int, threshold: int = 3, bits: int = DEFAULT_BITS) -> bool:
    """True iff *a* and *b* are both non-zero and within *threshold* bits.

    A zero fingerprint means "no content"; it never matches, so empty pages are
    not clustered as mirrors of one another.
    """
    if not a or not b:
        return False
    return hamming(a, b, bits) <= threshold
