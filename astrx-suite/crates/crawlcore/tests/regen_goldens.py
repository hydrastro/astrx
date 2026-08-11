#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for crawlcore.

Currently covers the `inflate` module: for each corpus item, Python `zlib`
produces the raw-DEFLATE, zlib-wrapped, and gzip-wrapped compressed forms that
`tests/xcheck_inflate.rs` embeds and inflates back to the original. Re-running
this and diffing against the literals in the test proves the Rust inflater stays
byte-identical to `zlib`.

    python3 crates/crawlcore/tests/regen_goldens.py
"""

from __future__ import annotations

import hashlib
import json
import zlib


def show(label: str, val) -> None:
    print(f"{label}\t{json.dumps(val, ensure_ascii=False)}")


def gen_inflate() -> None:
    corpus = {
        "empty": b"",
        "short": b"hello world",
        "repetitive": b"ab" * 300,
        "text": b"The quick brown fox jumps over the lazy dog. " * 30,
        "binary": bytes(range(256)) * 4,
        "onion_page": (
            b"<html><head><title>Test Onion</title></head><body>"
            + b"<p>content</p>" * 50
            + b"</body></html>"
        ),
    }
    print("== inflate (compressed forms; Rust inflates back to the original) ==")
    for name, data in corpus.items():
        craw = zlib.compressobj(9, zlib.DEFLATED, -zlib.MAX_WBITS)
        raw = craw.compress(data) + craw.flush()
        cgz = zlib.compressobj(9, zlib.DEFLATED, 16 + zlib.MAX_WBITS)
        gz = cgz.compress(data) + cgz.flush()
        show(f"{name}:raw", raw.hex())
        show(f"{name}:zlib", zlib.compress(data, 9).hex())
        show(f"{name}:gzip", gz.hex())


def gen_blake2b() -> None:
    """BLAKE2b goldens (unkeyed) for `tests/xcheck_blake2b.rs`: (out_len,
    input_hex, digest_hex) across small inputs, several output lengths, and a
    >128-byte message that spans two compression blocks."""
    cases = [
        (8, b""),
        (8, b"a"),
        (8, b"abc"),
        (8, b"hello world"),
        (8, b"onion"),
        (8, b"The quick brown fox jumps over the lazy dog"),
        (32, b"abc"),
        (64, b"abc"),
        (16, bytes(range(200))),
    ]
    print("== blake2b (out_len, input_hex, digest_hex) ==")
    for n, msg in cases:
        show(f"blake2b:{n}:{msg.hex()}", hashlib.blake2b(msg, digest_size=n).hexdigest())


def gen_json() -> None:
    import json
    corpus = [
        '{"@type": "VideoObject", "name": "Cats", "desc": "funny", "tags": ["a", "b"]}',
        '{"a": {"b": {"c": "deep"}}, "list": [1, "two", true, null, {"k": "v"}]}',
        '["caf\\u00e9", "emoji \\ud83d\\ude00", "tab\\tend"]',
        '{"dup": "first", "dup": "last", "n": 42, "f": 3.14}',
        '{"@graph": [{"headline": "H1"}, {"headline": "H2"}]}',
    ]

    def cs(v, out):
        if isinstance(v, str):
            out.append(v)
        elif isinstance(v, list):
            for x in v:
                cs(x, out)
        elif isinstance(v, dict):
            for _k, val in v.items():
                cs(val, out)

    def ck(v, out):
        if isinstance(v, list):
            for x in v:
                ck(x, out)
        elif isinstance(v, dict):
            for k, val in v.items():
                out.append(k)
                ck(val, out)

    print("== json (input -> string-leaves, keys via json.loads) ==")
    for s in corpus:
        v = json.loads(s)
        a = []
        cs(v, a)
        b = []
        ck(v, b)
        print("json:%r\tstrs=%r\tkeys=%r" % (s, a, b))


SECTIONS = [gen_inflate, gen_blake2b, gen_json]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
