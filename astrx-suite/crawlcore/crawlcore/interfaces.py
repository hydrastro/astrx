"""The injected seams crawlcore consumes but each crawler OWNS.

These are :class:`typing.Protocol` types - structural contracts, not base
classes. Nothing here has behaviour; the point is to name and document the
boundary so the shared mechanics can be reasoned about against a stable
interface while the *implementations* (especially the two security gates) live
in, and are owned by, each crawler.

Why the security boundary is modelled as an injected policy
-----------------------------------------------------------
:class:`HostPolicy` and :class:`Fetcher` are deliberately abstract:

  * onioncrawler's HostPolicy is the onion-ONLY validator + anti-leak gate: it
    refuses every non-.onion host (clearnet / localhost / confusables) BEFORE a
    socket is opened, and its Fetcher tunnels over SOCKS5-to-Tor with remote DNS.
  * websearch's HostPolicy is the SSRF internal-IP denylist (loopback / private /
    link-local / reserved, incl. IPv4-mapped IPv6) enforced on connect, on every
    redirect hop, and on robots; its Fetcher pins the socket to the validated
    address.

Both are hostile-input security gates. They are NOT shared - crawlcore only ever
*calls through* these protocols, so it can share the surrounding mechanics
without ever holding (or being able to weaken) the boundary itself.

The protocols are intentionally minimal (the common subset both crawlers already
satisfy structurally); a crawler's concrete class may - and does - expose more.
"""

from __future__ import annotations

from typing import Any, Iterable, Mapping, Optional, Protocol, Sequence, runtime_checkable


@runtime_checkable
class HostPolicy(Protocol):
    """Owned by each crawler. Decides whether a host may be contacted at all.

    ``allowed`` is a cheap boolean gate; ``require`` is the fail-closed form used
    immediately before a socket is opened (it MUST raise for a forbidden host).
    This is the security boundary and is never implemented inside crawlcore.
    """

    def allowed(self, host: str) -> bool:
        ...

    def require(self, host: str) -> str:
        """Return the normalized host or raise if it is not permitted."""
        ...


@runtime_checkable
class FetchResult(Protocol):
    """The common result shape both fetchers return."""

    url: str
    final_url: str
    status: int
    body: bytes
    error: Optional[str]

    def header(self, name: str, default: Any = None) -> Any:
        ...


@runtime_checkable
class Fetcher(Protocol):
    """Owned by each crawler. Performs one logical fetch (following redirects),
    enforcing that crawler's HostPolicy on the initial hop AND every redirect
    hop, and applying the shared decompression-bomb output cap."""

    def fetch(self, url: str, extra_headers: Optional[Mapping[str, str]] = None) -> Any:
        ...


@runtime_checkable
class Extractor(Protocol):
    """Turns fetched bytes/text into at least a title, body text, and links.

    Kept per-crawler (the two extract different metadata and boilerplate) but the
    shared contract is this common subset.
    """

    def __call__(self, data: Any) -> Any:
        ...


@runtime_checkable
class RobotsRules(Protocol):
    """A parsed robots.txt ruleset for one site.

    onioncrawler names the query ``allowed(path, agent)``; websearch names it
    ``can_fetch(path)``. Both expose an effective crawl delay. The matcher
    implementations differ (websearch's is a deliberately ReDoS-safe linear
    globber) so the parsers stay per-crawler; this only names the contract.
    """

    def can_fetch(self, path: str) -> bool:
        ...


@runtime_checkable
class Store(Protocol):
    """The durable, crash-safe crawl state seam: a leased frontier + resume.

    Schemas are frozen and crawler-specific, so the concrete stores are NOT
    shared; this protocol names the leasing/resume contract the crawl loop relies
    on: atomically lease the best queued URL, and mark it done/errored. Reclaim
    of expired leases on restart is what makes a crawl resumable.
    """

    def lease(self, *args: Any, **kwargs: Any) -> Any:
        ...

    def mark_done(self, *args: Any, **kwargs: Any) -> Any:
        ...


@runtime_checkable
class Scheduler(Protocol):
    """Recrawl timing. Implemented by :mod:`crawlcore.scheduler`."""

    def is_due(self, fetched_at: float, interval: float, now: float) -> bool:
        ...


__all__ = [
    "HostPolicy",
    "FetchResult",
    "Fetcher",
    "Extractor",
    "RobotsRules",
    "Store",
    "Scheduler",
]
