"""64-bit SimHash for near-duplicate / mirror detection.

Exact content dedup already lives in storage (SHA-1 of normalized title+text).
This is the *fuzzy* version: two pages that are near-identical (a mirror, a page
with a rotating ad or a different footer) produce SimHash values a small Hamming
distance apart, so they can be clustered and collapsed in results.

The tokenizer + token hash (blake2b over unigram tokens) are OWNED here so the
persisted fingerprints are unchanged; the shared column-sum / Hamming / signed
wrap / near-duplicate bit-math now lives once in :mod:`crawlcore.dedup`.

Pure and stdlib-only, so it is deterministic across processes and trivially
unit-testable.
"""

from __future__ import annotations

import hashlib
import re

# Shared bit-math (single tested implementation used by both crawlers). The
# public names below (hamming/to_signed64/is_near_duplicate) are re-exported for
# back-compat with callers and tests that import them from this module.
from crawlcore.dedup import (
    simhash_vector,
    hamming,
    signed64 as to_signed64,
    near as is_near_duplicate,
)

_TOKEN = re.compile(r"[0-9a-z]+", re.UNICODE)
_BITS = 64
_MASK = (1 << _BITS) - 1


def _token_hash(token: str) -> int:
    # blake2b with an 8-byte digest -> stable 64-bit int (hash() is salted per
    # process and must not be used for a persisted fingerprint).
    d = hashlib.blake2b(token.encode("utf-8"), digest_size=8).digest()
    return int.from_bytes(d, "big")


def _tokens(text: str):
    return _TOKEN.findall((text or "").lower())


def simhash64(text: str) -> int:
    """Return the 64-bit SimHash of *text* as a SIGNED 64-bit int (0 if there is
    no tokenizable text).

    Standard SimHash: weight each bit position by +count when the token-hash bit
    is set and -count when clear; the output bit is 1 iff the column sum > 0.

    The value is returned in signed two's-complement range [-2^63, 2^63-1] so it
    fits SQLite's signed INTEGER column. hamming() masks before counting, so all
    distance math is unaffected by the sign representation.
    """
    counts: dict[str, int] = {}
    for t in _tokens(text):
        counts[t] = counts.get(t, 0) + 1
    if not counts:
        return 0
    out = simhash_vector(
        ((_token_hash(token), weight) for token, weight in counts.items()),
        bits=_BITS,
    )
    return to_signed64(out & _MASK)


__all__ = ["simhash64", "hamming", "to_signed64", "is_near_duplicate"]
