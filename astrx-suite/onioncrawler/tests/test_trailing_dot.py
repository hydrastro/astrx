"""Regression: trailing-dot host canonicalization must NOT create a second
host key that evades the abuse host blocklist.

Before the fix, normalize_host stripped only ONE trailing dot, so
``http://<onion>.onion../x`` canonicalized to host ``<onion>.onion.`` -- a key
DISTINCT from ``<onion>.onion`` yet routing to the same hidden service. Pages
indexed under the dotted host survived ``apply_abuse_blocklist([<onion>.onion])``
and stayed searchable. normalize_host now strips ALL trailing dots so every
variant collapses to the single blocklist / dedup key. Covers .onion and .i2p.
"""

import os
import tempfile
import time
import unittest

from onioncrawler.onion import normalize_host, require_onion, require_i2p
from onioncrawler.canonical import canonicalize
from onioncrawler.storage import Storage
from onioncrawler.abuse import AbuseFilter

ONION = "f" * 56 + ".onion"
I2P = "b" * 52 + ".b32.i2p"


class TestNormalizeIdempotent(unittest.TestCase):
    def test_strips_all_trailing_dots(self):
        for suffix in ("", ".", "..", "..."):
            self.assertEqual(normalize_host(ONION + suffix), ONION)
        # port + trailing dots together still collapse
        self.assertEqual(normalize_host(ONION + ".:8080"), ONION)
        # require_onion returns the fully-normalized (dotless) host
        self.assertEqual(require_onion(ONION + ".."), ONION)
        self.assertEqual(require_i2p(I2P + ".."), I2P)


class TestCanonicalCollapsesDots(unittest.TestCase):
    def test_onion_variants_share_one_canonical(self):
        base = canonicalize(f"http://{ONION}/x")
        for v in (f"http://{ONION}./x", f"http://{ONION}../x",
                  f"http://{ONION}.../x"):
            cu = canonicalize(v)
            self.assertIsNotNone(cu)
            self.assertEqual(cu.host, ONION)
            self.assertEqual(cu.url, base.url, f"{v} must dedup to the dotless URL")

    def test_i2p_variants_share_one_canonical(self):
        base = canonicalize(f"http://{I2P}/x", allow_i2p=True)
        cu = canonicalize(f"http://{I2P}../x", allow_i2p=True)
        self.assertIsNotNone(cu)
        self.assertEqual(cu.host, I2P)
        self.assertEqual(cu.url, base.url)


class TestBlocklistNotEvadedByDot(unittest.TestCase):
    def _index_and_block(self, canon_input, block_host, allow_i2p=False):
        st = Storage(os.path.join(tempfile.mkdtemp(), "dot.db"))
        try:
            cu = canonicalize(canon_input, allow_i2p=allow_i2p)
            st.ensure_host(cu.host)
            st.store_page(cu.url, cu.host, "T", "abusive body text here",
                          "h1", 200, "text/html", 10, time.time())
            # sanity: the page is searchable before the block
            self.assertEqual(st.search("abusive")[1], 1)
            applied = st.apply_abuse_blocklist(AbuseFilter(hosts=[block_host]))
            return st, applied, st.search("abusive")[1]
        finally:
            st.close()

    def test_onion_dotted_page_is_blocked(self):
        # page indexed via the double-dot variant, operator blocks the dotless
        st, applied, total = self._index_and_block(
            f"http://{ONION}../secret", ONION)
        self.assertEqual(applied["hosts_blocked"], 1,
                         "dotted host row must be blocked by the dotless entry")
        self.assertEqual(total, 0, "abusive page must vanish from search")

    def test_i2p_dotted_page_is_blocked(self):
        st, applied, total = self._index_and_block(
            f"http://{I2P}../secret", I2P, allow_i2p=True)
        self.assertEqual(applied["hosts_blocked"], 1)
        self.assertEqual(total, 0)


if __name__ == "__main__":
    unittest.main()
