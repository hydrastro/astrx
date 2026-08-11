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


def gen_ranking() -> None:
    from websearch import ranking as r
    print("== ranking: parse_query ==")

    def pq(raw):
        q = r.parse_query(raw)
        return (q.optional, q.required, q.excluded, q.phrases, q.highlight,
                q.intitle, q.site, q.lang, q.filetype, q.after, q.before,
                q.boost, q.penalize)
    for raw in ["rust programming", '+rust -java "web crawler"',
                "site:example.com lang:en foo",
                "intitle:Rust before:2020-01-01 boost:good.com",
                '"a" host:X.COM/ filetype:PDF']:
        print("pq:%r\t%r" % (raw, pq(raw)))
    print("== ranking: parse_date ==")
    for d in ["2020-01-01", "2021-06-15", "bad"]:
        print("pd:%r\t%r" % (d, r._parse_date(d)))
    print("== ranking: freshness ==")
    for fa, now in [(0, 1000), (1000.0, 1000.0), (1000.0, 1000.0 + 30 * 86400),
                    (1000.0, 1000.0 + 60 * 86400)]:
        print("fr:%r,%r\t%.6f" % (fa, now, r._freshness(fa, now)))
    print("== ranking: content_quality ==")
    for n in [0, 50, 100, 101, 600, 1200, 2000]:
        print("cq:%d\t%.6f" % (n, r._content_quality({"body": "x" * n})))
    print("== ranking: proximity ==")
    print("px1\t%.6f" % r._proximity_bonus("the web crawler is here",
                                            [["web", "crawler"]], ["web", "crawler"]))
    print("px2\t%.6f" % r._proximity_bonus("web then some words then crawler",
                                            [], ["web", "crawler"]))
    print("px3\t%.6f" % r._proximity_bonus("nothing relevant", [], ["web", "crawler"]))
    print("== ranking: snippet ==")
    print("sn1\t%r" % r.make_snippet("The quick brown fox jumps over the lazy dog. " * 5,
                                      ["fox"], width=60))
    print("sn2\t%r" % r.make_snippet("<script>alert(1)</script> safe word here",
                                      ["word"], width=80))
    print("sn3\t%r" % r.make_snippet("", ["x"]))


def gen_pagerank() -> None:
    from websearch import index
    conn = index.connect(":memory:")

    def up(url, host):
        index.upsert_document(conn, url, url, "", "body", host=host,
                              fetched_at=100.0, http_status=200)
    up("http://x/a", "x")
    up("http://x/b", "x")
    up("http://y/c", "y")
    up("http://z/d", "z")
    index.add_links(conn, "http://x/a", [("http://x/b", True),
                                         ("http://y/c", False), ("http://z/d", False)])
    index.add_links(conn, "http://x/b", [("http://x/a", True), ("http://y/c", False)])
    index.add_links(conn, "http://y/c", [("http://x/a", False)])
    index.recompute_incoming(conn)
    index.compute_pagerank(conn)
    index.compute_host_authority(conn)
    print("== pagerank (url, rank, host_rank, incoming) ==")
    for u in ["http://x/a", "http://x/b", "http://y/c", "http://z/d"]:
        r = conn.execute(
            "SELECT rank, host_rank, incoming FROM docs WHERE url=?", (u,)).fetchone()
        print("%s\t%.9f\t%.9f\t%d" % (u, r[0], r[1], r[2]))
    print("== host_authority ==")
    for h, rk in conn.execute("SELECT host, rank FROM host_authority ORDER BY host"):
        print("ha:%s\t%.9f" % (h, rk))


def gen_structured() -> None:
    """htmlparse stage-2 helpers — emits the Rust literals embedded verbatim in
    `tests/xcheck_structured.rs` (diff the two to prove parity)."""
    import json
    from websearch.htmlparse import (
        parse_duration, _classify_player, _is_direct_media, _first_str,
        _first_url, _type_of, _iter_json_dicts, _collect_readable,
        _balanced_json, _extract_state_json,
    )

    def rs(s):
        if s is None:
            return "None"
        out = ['"']
        for ch in s:
            if ch == '\\':
                out.append('\\\\')
            elif ch == '"':
                out.append('\\"')
            elif ch == '\n':
                out.append('\\n')
            elif ch == '\t':
                out.append('\\t')
            elif ch == '\r':
                out.append('\\r')
            elif ord(ch) < 0x20:
                out.append('\\u{%x}' % ord(ch))
            else:
                out.append(ch)
        out.append('"')
        return ''.join(out)

    def opt(s):
        return "None" if s is None else "Some(%s)" % rs(s)

    print("// ==== structured::parse_duration ====")
    for d in ["PT1H2M3S", "PT1M30S", "P1DT2H", "PT0S", "P1W", "P2DT3H4M5S",
              "PT1.5S", "PT0.5S", "PT2.5S", "PT1.4S", "PT1.6S", "pt1h", "PT1.S",
              "PT.5S", "PT1.2.3S", "P", "PT", "", "garbage", "P1Y", "  PT1H  ",
              "P1WT1H", "P1D", "PT10M", "PT1H0M0S", "P0W", "PT90M"]:
        v = parse_duration(d)
        print("    (%s, %s)," % (rs(d), "None" if v is None else "Some(%d)" % v))

    print("// ==== structured::classify_player ====")
    for src in ["https://www.youtube.com/embed/dQw4w9WgXcQ",
                "https://www.youtube.com/embed/short",
                "https://www.youtube-nocookie.com/embed/abcdef1234",
                "https://youtu.be/dQw4w9WgXcQ", "https://youtu.be/",
                "https://player.vimeo.com/video/12345",
                "https://player.vimeo.com/video/abc",
                "https://www.dailymotion.com/embed/video/x7tgad0",
                "https://www.dailymotion.com/video/x7tgad0",
                "https://dai.ly/x7tgad0",
                "https://peertube.example.org/videos/embed/abc-123",
                "https://odysee.com/@x:1/y:2", "https://rumble.com/embed/v123",
                "https://example.com/x", "https://vimeo.com/12345",
                "//youtube.com/embed/abcdef",
                "https://WWW.YOUTUBE.COM/embed/UPPER123"]:
        p, w = _classify_player(src)
        print("    (%s, (%s, %s))," % (rs(src), opt(p), opt(w)))

    print("// ==== structured::is_direct_media ====")
    for m in ["http://a/clip.mp4", "http://a/clip.MP4", "http://a/v.webm",
              "http://a/v.m3u8", "http://a/v.mpd", "http://a/v.ogv",
              "http://a/v.mov", "http://a/page.html", "http://a/noext",
              "http://a/clip.mp4?x=1"]:
        print("    (%s, %s)," % (rs(m), "true" if _is_direct_media(m) else "false"))

    print("// ==== structured::first_str ====")
    for j in ['"hello"', '"  hi  "', '["", "  x ", "y"]', '[1, 2, "z"]', '42',
              '{"a":1}', '[]', '["   ", ""]']:
        print("    (%s, %s)," % (rs(j), rs(_first_str(json.loads(j)))))

    print("// ==== structured::first_url ====")
    for j in ['"http://x"', '{"url": "http://u"}', '{"@id": "http://id"}',
              '{"contentUrl": "http://c"}', '{"url": "", "@id": "http://id"}',
              '{"url": "http://u", "@id": "http://id"}',
              '["http://a", "http://b"]', '[{"url":"http://x"}]', '{}',
              '{"url": ["http://l1", "http://l2"]}', '42']:
        print("    (%s, %s)," % (rs(j), rs(_first_url(json.loads(j)))))

    print("// ==== structured::type_of ====")
    for j in ['{"@type": "VideoObject"}', '{"@type": ["Article", "NewsArticle"]}',
              '{"@type": ["Thing", 42, "Other"]}', '{"no_type": 1}',
              '{"@type": 42}']:
        ts = _type_of(json.loads(j))
        print("    (%s, &[%s])," % (rs(j), ", ".join(rs(t) for t in ts)))

    print("// ==== structured::iter_dicts (each dict projected via type_of) ====")
    for j in ['{"@type":"A"}', '{"@graph":[{"@type":"B"},{"@type":"C"}]}',
              '[{"@type":"X"}, {"@type":"Y"}]',
              '{"@type":"Root", "nested":{"@type":"Deep"}}',
              '{"@type":"R", "@graph":[{"@type":"G1"}, {"nested":{"@type":"NG"}}]}']:
        parts = ["&[%s]" % ", ".join(rs(t) for t in _type_of(d))
                 for d in _iter_json_dicts(json.loads(j))]
        print("    (%s, &[%s])," % (rs(j), ", ".join(parts)))

    print("// ==== structured::collect_readable ====")
    for j in ['{"name":"Cats", "nested":{"description":"d"}, "url":"u"}',
              '{"title":"T", "headline":"H", "body":"B"}',
              '{"Title":"Cap", "DESCRIPTION":"UP"}', '{"name":"  spaced  "}',
              '{"name":""}', '{"name":"   "}', '{"other":"x"}',
              '{"items":[{"name":"A"},{"name":"B"}]}']:
        out = _collect_readable(json.loads(j), [])
        print("    (%s, &[%s])," % (rs(j), ", ".join(rs(s) for s in out)))

    print("// ==== structured::balanced_json ====")
    for text, start in [('{"a":1}', 0), ('prefix {"a":1} suffix', 7),
                        ('xx {"a": {"b":2}} yy', 3), ('[1, 2, [3]]', 0),
                        ('no opener here', 0), ('{"s": "has } brace"}', 0),
                        ('  {unclosed', 0), ('{"e": "esc \\" quote"}', 0),
                        ('     {"a":1}', 0), (' ' * 150 + '{}', 0)]:
        print("    (%s, %d, %s)," % (rs(text), start, opt(_balanced_json(text, start))))

    print("// ==== structured::extract_state_json ====")
    for text in ['var x=1; window.__NUXT__ = {"a":{"title":"Hi"}}; more();',
                 'window.__INITIAL_STATE__={"k":1}',
                 '__APOLLO_STATE__ = {"x":[1,2,3]}',
                 '__PRELOADED_STATE__ : {"y":true}', 'no marker here {"a":1}',
                 '__NUXT__ = not json', '__NUXT__   =   {"a":1}',
                 'a __NUXT__={"n":1} b __INITIAL_STATE__={"i":2}']:
        print("    (%s, %s)," % (rs(text), opt(_extract_state_json(text))))


SECTIONS = [gen_ssrf, gen_dedup, gen_canonical, gen_robots, gen_httpclient,
            gen_htmlparse, gen_frontier, gen_index, gen_ranking, gen_pagerank,
            gen_structured]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
