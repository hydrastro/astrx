"""Near-duplicate detection: 64-bit SimHash over word-bigram shingles.

Exact-hash dedup (``content_hash``) already drops byte-identical mirrors at
crawl time.  SimHash catches the *fuzzy* case -- mirrors and boilerplate-heavy
pages that differ only in a nav bar, a date, or an ad -- so the ranker can
collapse them out of a result page.

The tokenizer + token hash (an explicit FNV-1a, not Python's per-process
randomised ``hash``) are OWNED here so fingerprints stay stable and unchanged;
the shared column-sum / Hamming / signed-wrap / near bit-math now lives once in
:mod:`crawlcore.dedup`.
"""

import re

# Shared bit-math (single tested implementation used by both crawlers). Re-export
# hamming/signed64/near so existing callers (ranking.py, tests) keep importing
# them from this module.
from crawlcore.dedup import simhash_vector, hamming, signed64, near  # noqa: F401

BITS = 64
_MASK = (1 << BITS) - 1
_TOKEN = re.compile(r"[^\W_]+", re.UNICODE)

_FNV_OFFSET = 0xCBF29CE484222325
_FNV_PRIME = 0x100000001B3


def _fnv1a(data):
    h = _FNV_OFFSET
    for b in data:
        h ^= b
        h = (h * _FNV_PRIME) & _MASK
    return h


def _shingles(text):
    """Word bigrams -- more discriminating than unigrams, so genuinely distinct
    short pages are not collapsed, while true mirrors still are."""
    words = _TOKEN.findall(text.lower())
    if len(words) < 2:
        return words
    return [words[i] + " " + words[i + 1] for i in range(len(words) - 1)]


def simhash(text):
    """Return a 64-bit (unsigned) SimHash of *text* (0 for empty/too-short)."""
    if not text:
        return 0
    return simhash_vector(
        ((_fnv1a(shingle.encode("utf-8", "replace")), 1)
         for shingle in _shingles(text)),
        bits=BITS,
    )
