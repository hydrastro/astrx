"""(a) onion-host validator accepts v3 and rejects clearnet/invalid."""

import unittest

from onioncrawler.onion import (
    is_onion_host, normalize_host, require_onion, onion_version, NotOnionError,
)

V3 = "a" * 56 + ".onion"
V2 = "b" * 16 + ".onion"


class TestOnionValidator(unittest.TestCase):
    def test_accepts_v3(self):
        self.assertTrue(is_onion_host(V3))
        self.assertEqual(onion_version(V3), 3)

    def test_accepts_v3_case_and_port(self):
        self.assertTrue(is_onion_host(("A" * 56) + ".onion"))
        self.assertTrue(is_onion_host(V3 + ":80"))
        self.assertTrue(is_onion_host(V3 + "."))  # trailing dot

    def test_rejects_clearnet(self):
        for h in ["example.com", "onion.example.com", "www.google.com",
                  "127.0.0.1", "localhost", "torproject.org.onion.evil.com",
                  "onion", ".onion", "", None]:
            self.assertFalse(is_onion_host(h), h)

    def test_rejects_wrong_length(self):
        self.assertFalse(is_onion_host("a" * 55 + ".onion"))
        self.assertFalse(is_onion_host("a" * 57 + ".onion"))

    def test_rejects_bad_base32_chars(self):
        # 0, 1, 8, 9 are not in the base32 alphabet
        self.assertFalse(is_onion_host("0" * 56 + ".onion"))
        self.assertFalse(is_onion_host(("a" * 55 + "1") + ".onion"))

    def test_v2_off_by_default_on_by_flag(self):
        self.assertFalse(is_onion_host(V2))
        self.assertTrue(is_onion_host(V2, allow_v2=True))
        self.assertEqual(onion_version(V2), 2)

    def test_require_onion_raises_on_clearnet(self):
        with self.assertRaises(NotOnionError):
            require_onion("example.com")
        with self.assertRaises(NotOnionError):
            require_onion("http://example.com/")  # not a bare host
        self.assertEqual(require_onion(V3 + ":80"), V3)

    def test_normalize_host(self):
        self.assertEqual(normalize_host("EXAMPLE.COM:8080"), "example.com")
        self.assertEqual(normalize_host("user@" + V3), V3)


if __name__ == "__main__":
    unittest.main()
