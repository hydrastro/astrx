"""Shared test wiring: build a Crawler pointed at the fixture via DirectFetcher."""

from __future__ import annotations

from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import DirectFetcher
from onioncrawler.abuse import AbuseFilter

try:  # package-mode discovery vs. discover -s tests (top-level modules)
    from .fixtures import ONION_BLOCKED, BLOCK_KEYWORD
except ImportError:
    from fixtures import ONION_BLOCKED, BLOCK_KEYWORD


def make_config(db_path, **over) -> Config:
    cfg = Config()
    cfg.db_path = db_path
    cfg.fetcher = "direct"
    cfg.crawl_delay = 0.0
    cfg.crawl_delay_jitter = 0.0
    cfg.workers = 2
    cfg.max_depth = 6
    cfg.max_urls_per_template = 8
    cfg.pagination_numeric_cap = 5
    cfg.max_urls_per_skeleton = 10
    cfg.max_path_segments = 6
    cfg.max_segment_repeats = 2
    cfg.lease_ttl = 30.0
    cfg.fetch_timeout = 5.0
    cfg.max_pages_per_host = 500
    cfg.recrawl_ttl = 7 * 24 * 3600.0
    for k, v in over.items():
        setattr(cfg, k, v)
    return cfg


def build_crawler(db_path, fixture, **over):
    cfg = make_config(db_path, **over)
    storage = Storage(cfg.db_path)
    fetcher = DirectFetcher(
        hostmap=fixture.hostmap,
        max_bytes=cfg.max_response_bytes,
        max_redirects=cfg.max_redirects,
        timeout=cfg.fetch_timeout,
        allow_v2=cfg.allow_v2,
    )
    abuse = AbuseFilter(hosts=[ONION_BLOCKED], keywords=[BLOCK_KEYWORD])
    crawler = Crawler(cfg, storage, fetcher, abuse)
    return cfg, storage, crawler
