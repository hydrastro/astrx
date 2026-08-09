"""crawlcore.dedup: the shared SimHash bit-math.

These tests also re-implement BOTH crawlers' historical fingerprint functions on
top of ``simhash_vector`` and assert the near/far/determinism properties each
crawler relies on, proving the shared accumulator reproduces both.
"""

import hashlib
import re
import unittest

from crawlcore import dedup

MASK = (1 << 64) - 1

# --- onioncrawler's fingerprint: blake2b over unigram tokens, weight by count --
_UNI = re.compile(r"[0-9a-z]+", re.UNICODE)


def _onion_token_hash(token):
    return int.from_bytes(
        hashlib.blake2b(token.encode("utf-8"), digest_size=8).digest(), "big")


def onion_simhash64(text):
    counts = {}
    for t in _UNI.findall((text or "").lower()):
        counts[t] = counts.get(t, 0) + 1
    out = dedup.simhash_vector(
        ((_onion_token_hash(tok), w) for tok, w in counts.items()))
    return dedup.signed64(out & MASK)


# --- websearch's fingerprint: FNV-1a over word-bigram shingles, weight 1 -------
_WORD = re.compile(r"[^\W_]+", re.UNICODE)
_FNV_OFFSET = 0xCBF29CE484222325
_FNV_PRIME = 0x100000001B3


def _fnv1a(data):
    h = _FNV_OFFSET
    for b in data:
        h ^= b
        h = (h * _FNV_PRIME) & MASK
    return h


def _shingles(text):
    words = _WORD.findall(text.lower())
    if len(words) < 2:
        return words
    return [words[i] + " " + words[i + 1] for i in range(len(words) - 1)]


def web_simhash(text):
    if not text:
        return 0
    return dedup.simhash_vector(
        (_fnv1a(s.encode("utf-8", "replace")), 1) for s in _shingles(text))


class HammingSignedNearTest(unittest.TestCase):
    def test_hamming_basic(self):
        self.assertEqual(dedup.hamming(0, 0), 0)
        self.assertEqual(dedup.hamming(0b1011, 0b1110), 2)
        self.assertEqual(dedup.hamming(0, MASK), 64)

    def test_signed64_range_and_roundtrip(self):
        for u in (0, 1, (1 << 63) - 1, 1 << 63, MASK):
            s = dedup.signed64(u)
            self.assertGreaterEqual(s, -(1 << 63))
            self.assertLess(s, 1 << 63)
            # signed and unsigned forms are Hamming-identical
            self.assertEqual(dedup.hamming(s, u), 0)

    def test_near(self):
        self.assertTrue(dedup.near(0b1111, 0b1110, threshold=1))
        self.assertFalse(dedup.near(0b1111, 0b1000, threshold=1))
        self.assertFalse(dedup.near(0, 123, threshold=3))   # empty never matches
        self.assertFalse(dedup.near(123, 0, threshold=3))


class SimhashVectorTest(unittest.TestCase):
    def test_empty_is_zero(self):
        self.assertEqual(dedup.simhash_vector(iter(())), 0)

    def test_weight_equals_repetition(self):
        # feeding (h, 3) must equal feeding (h, 1) three times: the whole reason
        # onion (weight-by-count) and websearch (per-occurrence) can share this.
        h1, h2 = _onion_token_hash("alpha"), _onion_token_hash("beta")
        weighted = dedup.simhash_vector([(h1, 3), (h2, 1)])
        expanded = dedup.simhash_vector([(h1, 1), (h1, 1), (h1, 1), (h2, 1)])
        self.assertEqual(weighted, expanded)

    def test_deterministic(self):
        self.assertEqual(web_simhash("the quick brown fox jumps"),
                         web_simhash("the quick brown fox jumps"))


class OnionFingerprintTest(unittest.TestCase):
    _BASE = "mirrortoken " + " ".join("word%d" % i for i in range(300))
    _FAR = "mirrortoken " + " ".join("term%d" % i for i in range(300))

    def test_near_and_far(self):
        a = onion_simhash64(self._BASE + " uniquealpha")
        b = onion_simhash64(self._BASE + " uniquebeta")
        c = onion_simhash64(self._FAR)
        self.assertLessEqual(dedup.hamming(a, b), 3)
        self.assertGreater(dedup.hamming(a, c), 3)

    def test_signed_sqlite_range(self):
        for t in (self._BASE, self._FAR, "x", "a b c d e f g"):
            v = onion_simhash64(t)
            self.assertGreaterEqual(v, -(1 << 63))
            self.assertLess(v, 1 << 63)


class WebFingerprintTest(unittest.TestCase):
    # Exact bodies from websearch's own dedup test, so the near/far contract
    # matches the crawler that owns this fingerprint.
    _WORDS = (
        "alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu "
        "xi omicron pi rho sigma tau upsilon phi chi psi omega search engine "
        "crawler inverted index ranking relevance freshness authority document "
        "corpus token frontier robots canonical simhash dedup pagerank fetch "
        "parser").split()
    _BASE = " ".join(_WORDS * 6)
    _A = _BASE + " sharedterm distinctivemarkeraaa footer2020"
    _B = _BASE + " sharedterm distinctivemarkerbbb footer2021"
    _WORDS2 = (
        "mountain river valley weather rainfall hydrology geology soil climate "
        "forest ocean desert glacier volcano earthquake tundra savanna prairie "
        "wetland estuary basin canyon plateau ridge summit").split()
    _C = " ".join(_WORDS2 * 8) + " sharedterm"

    def test_near_and_far(self):
        a, b, c = web_simhash(self._A), web_simhash(self._B), web_simhash(self._C)
        self.assertLessEqual(dedup.hamming(a, b), 3)
        self.assertGreater(dedup.hamming(a, c), 3)

    def test_signed_roundtrip(self):
        h = web_simhash(self._A)
        self.assertEqual(dedup.hamming(dedup.signed64(h), h), 0)


if __name__ == "__main__":
    unittest.main()
