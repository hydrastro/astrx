"""Shared helpers for the test suite."""

from websearch import canonical, index
from websearch.crawler import Crawler, CrawlConfig


def make_config(site, **overrides):
    host = canonical.host_of(site.base)
    kw = dict(
        scope_hosts=[host],
        base_delay=0.0,
        jitter=0.0,
        total_budget=200,
        per_host_budget=200,
        max_depth=6,
        segment_repeat_cap=3,
        query_param_cap=3,
        # The fixture runs on loopback; exempt exactly its authority from the
        # internal-IP SSRF denylist (which stays ON for everything else).
        allow_hosts=[canonical.authority_of(site.base)],
    )
    kw.update(overrides)
    return CrawlConfig(**kw)


def crawl_fixture(site, db_path, finalize=True, **overrides):
    """Crawl the fixture *site* into *db_path*; return (conn, stats)."""
    conn = index.connect(db_path)
    cfg = make_config(site, **overrides)
    crawler = Crawler(conn, cfg)
    crawler.add_seeds([site.url("/")])
    stats = crawler.run()
    if finalize:
        index.finalize(conn)
    conn.commit()
    return conn, stats


def rel_urls(conn, site):
    return sorted(
        r[0].replace(site.base, "") for r in conn.execute("SELECT url FROM docs")
    )
