"""BEP-33 DHT-scrape bloom-filter population estimator."""
import unittest

from torrentds import bep33


class TestBep33(unittest.TestCase):
    def test_empty_filter_is_zero(self):
        self.assertEqual(bep33.estimate(bep33.new_filter()), 0)

    def test_roundtrip_estimate_near_n(self):
        # build a filter from N distinct IPs; the estimate should land close.
        for n in (10, 50, 100, 200):
            ips = ["10.%d.%d.%d" % (i // 65536, (i // 256) % 256, i % 256)
                   for i in range(n)]
            est = bep33.estimate(bep33.build_filter(ips))
            # BEP-33 is a statistical estimate; allow generous tolerance
            self.assertGreater(est, n * 0.5, "n=%d est=%d too low" % (n, est))
            self.assertLess(est, n * 1.8, "n=%d est=%d too high" % (n, est))

    def test_ipv6(self):
        ips = ["2001:db8::%x" % i for i in range(1, 40)]
        est = bep33.estimate(bep33.build_filter(ips))
        self.assertGreater(est, 15)

    def test_more_ips_more_estimate(self):
        small = bep33.estimate(bep33.build_filter(
            ["10.0.0.%d" % i for i in range(20)]))
        big = bep33.estimate(bep33.build_filter(
            ["10.0.1.%d" % i for i in range(150)]))
        self.assertGreater(big, small)

    def test_estimate_from_response(self):
        sd = bep33.build_filter(["1.2.3.%d" % i for i in range(30)])
        pe = bep33.build_filter(["4.5.6.%d" % i for i in range(60)])
        s, l = bep33.estimate_from_response({b"BFsd": sd, b"BFpe": pe})
        self.assertGreater(s, 12)
        self.assertGreater(l, s)                 # more leechers than seeders
        # a response with no BEP-33 filters -> (None, None)
        self.assertEqual(bep33.estimate_from_response({b"values": []}),
                         (None, None))

    def test_malformed_ip_skipped(self):
        # build_filter tolerates junk without raising
        bf = bep33.build_filter(["1.2.3.4", "not-an-ip", "5.6.7.8"])
        self.assertGreaterEqual(bep33.estimate(bf), 1)


if __name__ == "__main__":
    unittest.main()
