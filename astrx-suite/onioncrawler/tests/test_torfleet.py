"""torfleet: a pool of Tor SOCKS endpoints, with per-host stable pinning."""
import unittest

try:
    from onioncrawler.fetcher import TorSocksFetcher, build_fetcher
    from onioncrawler.config import Config
except ImportError:
    from fetcher import TorSocksFetcher, build_fetcher
    from config import Config


class TestTorfleet(unittest.TestCase):
    def test_parse_proxies_strings_and_tuples(self):
        p = TorSocksFetcher._parse_proxies(
            ["127.0.0.1:9051", ("10.0.0.2", 9050)], "127.0.0.1", 9050)
        self.assertEqual(p, [("127.0.0.1", 9051), ("10.0.0.2", 9050)])

    def test_parse_fallback_to_default(self):
        self.assertEqual(
            TorSocksFetcher._parse_proxies([], "127.0.0.1", 9050),
            [("127.0.0.1", 9050)])

    def test_single_proxy_default(self):
        f = TorSocksFetcher()
        self.assertEqual(f.pool_size, 1)
        self.assertEqual(f._pick_proxy("abc.onion"), ("127.0.0.1", 9050))

    def test_pick_is_stable_and_distributes(self):
        f = TorSocksFetcher(proxies=["a:1", "b:2", "c:3"])
        self.assertEqual(f.pool_size, 3)
        # a host is pinned to one endpoint (circuit + politeness consistency)
        self.assertEqual(f._pick_proxy("host.onion"), f._pick_proxy("host.onion"))
        # but different hosts spread across the pool
        picks = {f._pick_proxy("h%d.onion" % i) for i in range(60)}
        self.assertGreater(len(picks), 1)
        for p in picks:
            self.assertIn(p, [("a", 1), ("b", 2), ("c", 3)])

    def test_build_fetcher_includes_base_plus_pool(self):
        cfg = Config()
        cfg.fetcher = "tor"
        cfg.tor_host = "127.0.0.1"
        cfg.tor_port = 9050
        cfg.tor_pool = "127.0.0.1:9051,127.0.0.1:9052"
        f = build_fetcher(cfg)
        self.assertEqual(f.pool_size, 3)     # base 9050 + 9051 + 9052

    def test_build_fetcher_no_pool_is_single(self):
        cfg = Config()
        cfg.fetcher = "tor"
        self.assertEqual(build_fetcher(cfg).pool_size, 1)


if __name__ == "__main__":
    unittest.main()
