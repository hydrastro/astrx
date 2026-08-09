"""The crawl engine: leases URLs, fetches politely over the pluggable fetcher,
enforces every trap defense, extracts + indexes, and enqueues discovered
.onion links. Resumable and crash-safe via storage.py.
"""

from __future__ import annotations

import hashlib
import random
import re
import threading
import time
from urllib.parse import parse_qsl

from .canonical import canonicalize
from .extract import extract_html
from . import traps
from . import onion
from .sitemap import parse_sitemap
from .robots import parse_robots, empty_rules, RobotsRules

_WS = re.compile(r"\s+")


def content_hash(title: str, text: str) -> str | None:
    norm = _WS.sub(" ", ((title or "") + "\n" + (text or "")).strip().lower())
    if not norm:
        return None
    return hashlib.sha1(norm.encode("utf-8")).hexdigest()


class Crawler:
    def __init__(self, config, storage, fetcher, abuse_filter, log=None):
        self.cfg = config
        self.storage = storage
        self.fetcher = fetcher
        self.abuse = abuse_filter
        # This crawl's network is fixed by its fetcher. allow_i2p admits .i2p
        # into the frontier ONLY for an i2p crawl; an onion crawl stays strictly
        # .onion (the crown invariant), so onion and i2p can never cross-leak.
        self.allow_i2p = getattr(fetcher, "allow_i2p", False)
        self.stop = threading.Event()
        self._robots_cache: dict[str, RobotsRules] = {}
        self._robots_lock = threading.Lock()
        self._run_count = 0
        self._run_lock = threading.Lock()
        # daemon reseed keep-alive: when a scheduled reseed is configured, idle
        # workers park instead of terminating so the periodic reseed can refill.
        self._keep_alive = bool(
            getattr(config, "reseed_interval", 0.0) and config.seed_list_path)
        self.log = log or (lambda *a: None)

    # ------------------------------------------------------------------ run
    def add_seeds(self, seeds):
        n = 0
        for s in seeds:
            cu = canonicalize(s, allow_v2=self.cfg.allow_v2,
                              allow_i2p=self.allow_i2p)
            # network lock: drop any seed not on this crawl's network (an onion
            # crawl already drops .i2p at canonicalize; the host_ok gate also
            # drops a stray off-network seed on an i2p crawl).
            if cu is None or (self.allow_i2p and not self.fetcher.host_ok(cu.host)):
                self.log(f"skip off-network seed: {s}")
                continue
            self.storage.ensure_host(cu.host)
            if self.storage.add_seed(cu) == "ok":
                n += 1
        return n

    def reseed(self, seeds=None):
        """Scheduled / on-demand re-enqueue of the curated seed roots. Uses the
        configured seed_list_path when *seeds* is None. Trusted operator path
        (force=True): revives dead roots, re-queues done roots, adds new ones,
        never touches trapped/blocked hosts. Returns aggregate counts."""
        from . import seedlist
        if seeds is None:
            path = getattr(self.cfg, "seed_list_path", "")
            if not path:
                return {"reseeded": 0, "added": 0}
            seeds = seedlist.load_seed_list(
                path, allow_v2=self.cfg.allow_v2, allow_i2p=self.allow_i2p)
        res = seedlist.reseed(
            self.storage, self.abuse, seeds, allow_v2=self.cfg.allow_v2,
            allow_i2p=self.allow_i2p, caps=None, force=True)
        self.log(f"reseed: {res}")
        return res

    def _reseed_loop(self):
        interval = max(1.0, float(getattr(self.cfg, "reseed_interval", 0.0)))
        while not self.stop.wait(interval):
            try:
                self.reseed()
            except Exception as e:  # a reseed must never kill the crawl
                self.log(f"reseed error: {e!r}")

    def run(self, seeds=None):
        # allow a Crawler instance to be run repeatedly (e.g. a recrawl loop):
        # a previous run()'s finally-block leaves stop set.
        self.stop.clear()
        # crash recovery: reclaim leases whose owner died
        reclaimed = self.storage.reclaim_expired()
        if reclaimed:
            self.log(f"reclaimed {reclaimed} expired lease(s) on startup")
        # dead-onion aging: count this recrawl cycle for hosts still down, and
        # demote any that have been down across the configured number of cycles.
        dead = self.storage.age_dead_hosts(self.cfg.dead_after_down_recrawls)
        if dead:
            self.log(f"demoted {dead} dead onion host(s)")
        # recrawl scheduler: requeue pages that are due (fetched_at + interval).
        if self.cfg.recrawl_ttl and self.cfg.recrawl_ttl > 0:
            due = self.storage.mark_recrawl_due(
                default_interval=self.cfg.recrawl_ttl)
            if due:
                self.log(f"recrawl: {due} page(s) due")
        if seeds:
            added = self.add_seeds(seeds)
            self.log(f"seeded {added} new URL(s)")

        # scheduled re-seed: a configured curated seed list is primed once at
        # startup (re-enqueues its roots, e.g. on a resumable restart), and -- if
        # reseed_interval is also set -- re-primed periodically so the index keeps
        # rediscovering roots. When the periodic loop is on, idle workers
        # keep-alive across an empty frontier so the reseed can refill it.
        reseed_thread = None
        if getattr(self.cfg, "seed_list_path", ""):
            self.reseed()  # prime the curated seed list once up front
        if self._keep_alive:
            reseed_thread = threading.Thread(target=self._reseed_loop, daemon=True)
            reseed_thread.start()

        n = max(1, self.cfg.workers)
        threads = [threading.Thread(target=self._worker, args=(i,), daemon=True)
                   for i in range(n)]
        for t in threads:
            t.start()
        try:
            while any(t.is_alive() for t in threads):
                for t in threads:
                    t.join(timeout=0.2)
        finally:
            self.stop.set()
            for t in threads:
                t.join(timeout=5.0)
            # graceful shutdown: return in-flight leases to the queue so a
            # restart re-crawls them (crash path relies on lease expiry).
            self.storage.reclaim_all_leased()
            # release any idle pooled keep-alive sockets
            close = getattr(self.fetcher, "close", None)
            if callable(close):
                close()
        return self.storage.stats()

    # --------------------------------------------------------------- worker
    def _worker(self, wid):
        cfg = self.cfg
        while not self.stop.is_set():
            # run-scoped page cap (used by the resume test)
            if cfg.max_pages_this_run and self._run_count >= cfg.max_pages_this_run:
                self.stop.set()
                break
            # global stored-pages cap
            if cfg.max_total_pages and \
                    self.storage.counter("pages_stored") >= cfg.max_total_pages:
                self.stop.set()
                break

            lease = self.storage.lease(time.time(), cfg.lease_ttl)
            if lease is None:
                queued, leased_active = self.storage.pending_summary()
                if queued == 0 and leased_active == 0:
                    if not self._keep_alive:
                        break  # frontier drained -> done
                    # scheduled-reseed daemon: park and re-check; the reseed
                    # loop refills the frontier on its interval.
                    if self.stop.wait(0.2):
                        break
                    continue
                if self.stop.wait(0.05):
                    break
                continue
            try:
                self._process(lease)
            except Exception as e:  # never let one URL kill a worker
                self.log(f"worker {wid} error on {lease.get('url')}: {e!r}")
                self.storage.mark_error(lease["id"], f"exception:{e}")

    def _bump_run(self):
        with self._run_lock:
            self._run_count += 1

    # -------------------------------------------------------------- process
    def _process(self, lease):
        cfg = self.cfg
        fid = lease["id"]
        url = lease["url"]
        host = lease["host"]
        depth = lease["depth"]

        cu = canonicalize(url, allow_v2=cfg.allow_v2, allow_i2p=self.allow_i2p)
        if cu is None:
            self.storage.mark_error(fid, "uncanonicalizable")
            return

        # abuse: blocked host never fetched, host blacklisted for the run
        if self.abuse.host_blocked(host):
            self.storage.set_host_state(host, "blocked", "abuse-host")
            self.storage.mark_error(fid, "blocked-host")
            self.storage.log_trap(host, url, "blocked-host")
            return

        rules = self._robots_for(host, cu.scheme)

        # robots.txt disallow (trap #2)
        if cfg.obey_robots and not rules.allowed(cu.path, cfg.user_agent):
            self.storage.mark_done(fid)
            self.storage.log_trap(host, url, "robots-disallow")
            self._apply_politeness(host)
            return

        # conditional GET: re-fetch a known page cheaply (freshness)
        cond = self._conditional_headers(cu.url)
        result = self.fetcher.fetch(cu.url, extra_headers=cond)
        self.storage.host_counter_bump(host, "fetch_count")
        self._bump_run()

        # 304 Not Modified: page unchanged. Bump last-seen, back the recrawl
        # interval off, do NOT re-index. Counts as liveness "up".
        if result.status == 304:
            self.storage.touch_page(
                cu.url, time.time(), grow_interval=cfg.recrawl_backoff,
                max_interval=cfg.recrawl_max_interval, base_interval=cfg.recrawl_ttl)
            self.storage.record_fetch_up(host)
            self.storage.mark_done(fid)
            self._apply_politeness(host)
            return

        # media-hash abuse filter (Ahmia-grade) -- runs BEFORE the ok-guard so a
        # blocklisted image/video served ABOVE max_response_bytes (which fails
        # the text read cap and returns ok=False/too_large) is still verified:
        # we re-fetch it up to the dedicated media cap, hash it, and block the
        # host on a hit (or when it is media too large to verify -> fail-closed).
        # Inert (zero extra fetches) unless an operator configured a media list.
        if self.abuse.has_media_blocklist and \
                self._media_block(host, fid, url, cu, result):
            return

        if not result.ok:
            self.storage.host_counter_bump(host, "error_count")
            # liveness tracks *reachability*: an HTTP response (even a 4xx/5xx)
            # means the onion is up; only a transport failure with no response
            # (status 0) counts toward the consecutive-failure / dead-onion path.
            if result.status >= 100:
                self.storage.record_fetch_up(host)
            else:
                self.storage.record_fetch_down(
                    host, threshold=cfg.liveness_fail_threshold)
            self.storage.mark_error(fid, result.error or f"http-{result.status}")
            self._score_host(host)
            self._apply_politeness(host)
            return

        # a real 2xx fetch: the host is alive
        self.storage.record_fetch_up(host)

        ctype = result.content_type

        # content-type allowlist (trap #3)
        if ctype and cfg.allowed_content_types and \
                ctype not in cfg.allowed_content_types:
            self.storage.mark_done(fid)
            self.storage.log_trap(host, url, f"ctype:{ctype}")
            self._apply_politeness(host)
            return

        # X-Robots-Tag header
        noindex = nofollow = False
        if cfg.obey_x_robots_tag:
            xr = (result.header("x-robots-tag", "") or "").lower()
            noindex = "noindex" in xr or "none" in xr
            nofollow = "nofollow" in xr or "none" in xr

        charset = _charset_from_ctype(result.header("content-type", ""))
        # cap <a href> links harvested per page (bounds link-graph growth and
        # parse memory: a hostile page can no longer emit unbounded links/edges)
        ext = extract_html(result.body, charset,
                           max_links=cfg.max_links_per_page)
        if cfg.obey_meta_robots:
            noindex = noindex or ext.meta_noindex
            nofollow = nofollow or ext.meta_nofollow

        # abuse: content keyword blocklist -> DROP from index (trap: CSAM etc.)
        reason = self.abuse.page_blocked(host, ext.title, ext.text)
        if reason:
            self.storage.mark_done(fid)
            self.storage.log_trap(host, url, reason)
            self._apply_politeness(host)
            # a hard host block only for host-list hits; keyword hits drop page
            return

        # index (content dedup, trap #7)
        if not noindex:
            chash = content_hash(ext.title, ext.text)
            outcome = self.storage.store_page(
                cu.url, host, ext.title, ext.text, chash, result.status,
                ctype, len(result.body), time.time(),
                dedup=cfg.dedup_content,
                etag=result.header("etag"),
                last_modified=result.header("last-modified"),
                interval=cfg.recrawl_ttl,
            )
            if outcome == "duplicate":
                self.storage.log_trap(host, url, "dup-content")
            elif outcome == "unchanged":
                # content identical to last crawl: back the recrawl interval off
                self.storage.touch_page(
                    cu.url, time.time(), grow_interval=cfg.recrawl_backoff,
                    max_interval=cfg.recrawl_max_interval,
                    base_interval=cfg.recrawl_ttl)
        else:
            self.storage.log_trap(host, url, "noindex")

        # enqueue links + plaintext .onions found in the body (unless nofollow)
        if not nofollow:
            discovered = []
            # body-text .onion discovery is onion-only; skip it on an i2p crawl
            # so a discovered onion can't pollute the i2p frontier (it would be
            # refused at the socket anyway).
            if cfg.discover_body_onions and not self.allow_i2p:
                discovered = onion.find_onion_urls(
                    ext.text, allow_v2=cfg.allow_v2,
                    limit=cfg.max_text_onions_per_page)
            self._enqueue_links(cu, list(ext.links) + discovered, depth)

        self.storage.mark_done(fid)
        self._score_host(host)
        self._apply_politeness(host)

    # ----------------------------------------------------------- media filter
    def _media_block(self, host, fid, url, cu, result):
        """Media-hash abuse filter. Returns True (and blocks the host) iff the
        fetched resource is media whose bytes are on the media blocklist -- or is
        media too large to verify. Runs before the ok-guard so media served above
        max_response_bytes cannot evade the block by failing the text read cap.
        """
        cfg = self.cfg
        ctype = result.content_type
        is_media = bool(ctype) and _is_media_ctype(ctype, cfg)

        # Fast path: the whole resource fit under the text read cap.
        if result.ok and not result.truncated:
            if is_media:
                return self._media_verdict(
                    host, fid, url, self.abuse.media_bytes_blocked(result.body))
            return False

        # It exceeded the text read cap (too_large abort, or a decompressed body
        # truncated). Known text -> not our concern (never re-download a text
        # tarpit). Media, or an unknown type from a headerless too_large abort ->
        # re-fetch up to the dedicated media cap so a large known-bad media still
        # blocks its host.
        if not (result.too_large or (result.truncated and is_media)):
            return False
        if ctype and not is_media:
            return False  # confirmed oversized text -> leave to normal handling

        mres = self.fetcher.fetch(cu.url, max_bytes=cfg.media_max_bytes)
        self.storage.host_counter_bump(host, "fetch_count")
        if mres.ok and not mres.truncated:
            if _is_media_ctype(mres.content_type, cfg):
                return self._media_verdict(
                    host, fid, url, self.abuse.media_bytes_blocked(mres.body))
            return False  # turned out to be indexable text -> not media
        # Still not fully downloadable (exceeds even the media cap). If it is
        # CONFIRMED media we cannot hash it against the blocklist, so fail closed
        # and block the host. An unknown content-type is left alone to avoid
        # over-blocking a large legitimate non-media file.
        mct = mres.content_type
        if mct and _is_media_ctype(mct, cfg):
            return self._media_verdict(
                host, fid, url, "oversized-unverifiable", is_hash=False)
        return False

    def _media_verdict(self, host, fid, url, hit, is_hash=True):
        """Apply a media-filter hit: flag the host 'blocked' + dead-letter the
        URL + log the trap. Returns True iff *hit* is a real match."""
        if not hit:
            return False
        reason = f"blocked-media:{hit}" if is_hash else f"blocked-media-{hit}"
        self.storage.set_host_state(host, "blocked", "abuse-media")
        self.storage.mark_error(fid, reason[:64])
        self.storage.log_trap(host, url, reason)
        self._apply_politeness(host)
        return True

    def _conditional_headers(self, url):
        """Build If-None-Match / If-Modified-Since from the stored page, if any."""
        if not self.cfg.conditional_get:
            return None
        page = self.storage.get_page(url)
        if page is None:
            return None
        headers = {}
        etag = page["etag"] if "etag" in page.keys() else None
        lm = page["last_modified"] if "last_modified" in page.keys() else None
        if etag:
            headers["If-None-Match"] = etag
        if lm:
            headers["If-Modified-Since"] = lm
        return headers or None

    # --------------------------------------------------------- enqueue links
    def _enqueue_links(self, parent, links, depth):
        cfg = self.cfg
        if depth + 1 > cfg.max_depth:  # max depth (trap #3/#6)
            return
        caps = {
            "max_unique_urls": cfg.max_unique_urls,
            "max_pages_per_host": cfg.max_pages_per_host,
            "max_urls_per_template": cfg.max_urls_per_template,
            "max_urls_per_skeleton": cfg.max_urls_per_skeleton,
        }
        seen_edges = set()
        for href in links:
            child = canonicalize(href, base=parent.url, allow_v2=cfg.allow_v2,
                                 allow_i2p=self.allow_i2p)
            if child is None:
                continue  # non-darknet / unusable -> dropped (darknet-only)

            # network lock: on an i2p crawl, drop any onion/clearnet host (and
            # vice-versa) so the frontier stays single-network. The fetcher gate
            # is the hard socket guarantee; this keeps the frontier clean too.
            # (No-op on an onion crawl: canonicalize already admitted onion only.)
            if self.allow_i2p and not self.fetcher.host_ok(child.host):
                self.storage.log_trap(child.host, child.url, "off-network")
                continue

            if self.abuse.host_blocked(child.host):
                self.storage.log_trap(child.host, child.url, "blocked-host-link")
                continue

            # path-shape traps: too deep / repeated / cyclic (trap #6)
            if traps.is_path_trap(child.path, cfg.max_path_segments,
                                  cfg.max_segment_repeats):
                self.storage.log_trap(child.host, child.url, "path-trap")
                continue

            # calendar / pagination bomb: numeric-only query gets a tighter cap
            child_caps = dict(caps)
            qpairs = parse_qsl(child.query, keep_blank_values=True)
            if traps.looks_like_pagination(qpairs):
                child_caps["max_urls_per_template"] = min(
                    cfg.max_urls_per_template, cfg.pagination_numeric_cap)

            reason = self.storage.enqueue(child, depth + 1, 0, child_caps)
            if reason not in ("ok", "dup-url"):
                self.storage.log_trap(child.host, child.url, reason)
                continue

            # Persist the inter-onion link edge ONLY for an admitted URL (deduped
            # per parent page so a nav-bar link doesn't inflate the weight).
            # Recording it after the enqueue caps means a flood of distinct-host
            # links the frontier refuses cannot grow link_edges without bound.
            if child.host != parent.host and \
                    (parent.host, child.host) not in seen_edges:
                seen_edges.add((parent.host, child.host))
                self.storage.add_link_edge(parent.host, child.host)

    # ------------------------------------------------------------ trap score
    def _score_host(self, host):
        cfg = self.cfg
        h = self.storage.get_host(host)
        if h is None or h["state"] != "active":
            return
        pages = h["pages_count"]
        dup = h["dup_count"]
        err = h["error_count"]
        fetch = h["fetch_count"]

        if cfg.max_pages_per_host and pages >= cfg.max_pages_per_host:
            self.storage.set_host_state(host, "trapped", "page-budget-exceeded")
            self.storage.log_trap(host, "", "trapped:page-budget")
            return
        seen = dup + pages
        if seen >= cfg.dup_ratio_min_samples and \
                dup / max(1, seen) >= cfg.dup_ratio_threshold:
            self.storage.set_host_state(host, "trapped", "duplicate-ratio")
            self.storage.log_trap(host, "", "trapped:dup-ratio")
            return
        if fetch >= cfg.error_ratio_min_samples and \
                err / max(1, fetch) >= cfg.error_ratio_threshold:
            self.storage.set_host_state(host, "trapped", "error-ratio")
            self.storage.log_trap(host, "", "trapped:error-ratio")
            return

    # ------------------------------------------------------------- politeness
    def _effective_delay(self, host):
        h = self.storage.get_host(host)
        if h is not None and h["crawl_delay"] is not None:
            return float(h["crawl_delay"])
        return self.cfg.crawl_delay

    def _apply_politeness(self, host):
        delay = self._effective_delay(host)
        jitter = random.uniform(0.0, max(0.0, self.cfg.crawl_delay_jitter))
        self.storage.set_next_allowed(host, time.time() + delay + jitter)

    # ---------------------------------------------------------------- robots
    def _robots_for(self, host, scheme) -> RobotsRules:
        with self._robots_lock:
            cached = self._robots_cache.get(host)
            if cached is not None:
                return cached
        # DB cache (persists across resume)
        h = self.storage.get_host(host)
        if h is not None and h["robots_fetched_at"]:
            rules = parse_robots(h["robots_body"]) if h["robots_present"] \
                else empty_rules()
            self._install_robots(host, rules)
            return rules

        rules = empty_rules()
        body = None
        present = False
        if self.cfg.obey_robots:
            robots_url = f"{scheme}://{host}/robots.txt"
            res = self.fetcher.fetch(robots_url)
            if res.ok and res.body:
                try:
                    body = res.body.decode("utf-8", errors="replace")
                    rules = parse_robots(body)
                    present = True
                except Exception:
                    rules = empty_rules()
        # effective crawl-delay (capped to avoid delay-based tarpit)
        delay = None
        if present and self.cfg.respect_robots_crawl_delay:
            cd = rules.crawl_delay(self.cfg.user_agent)
            if cd is not None:
                delay = min(cd, self.cfg.max_robots_crawl_delay)
        self.storage.save_robots(host, body, present, time.time(), delay)
        self._install_robots(host, rules)
        # honor Sitemap: directives once, right after we first fetch robots.
        if present and self.cfg.obey_sitemaps and rules.sitemaps:
            self._process_sitemaps(host, scheme, rules.sitemaps)
        return rules

    def _install_robots(self, host, rules):
        with self._robots_lock:
            self._robots_cache[host] = rules

    # --------------------------------------------------------------- sitemaps
    def _process_sitemaps(self, host, scheme, sitemap_urls):
        """Fetch + parse the sitemaps advertised in robots.txt and enqueue the
        page URLs they list. Handles <sitemapindex> recursion (bounded) and is
        onion-only + abuse-filtered like every other enqueue. All budgets are
        capped to prevent a sitemap bomb."""
        cfg = self.cfg
        hrow = self.storage.get_host(host)
        if hrow is not None and "sitemaps_done" in hrow.keys() \
                and hrow["sitemaps_done"]:
            return
        enqueued = 0
        fetched = 0
        # (url, depth) work queue; index files add children at depth+1
        queue = [(u, 0) for u in sitemap_urls]
        seen = set()
        while queue and fetched < cfg.max_sitemaps_per_host and \
                enqueued < cfg.max_sitemap_urls:
            sm_url, sdepth = queue.pop(0)
            scu = canonicalize(sm_url, allow_v2=cfg.allow_v2,
                               allow_i2p=self.allow_i2p)
            if scu is None or scu.url in seen:
                continue  # onion-only: non-onion sitemap targets are dropped
            seen.add(scu.url)
            if self.abuse.host_blocked(scu.host):
                continue
            fetched += 1
            res = self.fetcher.fetch(scu.url)
            if not res.ok or not res.body:
                continue
            doc = parse_sitemap(res.body, max_locs=cfg.max_sitemap_urls)
            if doc.kind == "sitemapindex":
                if sdepth < cfg.max_sitemap_depth:
                    for loc in doc.locs:
                        queue.append((loc, sdepth + 1))
                continue
            # urlset (or unknown-with-locs): enqueue each page URL
            for loc in doc.locs:
                if enqueued >= cfg.max_sitemap_urls:
                    break
                child = canonicalize(loc, allow_v2=cfg.allow_v2,
                                     allow_i2p=self.allow_i2p)
                if child is None:
                    continue
                if self.abuse.host_blocked(child.host):
                    self.storage.log_trap(child.host, child.url,
                                          "blocked-host-sitemap")
                    continue
                reason = self.storage.enqueue(child, 1, 0, {
                    "max_unique_urls": cfg.max_unique_urls,
                    "max_pages_per_host": cfg.max_pages_per_host,
                    "max_urls_per_template": cfg.max_urls_per_template,
                    "max_urls_per_skeleton": cfg.max_urls_per_skeleton,
                })
                if reason == "ok":
                    enqueued += 1
        self.storage.mark_sitemaps_done(host)
        if enqueued:
            self.log(f"sitemap: enqueued {enqueued} url(s) for {host[:12]}…")


def _charset_from_ctype(ctype: str):
    if not ctype:
        return None
    m = re.search(r"charset=([\w\-]+)", ctype, re.I)
    return m.group(1) if m else None


def _is_media_ctype(ctype: str, cfg) -> bool:
    """A downloaded resource is 'media' (a candidate for the media-hash filter)
    when it carries a content-type that is NOT one of the indexable text types.
    That covers images/audio/video/octet-stream and any other binary we fetched.
    An empty content-type is not treated as media (its type is unknown)."""
    if not ctype:
        return False
    return ctype not in (cfg.allowed_content_types or ())
