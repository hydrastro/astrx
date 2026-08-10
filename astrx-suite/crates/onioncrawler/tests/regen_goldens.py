#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for onioncrawler.

The `xcheck_*.rs` integration tests pin the Rust port byte-for-byte against the
retiring Python reference in `legacy-python/onioncrawler/`. This script re-derives
the expected values by driving the actual Python modules, so the "byte-identical
to Python" guarantee is auditable and reproducible rather than resting on
hand-copied constants.

Usage (from anywhere in the workspace):

    python3 crates/onioncrawler/tests/regen_goldens.py

It prints `LABEL <TAB> json(value)` lines grouped by cross-check. Compare the
output against the literals embedded in the corresponding `tests/xcheck_*.rs`;
any drift between the Rust port and the Python reference shows up as a diff here.
Extend the SECTIONS as further modules are cross-checked.
"""

from __future__ import annotations

import json
import os
import sys

# Locate legacy-python/onioncrawler and import it as the `onioncrawler` package.
_HERE = os.path.dirname(os.path.abspath(__file__))
_SUITE = os.path.abspath(os.path.join(_HERE, "..", "..", ".."))
_PYREF = os.path.join(_SUITE, "legacy-python", "onioncrawler")
if _PYREF not in sys.path:
    sys.path.insert(0, _PYREF)

from onioncrawler import canonical, entities, lang, onion  # noqa: E402

V3 = "a" * 56
V3B = "abcdefghijklmnopqrstuvwxyz234567" + "a" * 24  # 32 + 24 = 56
V2 = "b" * 16
I2PB32 = "c" * 52


def show(label: str, val) -> None:
    print(f"{label}\t{json.dumps(val, ensure_ascii=False)}")


def gen_onion() -> None:
    """xcheck_onion.rs: normalize / validators / i2p / darknet / find_onion."""
    print("== onion.normalize_host ==")
    for h in [
        "Example.ONION.", f"{V3}.onion", f"{V3}.onion:8080", f"user@{V3}.onion",
        f"[{V3}.onion]:80", f"{V3}.onion...", "  Foo.Onion  ", "", "HTTP://x",
        "a.b.i2p.", f"{I2PB32}.B32.I2P",
    ]:
        show(f"normalize_host {h!r}", onion.normalize_host(h))

    print("== onion.is_onion_host (v2 off) ==")
    for h in [f"{V3}.onion", f"{V3B}.onion", f"{V2}.onion", f"{V3}.onion.",
              f"{V3}.onion:9050", "notonion.com", f"{V3}0.onion", f"{V3[:-1]}.onion",
              "", f"{I2PB32}.b32.i2p", "stats.i2p"]:
        show(f"is_onion_v2off {h!r}", onion.is_onion_host(h))

    print("== onion.is_onion_host (v2 on) ==")
    for h in [f"{V2}.onion", f"{V3}.onion", "z1z1z1z1z1z1z1z1.onion"]:
        show(f"is_onion_v2on {h!r}", onion.is_onion_host(h, allow_v2=True))

    print("== onion.onion_version ==")
    for h in [f"{V3}.onion", f"{V2}.onion", "bad.onion", f"{V3}.ONION"]:
        show(f"onion_version {h!r}", onion.onion_version(h))

    print("== onion.is_i2p_host / i2p_kind ==")
    for h in [f"{I2PB32}.b32.i2p", "stats.i2p", "a.b.i2p", "i2p", ".i2p",
              "foo.i2p.evil.com", f"{V3}.onion", "xn--foo.i2p", "-bad.i2p",
              "bad-.i2p", f"{I2PB32}.B32.I2P"]:
        show(f"is_i2p {h!r}", onion.is_i2p_host(h))
        show(f"i2p_kind {h!r}", onion.i2p_kind(h))

    print("== onion.is_darknet_host ==")
    for (h, v2, i2) in [
        (f"{V3}.onion", False, False), (f"{V2}.onion", False, False),
        (f"{V2}.onion", True, False), ("stats.i2p", False, False),
        ("stats.i2p", False, True), ("evil.com", False, True),
    ]:
        show(f"is_darknet {h!r} v2={v2} i2p={i2}",
             onion.is_darknet_host(h, allow_v2=v2, allow_i2p=i2))

    print("== onion.find_onion_urls ==")
    corpus = [
        (f"visit http://{V3}.onion/path and {V2}.onion too", False),
        (f"visit http://{V3}.onion/path and {V2}.onion too", True),
        (f"bare {V3}.onion here", False),
        (f"HTTPS://{V3}.ONION:8080/A/b?x=1 mixed case", False),
        (f"x{V3}.onion adjacency blocked", False),
        (f"({V3}.onion) parens then stop", False),
        (f"{V3}.onion:123456/over five digits", False),
        (f"dup {V3}.onion and {V3}.onion again", False),
        (f"{'d' * 72}.onion too-long blob", False),
        ("no onions here at all, just text with words", False),
        (f"path stops at quote {V3}.onion/a\"b", False),
        (f"i2p {I2PB32}.b32.i2p not scanned by find_onion", False),
    ]
    for (text, v2) in corpus:
        show(f"find_onion v2={v2} {text!r}", onion.find_onion_urls(text, allow_v2=v2))


def gen_lang() -> None:
    """xcheck_lang.rs: guess_lang over Latin + Cyrillic samples."""
    print("== lang.guess_lang ==")
    samples = [
        ("the quick brown fox jumps over the lazy dog and it is on the log", 8),
        ("el gato de la casa que no es de los perros con la comida para el", 8),
        ("le chat de la maison et les chiens dans le jardin pour vous", 8),
        ("der Hund und die Katze mit dem Ball ist nicht auf das Haus", 8),
        ("questo di che la per con non una come ma se anche gli", 8),
        ("de que os para com nao por mais dos ao seu uma", 8),
        ("и в не на что с по как это из за для же", 8),
        ("short text", 8),
        ("aaa bbb ccc ddd eee fff ggg hhh", 8),
        ("the and of to", 3),
    ]
    for (text, mt) in samples:
        show(f"guess_lang mt={mt} {text!r}", lang.guess_lang(text, min_tokens=mt))
    show("known_languages", lang.known_languages())


def gen_canonical() -> None:
    """xcheck_canonical.rs: canonicalize + template/skeleton/query keys."""
    v3 = "a" * 56
    ot = "b" * 56
    c16 = "c" * 16
    d52 = "d" * 52
    cases = [
        (f"http://{v3}.onion/", None, False, False),
        (f"http://{v3}.onion", None, False, False),
        (f"HTTP://{v3.upper()}.ONION/Path/To", None, False, False),
        (f"http://{v3}.onion:80/x", None, False, False),
        (f"https://{v3}.onion:443/x", None, False, False),
        (f"http://{v3}.onion:8080/x", None, False, False),
        (f"http://{v3}.onion/a/./b/../c", None, False, False),
        (f"http://{v3}.onion//a///b", None, False, False),
        (f"http://{v3}.onion/a/b/", None, False, False),
        (f"http://{v3}.onion/../../etc", None, False, False),
        (f"http://{v3}.onion/%7Euser/%2e/x", None, False, False),
        (f"http://{v3}.onion/a b/c", None, False, False),
        (f"http://{v3}.onion/café/menü", None, False, False),
        (f"http://{v3}.onion/s?utm_source=x&q=1&ref=y", None, False, False),
        (f"http://{v3}.onion/s?a=&b=2&c", None, False, False),
        (f"http://{v3}.onion/s?b=2&a=1", None, False, False),
        (f"http://{v3}.onion/s?a=2&a=1", None, False, False),
        (f"http://{v3}.onion/s?q=hello world&r=a+b", None, False, False),
        (f"http://{v3}.onion/s?a=1;b=2", None, False, False),
        (f"http://{v3}.onion/s?path=/x/y&eq=a%3Db", None, False, False),
        (f"http://{v3}.onion/x?y=1#frag", None, False, False),
        ("http://example.com/", None, False, False),
        (f"ftp://{v3}.onion/", None, False, False),
        (f"http://{c16}.onion/", None, False, False),
        (f"http://{c16}.onion/x", None, True, False),
        ("http://stats.i2p/x", None, False, False),
        ("http://stats.i2p/x", None, False, True),
        (f"http://{d52}.b32.i2p/x", None, False, True),
        ("/b/c", f"http://{v3}.onion/a/x", False, False),
        ("sub/page", f"http://{v3}.onion/a/b", False, False),
        ("../c", f"http://{v3}.onion/a/b/c", False, False),
        ("?q=1", f"http://{v3}.onion/a/b", False, False),
        (f"//{ot}.onion/x", f"http://{v3}.onion/a", False, False),
        ("#top", f"http://{v3}.onion/a/b?q=1", False, False),
        ("", f"http://{v3}.onion/a/b?q=1", False, False),
        (f"http://{v3}.onion/post/12345/comments", None, False, False),
        (f"http://{v3}.onion/x/abcdef0123456789/y", None, False, False),
        (f"http://{v3}.onion/2020/01/02/title", None, False, False),
        (f"http://{v3}.onion/Foo/BarBaz", None, False, False),
        (f"http://{v3}.onion/cal?year=2020&month=1&day=2", None, False, False),
    ]
    print("== canonical.canonicalize ==")
    for (url, base, v2, i2p) in cases:
        c = canonical.canonicalize(url, base=base, allow_v2=v2, allow_i2p=i2p)
        val = None if c is None else {
            "url": c.url, "tmpl": c.template_key(),
            "skel": c.skeleton_key(), "qk": list(c.query_keys()),
        }
        show(f"canon {url!r} base={base!r} v2={v2} i2p={i2p}", val)


def gen_entities() -> None:
    """xcheck_entities.rs: extract (pgp/btc/xmr/eth)."""
    lines = [
        "Contact our PGP key below:",
        "-----BEGIN PGP PUBLIC KEY BLOCK-----",
        "Version: OnionMail",
        "",
        "mQENBFabc123DEF456ghiJKLmno789PQRstu",
        "wxyz0123456789ABCDEFabcdef+/=ZZZZ",
        "=Ab9",
        "-----END PGP PUBLIC KEY BLOCK-----",
        "Donations: BTC 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
        "bech32 bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4",
        "XMR 44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A",
        "ETH 0x52908400098527886E0F7030069857D2E4169EE7",
        "duplicate BTC again 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
        "toolong 0x52908400098527886E0F7030069857D2E4169EE7abcd should not match",
        "adjacency xxxx1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2xxxx blocked",
        "second eth 0xde0B295669a9FD93d5F28D9Ec85E40f4cb697BAe end",
    ]
    print("== entities.extract ==")
    show("entities", [[k, v] for (k, v) in entities.extract("\n".join(lines))])


SECTIONS = [gen_onion, gen_lang, gen_canonical, gen_entities]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
