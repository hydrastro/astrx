"""A small thread-safe token-bucket rate limiter, keyed by client (IP).

Used to protect the public search/API endpoints. The clock is injectable so the
refill behaviour is deterministically unit-testable without sleeping.

The per-key table is bounded by *max_keys* with LRU eviction: when it overflows
we drop the least-recently-used key(s), NOT the whole table -- clearing
everything would hand every active client a fresh full burst at once.

Deployment note: keys should be the real transport peer, never a spoofable
header. Behind a Tor onion service every request arrives from 127.0.0.1, so the
limiter necessarily collapses to a single shared/global bucket; per-client
fairness is impossible over Tor because there is no per-client identity.
"""

from __future__ import annotations

import threading
import time
from collections import OrderedDict


class TokenBucket:
    def __init__(self, rate: float, capacity: float, now=time.monotonic,
                 max_keys: int = 100_000):
        """*rate* tokens/sec refill up to *capacity* burst, per key."""
        self.rate = float(rate)
        self.capacity = float(capacity)
        self._now = now
        self._max_keys = max(1, int(max_keys))
        # LRU order: most-recently-used key sits at the end.
        self._buckets: "OrderedDict[str, tuple[float, float]]" = OrderedDict()
        self._lock = threading.Lock()

    def allow(self, key: str, cost: float = 1.0) -> bool:
        """Consume *cost* tokens for *key*; return True if allowed, else False."""
        t = self._now()
        with self._lock:
            # pop + re-insert moves this key to the MRU end (LRU bookkeeping)
            tokens, ts = self._buckets.pop(key, (self.capacity, t))
            # refill for elapsed time
            tokens = min(self.capacity, tokens + (t - ts) * self.rate)
            if tokens >= cost:
                tokens -= cost
                allowed = True
            else:
                allowed = False
            self._buckets[key] = (tokens, t)
            # bounded memory: evict the least-recently-used key(s), not the whole
            # table, so an overflow can't reset every active client's limit.
            while len(self._buckets) > self._max_keys:
                self._buckets.popitem(last=False)
            return allowed
