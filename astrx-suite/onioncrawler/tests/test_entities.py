"""Pure entity extraction (PGP keys, BTC/XMR/ETH addresses)."""
import time
import unittest

try:
    from onioncrawler import entities
except ImportError:
    import entities


class TestEntities(unittest.TestCase):
    def test_btc_legacy_and_bech32(self):
        text = ("donate 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa or "
                "bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq thanks")
        got = dict((k, v) for k, v in entities.extract(text))
        vals = [v for k, v in entities.extract(text) if k == "btc"]
        self.assertIn("1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa", vals)
        self.assertIn("bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq", vals)

    def test_eth(self):
        text = "send to 0x52908400098527886E0F7030069857D2E4169EE7 now"
        vals = [v for k, v in entities.extract(text) if k == "eth"]
        self.assertEqual(vals, ["0x52908400098527886E0F7030069857D2E4169EE7"])

    def test_eth_not_confused_with_long_hash(self):
        # a 64-hex txid must NOT be picked up as a 40-hex address
        text = "0x" + "a" * 64
        self.assertFalse([v for k, v in entities.extract(text) if k == "eth"])

    def test_xmr(self):
        addr = "4" + "A" + "B" * 93   # 4, [0-9AB], then 93 base58 chars
        vals = [v for k, v in entities.extract("pay " + addr + " ok")
                if k == "xmr"]
        self.assertEqual(vals, [addr])

    def test_pgp_fingerprint_stable_across_whitespace(self):
        block = ("-----BEGIN PGP PUBLIC KEY BLOCK-----\n\n"
                 "mQINBFxyz123ABCDEF\nabcDEF==\n"
                 "-----END PGP PUBLIC KEY BLOCK-----")
        block2 = block.replace("\n", "\r\n")   # same body, different newlines
        a = [v for k, v in entities.extract(block) if k == "pgp"]
        b = [v for k, v in entities.extract(block2) if k == "pgp"]
        self.assertEqual(len(a), 1)
        self.assertEqual(a, b)                  # fingerprint is whitespace-stable

    def test_dedup_within_page(self):
        text = "0xabcabcabcabcabcabcabcabcabcabcabcabcabca " * 5
        vals = [v for k, v in entities.extract(text) if k == "eth"]
        self.assertEqual(len(vals), 1)          # same address collapsed

    def test_empty_and_none(self):
        self.assertEqual(entities.extract(""), [])
        self.assertEqual(entities.extract(None), [])

    def test_bounded_on_hostile_input(self):
        text = ("0x%040x " % 1) * 50000         # 50k addresses
        t = time.monotonic()
        got = entities.extract(text)
        dt = time.monotonic() - t
        self.assertLess(dt, 2.0)                 # linear + capped
        eth = [v for k, v in got if k == "eth"]
        self.assertLessEqual(len(eth), 100)      # per-kind cap enforced


if __name__ == "__main__":
    unittest.main()
