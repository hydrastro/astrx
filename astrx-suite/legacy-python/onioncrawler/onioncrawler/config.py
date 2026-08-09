"""Central configuration. Every trap defense and limit is tunable here and via
the CLI. Defaults are conservative and safe."""

from __future__ import annotations

from dataclasses import dataclass, field, asdict


@dataclass
class Config:
    # --- storage ---
    db_path: str = "crawl.db"

    # --- fetcher / transport ---
    fetcher: str = "tor"                 # 'tor' | 'i2p' | 'direct'
    tor_host: str = "127.0.0.1"
    tor_port: int = 9050
    tor_pool: str = ""       # torfleet: comma list of extra Tor SOCKS host:port
    stream_isolation: bool = True        # per-host Tor circuit
    verify_tls: bool = False             # for https onions
    direct_map: list = field(default_factory=list)  # ["host.onion=127.0.0.1:PORT"]
    direct_network: str = "onion"        # 'onion' | 'i2p' (offline test transport)
    fetch_timeout: float = 60.0
    max_redirects: int = 5

    # --- onion policy ---
    allow_v2: bool = False               # legacy 16-char onions off by default

    # --- i2p policy (gap-closer: Tor-only -> darknet-only) ---
    # OFF by default: the crawler stays strictly .onion unless the operator opts
    # in. When on, .i2p hosts become admissible AND an i2p fetcher can be built;
    # onion and i2p crawls remain network-locked (no cross-leak).
    enable_i2p: bool = False
    i2p_proxy_host: str = "127.0.0.1"    # local I2P router HTTP proxy
    i2p_proxy_port: int = 4444

    # --- concurrency + politeness (trap #1) ---
    workers: int = 4                     # global concurrency cap
    crawl_delay: float = 3.0             # base per-host delay (seconds)
    crawl_delay_jitter: float = 1.5      # +/- random jitter
    lease_ttl: float = 300.0             # seconds before a leased URL is reclaimed
    respect_robots_crawl_delay: bool = True
    max_robots_crawl_delay: float = 30.0 # cap to avoid delay-based tarpit

    # --- robots (trap #2) ---
    obey_robots: bool = True
    obey_meta_robots: bool = True
    obey_x_robots_tag: bool = True

    # --- hard limits (trap #3) ---
    max_depth: int = 8
    max_pages_per_host: int = 500        # per-host budget
    max_total_pages: int = 10000         # whole-run cap
    max_unique_urls: int = 200000        # frontier backstop (trap #9)
    max_response_bytes: int = 2_000_000
    # Dedicated (larger) read cap used ONLY to hash a media/non-text resource for
    # the media-hash abuse filter. A blocklisted image/video served above
    # max_response_bytes would otherwise fail the text read cap and never be
    # hashed, letting its host escape the block; when a media blocklist is
    # configured the crawler re-fetches such a resource up to this cap. Media
    # too large to verify even here gets its host blocked (fail-closed).
    media_max_bytes: int = 12_000_000
    allowed_content_types: tuple = ("text/html", "text/plain")

    # --- canonical / path traps (trap #4, #6) ---
    max_path_segments: int = 12          # infinite-depth path guard
    max_segment_repeats: int = 3         # repeated-segment guard

    # --- query explosion / calendar bomb (trap #5) ---
    max_urls_per_template: int = 50      # same host+path+query-keys cap
    max_urls_per_skeleton: int = 200     # same shape (numeric ids collapsed)
    pagination_numeric_cap: int = 25     # numeric/date param bomb cap

    # --- content dedup (trap #7) ---
    dedup_content: bool = True

    # --- trap scoring / auto-blacklist (trap #8) ---
    dup_ratio_threshold: float = 0.85    # fraction duplicate => trapped
    dup_ratio_min_samples: int = 20
    error_ratio_threshold: float = 0.6   # fraction errored => trapped
    error_ratio_min_samples: int = 10

    # --- discovery ---
    discover_body_onions: bool = True    # scan page body text for bare .onions
    max_text_onions_per_page: int = 100  # cap discovered onions per page
    max_links_per_page: int = 500        # cap <a href> links processed per page
                                         # (bounds per-page link-graph growth)
    obey_sitemaps: bool = True           # honor robots.txt Sitemap: directives
    max_sitemap_urls: int = 5000         # cap URLs enqueued from one host's sitemaps
    max_sitemaps_per_host: int = 50      # cap sitemap docs fetched per host (index recursion)
    max_sitemap_depth: int = 2           # sitemapindex recursion depth cap

    # --- recrawl / freshness ---
    recrawl_ttl: float = 7 * 24 * 3600.0  # default per-page recrawl interval
    conditional_get: bool = True          # send If-None-Match / If-Modified-Since
    recrawl_backoff: float = 1.5          # grow interval x this when unchanged (304/same hash)
    recrawl_max_interval: float = 30 * 24 * 3600.0  # cap the backed-off interval

    # --- liveness / dead-onion aging ---
    liveness_fail_threshold: int = 3     # consecutive failures before a host is 'down'
    dead_after_down_recrawls: int = 5    # down across N recrawl cycles -> 'dead' (hidden)

    # --- ranking ---
    authority_weight: float = 0.0        # blend host PageRank into bm25 (0 = off)
    collapse_duplicates: bool = True     # collapse near-dup mirrors in results
    simhash_threshold: int = 3           # Hamming distance for near-dup

    # --- connection reuse ---
    reuse_connections: bool = True       # HTTP keep-alive pool per host where possible

    # --- abuse filtering (REQUIRED) ---
    blocklist_hosts_path: str = "blocklist_hosts.txt"
    blocklist_keywords_path: str = "blocklist_keywords.txt"
    blocklist_media_path: str = "blocklist_media.txt"   # one hex sha256 per line
    blocklist_host_md5_path: str = ""                   # Ahmia md5(domain) banlist

    # --- curated seed list + scheduled re-seed ---
    submission_ttl: float = 0.0          # expire never-crawled queued seeds (s); 0=off
    seed_list_path: str = ""             # curated known-onions seed file
    reseed_interval: float = 0.0         # seconds; 0 = off (no periodic reseed)

    # --- run control ---
    max_pages_this_run: int = 0          # 0 = unlimited (used by resume test)

    # --- search server ---
    bind_host: str = "127.0.0.1"         # privacy: localhost only
    bind_port: int = 8802
    results_per_page: int = 10

    # --- public endpoint rate limiting (token bucket, per client IP) ---
    rate_limit_enabled: bool = True
    rate_limit_rps: float = 5.0          # sustained requests/sec refill
    rate_limit_burst: float = 20.0       # bucket capacity

    # --- optional metrics/health gate (OFF by default) ---
    # /metrics and /health emit only AGGREGATE counters (never onion hosts, IPs,
    # queries or seeds), so they are open by default and a monitor/compose poller
    # keeps working with no config. If an operator publishes the raw search
    # server, setting a token requires a matching ?token= / X-Metrics-Token /
    # Authorization: Bearer on /metrics and /health. /healthz stays a trivial,
    # always-open liveness probe regardless (container healthcheck).
    metrics_token: str = ""              # "" = gate disabled (open)

    # --- admin (submit/purge/recrawl) HTTP Basic auth (off unless both set) ---
    admin_user: str = ""
    admin_pass: str = ""
    # --- admin blocklist editor (POST /blocklist) token (off unless set) ---
    # Consumed by the AstrX blocklist editor; a single bearer token gates
    # POST /blocklist (kind=host|keyword&value=...). Unset => endpoint 403s.
    admin_token: str = ""
    allow_public_submit: bool = False    # allow /add without auth (off by default)
    # Untrusted (public) /add submissions are NOT trusted seeds: they honour the
    # frontier trap backstops (max_unique_urls / per-host / template / skeleton)
    # and this per-request URL count cap, so an anonymous submitter cannot grow
    # the frontier past the caps. Operator CLI / authed /add stay trusted.
    max_public_add_urls: int = 100       # max URLs accepted per public /add call

    user_agent: str = "OnionCrawler/1.0"

    def to_dict(self):
        return asdict(self)
