"""crawlcore.interfaces: the injected seams are structural Protocols.

A conforming duck-typed object satisfies the runtime-checkable protocol; a
non-conforming one does not. This documents (and guards) the contract each
crawler's owned security gate / fetcher / store must present to crawlcore.
"""

import unittest

from crawlcore import interfaces, scheduler


class _OnionLikePolicy:
    """Stand-in shaped like onioncrawler's onion-only gate."""

    def allowed(self, host):
        return host.endswith(".onion")

    def require(self, host):
        if not self.allowed(host):
            raise ValueError("refusing non-onion host")
        return host


class _SsrfLikePolicy:
    """Stand-in shaped like websearch's internal-IP denylist gate."""

    def allowed(self, host):
        return not host.startswith("127.")

    def require(self, host):
        if not self.allowed(host):
            raise ValueError("blocked-internal")
        return host


class _Result:
    url = final_url = ""
    status = 0
    body = b""
    error = None

    def header(self, name, default=None):
        return default


class _Fetcher:
    def fetch(self, url, extra_headers=None):
        return _Result()


class _Store:
    def lease(self, *a, **k):
        return None

    def mark_done(self, *a, **k):
        return None


class ConformanceTest(unittest.TestCase):
    def test_host_policies_conform(self):
        for pol in (_OnionLikePolicy(), _SsrfLikePolicy()):
            self.assertIsInstance(pol, interfaces.HostPolicy)

    def test_fetcher_and_result_conform(self):
        self.assertIsInstance(_Fetcher(), interfaces.Fetcher)
        self.assertIsInstance(_Result(), interfaces.FetchResult)

    def test_store_conforms(self):
        self.assertIsInstance(_Store(), interfaces.Store)

    def test_scheduler_module_conforms(self):
        self.assertIsInstance(scheduler, interfaces.Scheduler)

    def test_non_conforming_rejected(self):
        class NotAPolicy:
            pass

        self.assertNotIsInstance(NotAPolicy(), interfaces.HostPolicy)
        self.assertNotIsInstance(object(), interfaces.Fetcher)


if __name__ == "__main__":
    unittest.main()
