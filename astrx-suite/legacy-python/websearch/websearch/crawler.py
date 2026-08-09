"""The crawl loop: leasing, politeness, robots, extraction and indexing.

Discipline implemented here (production-shaped, not a toy):

  * robots.txt honoured per host, including ``Crawl-delay`` and redirect hops,
  * per-host politeness delay with jitter, enforced through the frontier,
  * max depth, per-host budget, total page budget, max response bytes and a
    content-type allowlist,
  * URL canonicalization + frontier dedup,
  * trap guards: path-segment-repeat cap and query-parameter-explosion cap,
  * capped redirects, gzip, timeouts (via :mod:`httpclient`),
  * content-hash dedup, ``rel=canonical`` and ``meta robots`` handling,
  * resumable: leases persist; ``done`` URLs are never refetched.
"""

import ipaddress
import logging
import random
import threading
import time
from urllib.parse import urljoin, urlsplit

from . import canonical, dedup, htmlparse, httpclient, index, pdftext
from .frontier import Frontier
from . import federation
from .robots import parse as parse_robots

log = logging.getLogger("websearch.crawler")


def _is_internal_ip_literal(host):
    """True iff *host* is a LITERAL IP address in an internal range.

    Reuses the crawler's SSRF internal-IP predicate
    (:func:`httpclient._ip_is_internal`) but only for hosts that are already IP
    literals: a hostname returns ``False`` because classifying it would require
    DNS resolution, and the image vertical must stay pure string work that opens
    NO socket.  Used to drop harvested ``<img>`` thumbnails whose src points at a
    loopback / link-local / private / ULA / reserved address, so the *viewer's*
    browser is never handed an internal-address image URL to fetch (a client-side
    SSRF / internal-port-scan vector).  Note ``_ip_is_internal`` fails closed on
    unparseable input, hence the explicit ``ip_address`` gate here so genuine
    public hostnames are kept.
    """
    if not host:
        return False
    try:
        ipaddress.ip_address(host)
    except ValueError:
        return False
    return httpclient._ip_is_internal(host)


def _public_resolved(raw, base):
    """Resolve *raw* against *base* and return it only if it is a PUBLIC URL.

    Pure string work: canonicalizes (dropping non-http(s) / unparseable input to
    ``''``) and applies the SAME internal-IP drop as the image vertical -- a URL
    whose host is a LITERAL loopback / link-local / private / ULA / reserved IP
    is dropped (returns ``''``), so the viewer's browser is never handed an
    internal-address video, embed or thumbnail to fetch (a client-side SSRF /
    internal-port-scan vector).  Opens NO socket: it never fetches the resource
    and the internal-IP check resolves no hostnames.
    """
    if not raw:
        return ""
    abs_url = canonical.canonicalize(raw, base=base)
    if not abs_url:
        return ""
    if _is_internal_ip_literal(canonical.host_of(abs_url)):
        return ""
    return abs_url


HTML_TYPES = {
    "text/html", "application/xhtml+xml", "application/xml", "text/xml", "",
}
# Plain-text-like types indexed verbatim (no HTML parsing).
TEXT_TYPES = {
    "text/plain", "text/markdown", "text/x-markdown", "text/csv",
    "text/tab-separated-values", "application/json", "text/x-rst",
}
PDF_TYPE = "application/pdf"


class CrawlConfig:
    def __init__(
        self,
        scope_hosts=None,            # None = crawl broadly
        user_agent=httpclient.DEFAULT_UA,
        robots_agent="astrx-websearch",
        respect_robots=True,
        timeout=10.0,
        max_bytes=2_000_000,
        max_depth=6,
        per_host_budget=500,
        total_budget=2000,
        base_delay=0.5,
        jitter=0.3,
        max_redirects=5,
        allowed_schemes=("http", "https"),
        content_types=None,
        segment_repeat_cap=3,
        query_param_cap=3,
        max_path_depth=24,
        lease_seconds=120,
        block_internal_ips=True,
        allow_hosts=(),
        index_pdf=False,
        recrawl_interval=7 * 86400.0,
        keep_alive=False,
        workers=1,
        shard_id=None,               # this node's id in the shard set (fleet mode)
        shards=(),                   # all shard ids; empty = single-node (owns all)
    ):
        self.scope_hosts = scope_hosts
        self.user_agent = user_agent
        self.robots_agent = robots_agent
        self.respect_robots = respect_robots
        self.timeout = timeout
        self.max_bytes = max_bytes
        self.max_depth = max_depth
        self.per_host_budget = per_host_budget
        self.total_budget = total_budget
        self.base_delay = base_delay
        self.jitter = jitter
        self.max_redirects = max_redirects
        self.allowed_schemes = tuple(allowed_schemes)
        if content_types is not None:
            self.content_types = set(content_types)
        else:
            self.content_types = ({"text/html", "application/xhtml+xml"}
                                  | TEXT_TYPES)
            if index_pdf:
                self.content_types.add(PDF_TYPE)
        self.segment_repeat_cap = segment_repeat_cap
        self.query_param_cap = query_param_cap
        self.max_path_depth = max_path_depth
        self.lease_seconds = lease_seconds
        self.block_internal_ips = block_internal_ips
        self.allow_hosts = tuple(allow_hosts or ())
        self.index_pdf = index_pdf
        self.recrawl_interval = recrawl_interval
        self.keep_alive = keep_alive
        self.workers = max(1, int(workers))
        self.shard_id = shard_id
        self.shards = tuple(shards or ())


def _scheme_of(url):
    return urlsplit(url).scheme.lower()


def _path_of(url):
    s = urlsplit(url)
    p = s.path or "/"
    if s.query:
        p += "?" + s.query
    return p


class Crawler:
    def __init__(self, conn, config=None, verbose=False):
        self.conn = conn
        self.cfg = config or CrawlConfig()
        self.fr = Frontier(conn)
        self.robots = {}                 # host -> Robots (in-process cache)
        self.verbose = verbose
        self.pages_fetched = 0           # counts against total_budget this run
        # Opt-in keep-alive connector; same SSRF checks on every hop + reuse.
        self._fetcher = (httpclient.Fetcher(keep_alive=True)
                         if self.cfg.keep_alive else None)
        self.stats = {
            "fetched": 0, "indexed": 0, "skipped": 0, "errors": 0,
            "robots_blocked": 0, "dups": 0, "unchanged": 0,
        }

    def _fetch(self, url, **kw):
        """Route every fetch through the SSRF-checked connector (pooled or not)."""
        if self._fetcher is not None:
            return self._fetcher.fetch(url, **kw)
        return httpclient.fetch(url, **kw)

    def close(self):
        if self._fetcher is not None:
            self._fetcher.close()

    def _log(self, event, **fields):
        """Structured, opt-in crawl log (wired to the --verbose flag)."""
        if not self.verbose:
            return
        parts = " ".join("%s=%s" % (k, v) for k, v in fields.items())
        log.info("%s %s", event, parts)

    # ---- public API -------------------------------------------------------
    def _owns(self, url):
        """Fleet mode: True iff this shard owns *url*'s host under HRW hashing.

        Single-node mode (no ``shards`` configured) owns everything, so the
        crawler is byte-for-byte unchanged.  Because a host maps to exactly one
        shard, gating every enqueue here means each shard is the sole crawler of
        its hosts -- per-host politeness needs no cross-node lock and no URL is
        ever fetched twice across the fleet.
        """
        if not self.cfg.shards:
            return True
        return federation.owns(
            canonical.host_of(url), self.cfg.shard_id, self.cfg.shards)

    def add_seeds(self, seeds):
        added = 0
        for s in seeds:
            u = canonical.canonicalize(s)
            if not u or _scheme_of(u) not in self.cfg.allowed_schemes:
                continue
            if self._owns(u) and self.fr.add(u, canonical.authority_of(u), 0):
                added += 1
        self.conn.commit()
        return added

    def enqueue_recrawls(self, interval=None, now=None):
        """Re-queue every indexed URL that is due for a recrawl.

        "Due" means ``fetched_at + interval <= now``.  Each due URL is put back
        into the frontier as ``queued`` and its host politeness counter is reset
        so the refetch is not blocked by a spent per-host budget.  The refetch
        itself is an ordinary :meth:`run` pass -- which sends a conditional GET
        (via the stored validators) and routes through the same SSRF-checked
        connector as any other fetch.  Returns the number of URLs queued.
        """
        if interval is None:
            interval = self.cfg.recrawl_interval
        due = index.due_for_recrawl(self.conn, interval, now)
        ts = time.time()
        for url, _host in due:
            authority = canonical.authority_of(url)
            self.conn.execute(
                "INSERT INTO frontier (url, host, depth, status, added_at, "
                "updated_at, reason) VALUES (?,?,0,'queued',?,?, 'recrawl') "
                "ON CONFLICT(url) DO UPDATE SET status='queued', lease_until=0, "
                "reason='recrawl', updated_at=excluded.updated_at",
                (url, authority, ts, ts))
            # Let the refetch through: clear this host's spent budget + delay.
            self.conn.execute(
                "UPDATE hosts SET fetched=0, next_time=0 WHERE host=?",
                (authority,))
        self.conn.commit()
        self._log("recrawl-scheduled", due=len(due))
        return len(due)

    def run(self, max_pages=None):
        budget = self.cfg.total_budget if max_pages is None else max_pages
        self.fr.reclaim()                # resume: recover stale leases
        while self.pages_fetched < budget:
            now = time.time()
            self.fr.reclaim(now)
            row = self.fr.lease(
                now, lease_seconds=self.cfg.lease_seconds,
                host_budget=self.cfg.per_host_budget,
            )
            if row is None:
                if not self.fr.has_queued():
                    break
                nxt = self.fr.next_ready_time(host_budget=self.cfg.per_host_budget)
                if nxt is None or nxt <= now:
                    break                # queued remain but nothing leasable
                time.sleep(min(nxt - now, 5.0))
                continue
            try:
                self._process(row["url"], row["depth"])
            except Exception as exc:      # never let one URL kill the crawl
                self.stats["errors"] += 1
                self.fr.complete(row["url"], "error", "exc:%r" % exc)
            self.conn.commit()
        self.conn.commit()
        self.close()
        return self.stats

    def run_worker(self, budget, stop):
        """A single worker of a multi-worker crawl (see :class:`MultiCrawler`).

        Coordinates purely through the shared SQLite frontier (atomic leases) and
        a shared, thread-safe page ``budget``.  A worker only stops when the
        global budget is spent, ``stop`` is set, or there is provably no more
        work -- i.e. no leasable queued URL *and* no other worker still holds a
        lease that could enqueue more.
        """
        self.fr.reclaim()
        try:
            while not stop.is_set():
                if not budget.take():
                    break
                now = time.time()
                self.fr.reclaim(now)
                row = self.fr.lease(
                    now, lease_seconds=self.cfg.lease_seconds,
                    host_budget=self.cfg.per_host_budget)
                if row is None:
                    budget.give_back()        # slot unused: no work leased
                    if self.fr.has_queued():
                        nxt = self.fr.next_ready_time(
                            host_budget=self.cfg.per_host_budget)
                        if nxt is not None and nxt > now:
                            time.sleep(min(nxt - now, 0.25))
                            continue
                    if self._peers_active():
                        time.sleep(0.02)
                        continue
                    break
                try:
                    self._process(row["url"], row["depth"])
                except Exception as exc:
                    self.stats["errors"] += 1
                    self.fr.complete(row["url"], "error", "exc:%r" % exc)
                self.conn.commit()
        finally:
            self.conn.commit()
            self.close()
        return self.stats

    def _peers_active(self):
        """True if another worker currently holds a lease (may enqueue more)."""
        return self.conn.execute(
            "SELECT 1 FROM frontier WHERE status='leased' LIMIT 1"
        ).fetchone() is not None

    # ---- per-URL processing ----------------------------------------------
    def _process(self, url, depth):
        host = canonical.authority_of(url)   # origin key: host[:port]
        scheme = _scheme_of(url)

        hrow = self.fr.host_row(host)
        if self.cfg.per_host_budget and hrow["fetched"] >= self.cfg.per_host_budget:
            self.fr.complete(url, "skipped", "host-budget")
            self.stats["skipped"] += 1
            return

        rob = self._robots_for(host, scheme)
        if self.cfg.respect_robots and not rob.can_fetch(_path_of(url)):
            self.fr.complete(url, "skipped", "robots")
            self.stats["robots_blocked"] += 1
            return

        delay = self.cfg.base_delay
        if rob.crawl_delay is not None:
            delay = max(delay, rob.crawl_delay)
        self.fr.reserve_host(host, time.time() + delay)

        # Conditional GET: if we already have this URL indexed, revalidate with
        # its stored validators so an unchanged page costs a 304, not a re-index.
        etag, last_mod = index.get_validators(self.conn, url)
        cond = {}
        if etag:
            cond["If-None-Match"] = etag
        if last_mod:
            cond["If-Modified-Since"] = last_mod

        self.pages_fetched += 1
        res = self._fetch(
            url, user_agent=self.cfg.user_agent, timeout=self.cfg.timeout,
            max_bytes=self.cfg.max_bytes, max_redirects=self.cfg.max_redirects,
            allow=self._fetch_allowed,
            block_internal=self.cfg.block_internal_ips,
            allow_hosts=self.cfg.allow_hosts,
            extra_headers=cond or None,
        )
        jitter = random.uniform(0, self.cfg.jitter) if self.cfg.jitter else 0.0
        self.fr.note_fetch(host, time.time() + delay + jitter)
        self.stats["fetched"] += 1

        if res.error:
            self.fr.complete(url, "error", res.error)
            self.stats["errors"] += 1
            self._log("error", url=url, err=res.error)
            return
        if res.status == 304:
            # Unchanged since last crawl: keep the indexed body, just refresh the
            # freshness clock and any re-sent validators.
            index.touch_revalidated(
                self.conn, url,
                etag=res.headers.get("etag"),
                last_modified=res.headers.get("last-modified"))
            self.stats["unchanged"] += 1
            self._log("unchanged", url=url)
            self.fr.complete(url, "done", "unchanged-304")
            return
        if res.status != 200:
            self.fr.complete(url, "done", "status-%d" % res.status)
            self._log("status", url=url, code=res.status)
            return
        ctype = res.content_type or ""
        if ctype and ctype not in self.cfg.content_types:
            self.fr.complete(url, "done", "ctype-%s" % ctype)
            return

        final_url = res.final_url or url
        new_etag = res.headers.get("etag", "") or ""
        new_last_mod = res.headers.get("last-modified", "") or ""

        if ctype == PDF_TYPE:
            ex = htmlparse.Extracted()
            ex.text = pdftext.extract_text(res.body)
            if not ex.text:
                # No text recovered (scanned/encrypted PDF): don't fake it.
                self.fr.complete(url, "done", "pdf-no-text")
                return
            ex.title = (pdftext.extract_title(res.body)
                        or urlsplit(final_url).path.rsplit("/", 1)[-1]
                        or final_url)
            ex.lang = htmlparse.guess_lang(ex.text)
        elif ctype in TEXT_TYPES:
            body_text = httpclient.decode_body(res.body, res.charset)
            ex = htmlparse.Extracted()
            ex.text = body_text.strip()
            ex.lang = htmlparse.guess_lang(ex.text)
        else:
            body_text = httpclient.decode_body(res.body, res.charset)
            ex = htmlparse.extract(body_text)

        base = final_url
        if ex.base_href:
            base = urljoin(final_url, ex.base_href)

        # rel=canonical: index the canonical target instead of this alias.
        canon = None
        if ex.canonical:
            canon = canonical.canonicalize(ex.canonical, base=base)
        if (canon and canon != final_url and canon != url
                and _scheme_of(canon) in self.cfg.allowed_schemes
                and canonical.in_scope(canon, self.cfg.scope_hosts)):
            self._enqueue_links(final_url, base, ex, depth,
                                follow=not ex.nofollow)
            if self._owns(canon):
                self.fr.add(canon, canonical.authority_of(canon), depth)
            self.fr.complete(url, "done", "canonical")
            return

        self._enqueue_links(final_url, base, ex, depth, follow=not ex.nofollow)

        if ex.noindex:
            self.fr.complete(url, "done", "noindex")
            return

        chash = index.content_hash(ex.title, ex.description, ex.text)
        existing = self.conn.execute(
            "SELECT url FROM docs WHERE content_hash=? LIMIT 1", (chash,)
        ).fetchone()
        if existing is not None and existing[0] != final_url:
            self.fr.complete(url, "done", "dup-of:%s" % existing[0])
            self.stats["dups"] += 1
            return

        doc_id = index.upsert_document(
            self.conn, final_url, ex.title, ex.description, ex.text,
            host=canonical.host_of(final_url), lang=ex.lang or "",
            fetched_at=time.time(), chash=chash, http_status=200,
            etag=new_etag, last_modified=new_last_mod, content_type=ctype,
            simhash=dedup.signed64(dedup.simhash(ex.text)),
        )
        self._index_images(doc_id, final_url, base, ex)
        self._index_videos(doc_id, final_url, base, ex)
        self.stats["indexed"] += 1
        self._log("indexed", url=final_url, ctype=ctype or "html",
                  bytes=len(ex.text))
        self.fr.complete(url, "done", None)

    def _index_images(self, doc_id, page_url, base, ex):
        """Store ``<img>`` metadata from the already-fetched page.

        Resolves each raw ``src`` against the page *base* with the same pure
        string canonicalizer used for links (``canonical.canonicalize``); a
        non-http(s) or unresolvable src (e.g. ``data:`` / ``mailto:``) yields
        ``None`` and is dropped.  A src whose host is a LITERAL internal-range IP
        (loopback / link-local / private / ULA) is also dropped, so the results
        view never hands the viewer's browser an internal-address thumbnail to
        fetch.  This performs NO network I/O and opens NO sockets -- it never
        touches the SSRF-guarded fetch path, and the internal-IP check resolves
        no hostnames -- because the image bytes are never downloaded; only
        metadata is indexed.
        """
        images = getattr(ex, "images", None)
        if not images:
            return
        resolved = []
        for src, alt, title, context in images:
            abs_src = canonical.canonicalize(src, base=base)
            if not abs_src:
                continue
            if _is_internal_ip_literal(canonical.host_of(abs_src)):
                continue          # internal-IP thumbnail -> client-side SSRF
            resolved.append((abs_src, alt, title, context))
        if resolved:
            index.replace_images(
                self.conn, doc_id, page_url, canonical.host_of(page_url),
                resolved)

    def _index_videos(self, doc_id, page_url, base, ex):
        """Store harvested video metadata from the already-fetched page.

        Mirrors :meth:`_index_images` exactly: each candidate URL (video /
        embed / watch / thumbnail) is resolved against the page *base* with the
        pure string canonicalizer and then run through the SAME internal-IP drop
        (:func:`_public_resolved`), so an internal-address host is removed before
        anything is stored.  A video with no remaining linkable URL is skipped.
        Performs NO network I/O and opens NO socket -- the video/thumbnail bytes
        are never downloaded; only metadata already present in the fetched HTML
        is indexed, and the browser (never the server) loads it at view time.
        """
        videos = getattr(ex, "videos", None)
        if not videos:
            return
        resolved = []
        seen = set()
        for v in videos:
            video_url = _public_resolved(v.get("video_url"), base)
            embed_url = _public_resolved(v.get("embed_url"), base)
            watch_url = _public_resolved(v.get("watch_url"), base)
            thumb = _public_resolved(v.get("thumbnail"), base)
            if not (video_url or embed_url or watch_url):
                continue          # every link dropped (internal/unusable)
            key = (video_url, embed_url, watch_url)
            if key in seen:
                continue          # collapse the same video from many signals
            seen.add(key)
            resolved.append((
                video_url, embed_url, watch_url,
                (v.get("title") or "").strip(), thumb,
                (v.get("source") or "").strip(), v.get("duration"),
                (v.get("context") or "").strip()))
        if resolved:
            index.replace_videos(
                self.conn, doc_id, page_url, canonical.host_of(page_url),
                resolved)

    # ---- link handling ----------------------------------------------------
    def _enqueue_links(self, src_url, base, ex, depth, follow=True):
        edges = []
        seen_local = set()
        for href in ex.links:
            tgt = canonical.canonicalize(href, base=base)
            if not tgt or _scheme_of(tgt) not in self.cfg.allowed_schemes:
                continue
            if tgt in seen_local:
                continue
            seen_local.add(tgt)
            internal = canonical.in_scope(tgt, self.cfg.scope_hosts)
            edges.append((tgt, internal))
            if (follow and internal and depth + 1 <= self.cfg.max_depth
                    and self._trap_ok(tgt) and self._owns(tgt)):
                self.fr.add(tgt, canonical.authority_of(tgt), depth + 1)
        if edges:
            index.add_links(self.conn, src_url, edges)

    def _trap_ok(self, url):
        if canonical.max_segment_repeat(url) > self.cfg.segment_repeat_cap:
            return False
        if canonical.query_param_count(url) > self.cfg.query_param_cap:
            return False
        if canonical.path_depth(url) > self.cfg.max_path_depth:
            return False
        return True

    # ---- robots + fetch gating -------------------------------------------
    def _fetch_allowed(self, u):
        if _scheme_of(u) not in self.cfg.allowed_schemes:
            return False
        if not canonical.in_scope(u, self.cfg.scope_hosts):
            return False
        if self.cfg.respect_robots:
            rob = self._robots_for(canonical.authority_of(u), _scheme_of(u))
            if not rob.can_fetch(_path_of(u)):
                return False
        return True

    def _robots_fetch_allowed(self, u):
        # Gate for the robots.txt fetch itself and its redirect hops: scheme +
        # scope only (NOT robots, which would recurse).  The internal-IP
        # denylist is enforced separately by httpclient via block_internal.
        if _scheme_of(u) not in self.cfg.allowed_schemes:
            return False
        return canonical.in_scope(u, self.cfg.scope_hosts)

    def _robots_for(self, authority, scheme):
        if authority in self.robots:
            return self.robots[authority]
        robots_url = "%s://%s/robots.txt" % (scheme, authority)
        text = ""
        try:
            res = self._fetch(
                robots_url, user_agent=self.cfg.user_agent,
                timeout=self.cfg.timeout, max_bytes=262_144,
                max_redirects=3, allow=self._robots_fetch_allowed,
                block_internal=self.cfg.block_internal_ips,
                allow_hosts=self.cfg.allow_hosts,
            )
            if res.error is None and res.status == 200:
                text = httpclient.decode_body(res.body, res.charset)
            # 4xx / 5xx / error -> empty -> allow all (documented behaviour)
        except Exception:
            text = ""
        rob = parse_robots(text, self.cfg.robots_agent)
        self.robots[authority] = rob
        self.fr.set_crawl_delay(authority, rob.crawl_delay)
        return rob


# ---- multi-worker driver ---------------------------------------------------

class _Budget:
    """Thread-safe global page budget shared by all workers of a crawl."""

    def __init__(self, total):
        self._remaining = total
        self._lock = threading.Lock()

    def take(self):
        with self._lock:
            if self._remaining <= 0:
                return False
            self._remaining -= 1
            return True

    def give_back(self):
        with self._lock:
            self._remaining += 1


class MultiCrawler:
    """Run several :class:`Crawler` workers over one shared frontier/index.

    Each worker gets its OWN SQLite connection (SQLite handles are per-thread)
    and its OWN keep-alive :class:`~websearch.httpclient.Fetcher`, but they all
    lease from the same frontier (atomic ``BEGIN IMMEDIATE`` leases) and draw
    from one shared page budget.  Every fetch -- on every worker and every hop --
    still goes through the SSRF-checked connector.
    """

    def __init__(self, db_path, config=None, verbose=False):
        self.db_path = db_path
        self.cfg = config or CrawlConfig()
        self.verbose = verbose
        self.workers = max(1, self.cfg.workers)
        self.stats = {
            "fetched": 0, "indexed": 0, "skipped": 0, "errors": 0,
            "robots_blocked": 0, "dups": 0, "unchanged": 0,
        }

    def _with_conn(self, fn):
        conn = index.connect(self.db_path)
        try:
            return fn(Crawler(conn, self.cfg, verbose=self.verbose))
        finally:
            conn.commit()
            conn.close()

    def add_seeds(self, seeds):
        return self._with_conn(lambda cr: cr.add_seeds(seeds))

    def enqueue_recrawls(self, interval=None):
        return self._with_conn(lambda cr: cr.enqueue_recrawls(interval))

    def run(self, max_pages=None):
        total = self.cfg.total_budget if max_pages is None else max_pages
        budget = _Budget(total)
        stop = threading.Event()
        results = []
        lock = threading.Lock()

        def work():
            conn = index.connect(self.db_path)
            try:
                cr = Crawler(conn, self.cfg, verbose=self.verbose)
                cr.run_worker(budget, stop)
                with lock:
                    results.append(dict(cr.stats))
            finally:
                conn.commit()
                conn.close()

        threads = [threading.Thread(target=work, daemon=True)
                   for _ in range(self.workers)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()

        agg = dict.fromkeys(self.stats, 0)
        for s in results:
            for k in agg:
                agg[k] += s.get(k, 0)
        self.stats = agg
        return agg
