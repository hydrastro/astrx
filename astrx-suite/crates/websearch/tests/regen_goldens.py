#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for websearch.

Covers the `ssrf` gate (`_ip_is_internal` over a broad IPv4/IPv6 special-range
corpus) and `dedup` (FNV-1a shingled SimHash). Re-running this and diffing
against the literals in `tests/xcheck_*.rs` proves the Rust ports stay
byte-identical to the Python reference.

    PYTHONPATH=legacy-python/websearch:legacy-python/crawlcore \
        python3 crates/websearch/tests/regen_goldens.py
"""

from __future__ import annotations


def gen_ssrf() -> None:
    from websearch.httpclient import _ip_is_internal
    ips = [
        "127.0.0.1", "10.0.0.1", "192.168.1.1", "172.16.5.4", "172.31.255.255",
        "172.32.0.1", "172.15.255.255", "172.16.0.0", "169.254.169.254",
        "169.254.0.1", "100.64.0.1", "100.63.255.255", "100.64.0.0",
        "100.127.255.255", "100.128.0.0", "100.128.0.1", "8.8.8.8", "1.1.1.1",
        "93.184.216.34", "11.0.0.1", "0.0.0.0", "255.255.255.255", "224.0.0.1",
        "239.255.255.255", "233.252.0.1", "240.0.0.1", "203.0.113.5",
        "203.0.114.0", "198.51.100.9", "198.51.101.0", "192.0.2.7", "192.0.0.5",
        "192.0.0.171", "192.0.0.255", "192.1.0.1", "192.88.99.1", "198.18.0.1",
        "198.19.255.255", "198.20.0.1", "::1", "::", "fe80::1", "fc00::1",
        "fd12:3456::1", "fec0::1", "2001:4860:4860::8888", "2606:4700::1111",
        "::ffff:127.0.0.1", "::ffff:8.8.8.8", "::ffff:169.254.169.254",
        "::ffff:10.1.2.3", "::ffff:1.2.3.4", "ff02::1", "2001:db8::1", "2002::1",
        "64:ff9b::1", "100::1", "2001::1", "2001:20::1", "not-an-ip", "",
        "999.1.1.1",
    ]
    print("== ssrf (ip, is_internal) ==")
    for ip in ips:
        print("%s\t%d" % (ip, int(_ip_is_internal(ip))))


def gen_dedup() -> None:
    from websearch.dedup import simhash, _fnv1a, hamming
    print("== dedup: fnv1a ==")
    for s in ["", "a"]:
        print("fnv1a:%r\t%d" % (s, _fnv1a(s.encode("utf-8", "replace"))))
    print("== dedup: simhash ==")
    for t in ["", "a", "the quick brown fox jumps over the lazy dog",
              "The Quick Brown Fox Jumps Over The Lazy Dog",
              "hello world foo bar baz", "café résumé señor niño"]:
        print("simhash:%r\t%d" % (t, simhash(t)))
    print("hamming\t%d" % hamming(
        simhash("the quick brown fox jumps over the lazy dog"),
        simhash("the quick brown fox jumps over the lazy cat")))


def gen_canonical() -> None:
    from websearch import canonical as c
    print("== canonical: canonicalize (input, base, result) ==")
    cases = [
        ("http://Example.COM/a/./b/../c", None),
        ("HTTP://example.com:80/path", None),
        ("https://example.com:443/", None),
        ("http://example.com:8080/x?b=2&a=1", None),
        ("http://example.com/a//b///c", None),
        ("http://example.com", None),
        ("//example.com/x", "http://base.com/"),
        ("/rel/path", "http://example.com/a/b"),
        ("../up", "http://example.com/a/b/c"),
        ("http://user:pass@example.com/x", None),
        ("http://[2001:db8::1]:8080/x", None),
        ("ftp://example.com/x", None),
        ("not a url", None),
        ("http://example.com/p?z=1&a=2&a=1", None),
        ("http://example.com/%7euser/", None),
    ]
    for url, base in cases:
        print("canon:%r|%r\t%r" % (url, base, c.canonicalize(url, base=base)))
    print("== canonical: host/authority/is_http ==")
    for u in ["http://User@Example.com:8080/x", "https://example.com/",
              "http://[2001:db8::1]:99/", "ftp://x/", "http://example.com:80/"]:
        print("parts:%r\t%r\t%r\t%s" % (u, c.host_of(u), c.authority_of(u), c.is_http_url(u)))


def gen_robots() -> None:
    from websearch.robots import parse
    print("== robots ==")
    r1 = parse("User-agent: *\nDisallow: /private\nAllow: /private/ok\nCrawl-delay: 2.5\n", "mybot")
    print("r1\t%s\t%s\t%s\t%s" % (r1.can_fetch("/private/x"), r1.can_fetch("/private/ok/y"),
                                  r1.can_fetch("/public"), r1.crawl_delay))
    r2 = parse("User-agent: mybot\nDisallow: /\nUser-agent: *\nDisallow: /x\n", "mybot")
    print("r2\t%s\t%s" % (r2.can_fetch("/anything"), r2.can_fetch("/x")))
    print("r3\t%s" % parse("", "any").can_fetch("/"))
    print("r4\t%s" % parse("User-agent: *\nDisallow:\n", "any").can_fetch("/anything"))
    r5 = parse("User-agent: *\nDisallow: /*.pdf$\n", "any")
    print("r5\t%s\t%s\t%s" % (r5.can_fetch("/a.pdf"), r5.can_fetch("/a.pdf?x"), r5.can_fetch("/a.html")))


def gen_httpclient() -> None:
    import zlib
    from websearch.httpclient import (
        _parse_content_type, _authority_exempt, _decompress, _ip_is_internal,
        decode_body,
    )
    print("== httpclient: parse_content_type ==")
    for v in ["text/HTML; charset=UTF-8", "application/json",
              "text/plain; charset=\"ISO-8859-1\"", "TEXT/Plain ; Charset='utf-8'",
              "image/png; boundary=x; charset=us-ascii", ""]:
        ct, cs = _parse_content_type(v)
        print("ctype:%r\t%r\t%r" % (v, ct, cs))

    print("== httpclient: authority_exempt ==")
    allow = ["intranet:8080", "[::1]", "Example.COM"]
    for host, port in [("intranet", 8080), ("intranet", 80), ("::1", 443),
                       ("example.com", 80), ("other", 80)]:
        print("exempt:%r:%d\t%s" % (host, port, _authority_exempt(host, port, allow)))
    print("exempt-empty\t%s" % _authority_exempt("intranet", 8080, []))

    print("== httpclient: gate decision ==")
    def gate(ips, block, exempt):
        if block and not exempt:
            for ip in ips:
                if _ip_is_internal(ip):
                    return "blocked:%s" % ip
        return "ok:%d" % len(ips)
    for ips, block, exempt in [
        (["8.8.8.8"], True, False), (["8.8.8.8", "127.0.0.1"], True, False),
        (["127.0.0.1"], True, True), (["10.0.0.1", "192.168.1.1"], False, False),
        (["1.1.1.1", "2606:4700::1111"], True, False),
        (["93.184.216.34", "169.254.169.254"], True, False),
    ]:
        print("gate:%r,%s,%s\t%s" % (ips, block, exempt, gate(ips, block, exempt)))

    print("== httpclient: decompress (enc, hex_input -> plaintext) ==")
    plain = b"the quick brown fox" * 4
    gz = zlib.compressobj(9, zlib.DEFLATED, 16 + zlib.MAX_WBITS)
    gzipped = gz.compress(plain) + gz.flush()
    zl = zlib.compress(plain, 9)                       # zlib-wrapped
    rawd = zlib.compressobj(9, zlib.DEFLATED, -zlib.MAX_WBITS)
    rawdef = rawd.compress(plain) + rawd.flush()       # raw DEFLATE
    for enc, blob in [("gzip", gzipped), ("deflate", zl), ("deflate", rawdef),
                      ("zlib", zl), ("identity", plain)]:
        out = _decompress(blob, enc, 1_000_000)
        print("dec:%s\t%s\t%s" % (enc, blob.hex(), (out == plain)))

    print("== httpclient: decode_body ==")
    for body, cs in [(b"caf\xc3\xa9", "utf-8"), (b"caf\xe9", "latin-1"),
                     (b"<meta charset=utf-8>\xc3\xa9", None),
                     (b"plain ascii text", None)]:
        print("body:%s\t%r\t%r" % (body.hex(), cs, decode_body(body, cs)))


def gen_htmlparse() -> None:
    from websearch.htmlparse import extract, guess_lang
    print("== htmlparse: core extraction (fixtures f1..f5) ==")
    # The fixtures live verbatim in tests/xcheck_htmlparse.rs; each has a
    # >=200-char body (or no recover-triggering structured data) so the Python
    # `_recover` backfill is a no-op and stage-1 core output is byte-identical.
    fixtures = {
        "f1": (
            '<html lang="en-US"><head><title>News &amp; Notes &#8212; Today</title>\n'
            '<meta name="description" content="  A concise   summary. ">\n'
            '<link rel="canonical" href="http://ex/a"><base href="http://ex/">\n'
            '<meta name="robots" content="INDEX, NoFollow"></head>\n'
            '<body><nav><a href="/home">Home</a> menu items that are boilerplate</nav>\n'
            "<h1>Main Heading</h1>\n"
            "<p>The quick brown fox jumps over the lazy dog and then the fox runs "
            "away to the woods for a while.</p>\n"
            "<script>var x = a < b ? 1 : 2;</script>\n"
            '<p>Read <a href="/more">more here</a> and also <a href="/x" '
            'rel="nofollow">this</a> for details today.</p>\n'
            "<footer>copyright boilerplate footer text here</footer></body></html>"
        ),
    }
    for name, html in fixtures.items():
        e = extract(html)
        print("%s\ttitle=%r\tdesc=%r\tlinks=%r\tcanon=%r\tbase=%r\tlang=%r\trobots=%r"
              % (name, e.title, e.description, e.links, e.canonical, e.base_href,
                 e.lang, e.meta_robots))
        print("%s.text\t%r" % (name, e.text))
    print("== htmlparse: guess_lang ==")
    for text, hint in [("the and of to in a is", None), ("le la de et les des", None),
                       ("xxxxx", "DE-de"), ("", None)]:
        print("gl:%r|%r\t%s" % (text, hint, guess_lang(text, hint)))


def gen_frontier() -> None:
    import sqlite3
    import time
    # Deterministic clock: added_at becomes insertion order, matching the Rust
    # port's monotonic counter, so the lease ordering is reproducible.
    counter = [0]

    def fake_time():
        counter[0] += 1
        return float(counter[0])

    time.time = fake_time
    from websearch.frontier import Frontier
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.isolation_level = None
    f = Frontier(conn)

    def lz(now, budget=None):
        r = f.lease(now=now, lease_seconds=120, host_budget=budget)
        return r["url"] if r else None

    print("== frontier ==")
    print("adds\t%s\t%s\t%s\t%s" % (
        f.add("http://a/1", "a", 0), f.add("http://a/2", "a", 1),
        f.add("http://b/1", "b", 0), f.add("http://a/1", "a", 0)))
    print("seen\t%s\t%s" % (f.seen("http://a/1"), f.seen("http://z")))
    print("lease1000\t%r" % lz(1000))
    f.note_fetch("a", 1100)
    print("lease1000b\t%r" % lz(1000))
    f.note_fetch("b", 1100)
    print("lease1000none\t%r" % lz(1000))
    print("nrt\t%r" % f.next_ready_time())
    print("lease1200\t%r" % lz(1200))
    f.note_fetch("a", 1300)
    f.complete("http://a/1", "done")
    f.complete("http://b/1", "error", "boom")
    print("total_done\t%d" % f.total_done())
    print("has_queued\t%s" % f.has_queued())
    print("counts\t%r" % dict(sorted(f.counts().items())))
    f.reclaim(now=1400)
    print("has_queued2\t%s" % f.has_queued())
    print("counts2\t%r" % dict(sorted(f.counts().items())))
    print("lease2000b2\t%r" % lz(2000, 2))
    print("lease2000b3\t%r" % lz(2000, 3))
    hr = f.host_row("a")
    print("host_a\t%r" % ((hr["next_time"], hr["crawl_delay"],
                           hr["robots_done"], hr["fetched"]),))


def gen_index() -> None:
    from websearch import index
    print("== index: content_hash ==")
    for parts in [("a", "b", "c"), ("T2", "", ""),
                  ("Hello", "World", "Body text here"), ("", "", ""),
                  ("café", "é", "")]:
        print("ch:%r\t%s" % (list(parts), index.content_hash(*parts)))
    conn = index.connect(":memory:")

    def up(url, **kw):
        return index.upsert_document(
            conn, url, kw.get("title", ""), kw.get("desc", ""), kw.get("body", ""),
            host=kw.get("host", ""), lang=kw.get("lang", ""),
            fetched_at=kw.get("fa", 0.0), etag=kw.get("etag", ""),
            last_modified=kw.get("lm", ""), http_status=200)

    print("== index: store ==")
    print("ids\t%d\t%d\t%d" % (
        up("http://x/a", title="A", body="alpha body", host="x", lang="en", fa=100.0, etag='"v1"'),
        up("http://x/b", title="B", body="beta body", host="x", lang="en", fa=200.0),
        up("http://y/c", title="C", body="gamma body", host="y", lang="fr", fa=300.0)))
    print("id1_re\t%d" % up("http://x/a", title="A2", body="alpha body", host="x", lang="en", fa=150.0))
    print("valid_a\t%r" % (index.get_validators(conn, "http://x/a"),))
    index.touch_revalidated(conn, "http://x/a", fetched_at=500.0, etag='"v2"')
    print("valid_a2\t%r" % (index.get_validators(conn, "http://x/a"),))
    print("due\t%r" % index.due_for_recrawl(conn, 100.0, now=1000.0))
    index.add_links(conn, "http://x/a", [("http://x/b", True), ("http://y/c", False)])
    index.add_links(conn, "http://x/a", [("http://x/b", True)])
    index.add_links(conn, "http://y/c", [("http://x/b", True)])
    index.recompute_incoming(conn)
    print("incoming_b\t%d" % conn.execute(
        "SELECT incoming FROM docs WHERE url='http://x/b'").fetchone()[0])
    s = index.stats(conn)
    print("stats\t%d\t%d\t%d\t%r\t%r\t%r\t%r" % (
        s["docs"], s["hosts"], s["links"], s["oldest"], s["newest"],
        s["top_hosts"], s["languages"]))


SECTIONS = [gen_ssrf, gen_dedup, gen_canonical, gen_robots, gen_httpclient,
            gen_htmlparse, gen_frontier, gen_index]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
