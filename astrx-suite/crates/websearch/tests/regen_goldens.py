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


SECTIONS = [gen_ssrf, gen_dedup]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
