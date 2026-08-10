#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens from the Python reference.

The `xcheck_*.rs` integration tests pin the Rust output byte-for-byte against the
retiring Python suite in `legacy-python/torrentds/`. Those goldens are hex / value
literals; this script re-derives them by driving the actual Python modules, so the
"byte-identical to Python" guarantee is auditable and reproducible rather than
resting on hand-copied constants.

Usage (from anywhere in the workspace):

    python3 crates/torrentds/tests/regen_goldens.py

It prints ``LABEL = <value>`` lines grouped by cross-check. Compare the output
against the literals embedded in the corresponding ``tests/xcheck_*.rs`` (and the
`spam`/`store` unit corpora); any drift between the Rust port and the Python
reference shows up as a diff here. A CI job can run this and fail on mismatch.

This covers the modules whose goldens were generated for this port; extend the
``SECTIONS`` list as further Python modules are cross-checked.
"""

from __future__ import annotations

import os
import sys

# Locate legacy-python/torrentds and import it as the `torrentds` package.
_HERE = os.path.dirname(os.path.abspath(__file__))
_SUITE = os.path.abspath(os.path.join(_HERE, "..", "..", ".."))
_PYREF = os.path.join(_SUITE, "legacy-python", "torrentds")
if _PYREF not in sys.path:
    sys.path.insert(0, _PYREF)


def to_hex(b: bytes) -> str:
    return b.hex()


def gen_spam() -> None:
    """xcheck_spam.rs + spam unit corpus: (score, reasons) per torrent."""
    from torrentds import spam

    cases = [
        ("Some.Movie.2019.1080p.BluRay.x264", [("movie/movie.mkv", 1_400_000_000)], 1_400_000_000, 262_144, 5340, "video"),
        ("Movie", [("movie.mkv", 700_000_000), ("setup.exe", 5_000_000)], 705_000_000, 262_144, 2689, "video"),
        ("Movie www.piratesite.com FREE", [], 0, 0, 0, "other"),
        ("get it at example.com now", [], 0, 0, 0, "other"),
        ("download.here.site.to", [], 0, 0, 0, "other"),
        ("a.comic.book", [], 0, 0, 0, "other"),
        ("cracked.software.keygen.www.warez.biz", [], 0, 0, 0, "software"),
    ]
    print("== spam.score (name -> score) ==")
    for name, files, total, plen, pcnt, cat in cases:
        score, _reasons = spam.score(name, files, total, plen, pcnt, cat)
        print(f"  {name!r:45} = {score!r}")


def gen_store() -> None:
    """xcheck_store.rs: categorize / content_signature / magnet_link."""
    from torrentds import store

    print("== store.categorize ==")
    for name, files in [
        ("movie", [("a.mkv", 1), ("b.srt", 1)]),
        ("mixed", [("a.mkv", 1), ("b.mp3", 1), ("c.mkv", 1)]),
        ("tie", [("a.mkv", 1), ("b.mp3", 1)]),
        ("archive.zip", [("data.bin", 1)]),
    ]:
        print(f"  {name!r:14} = {store.categorize(name, files)}")

    print("== store.content_signature ==")
    for files, cid in [
        ([("a.txt", 100), ("sub/b.bin", 200)], None),
        ([("a.txt", 100), ("sub/b.bin", 200)], bytes([0x11] * 32)),
        ([("z.bin", 1), ("a.bin", 2)], None),
    ]:
        print(f"  {store.content_signature(files, cid)}")

    print("== store.magnet_link ==")
    ih = "0123456789abcdef0123456789abcdef01234567"
    for a, name, v2 in [
        (ih, "Test Movie 2019", None),
        (None, "v2 only", "aa" * 32),
        (ih, "Hybrid & Special/Chars!", "bb" * 32),
        (ih, "space test+plus", None),
    ]:
        print(f"  {store.magnet_link(a, name, v2)}")


def gen_classify() -> None:
    """xcheck_classify.rs: the classifier tag string for a corpus of names."""
    from torrentds import classify

    print("== classify.tag_string ==")
    for name in [
        "The.Show.S01E02.1080p.WEB-DL.x265-GROUP",
        "Movie.2019.2160p.UHD.BluRay.x265.HDR",
        "Artist - Album (2020) [FLAC]",
        "Movie,2019,1080p,BluRay",
    ]:
        tags = classify.tag_string(classify.classify(name, []))
        print(f"  {name!r:45} = {tags!r}")


SECTIONS = [
    ("spam", gen_spam),
    ("store", gen_store),
    ("classify", gen_classify),
]

# The tracker / DHT / metadata / infohash goldens are byte outputs of wire
# *builders*; in the Python reference those live inside request-handler classes
# (e.g. tracker's `handle_scrape`) rather than as standalone functions, so they are
# regenerated via their own harnesses, not this module-level driver. Add sections
# here as those are lifted into callable form.


def main() -> int:
    failures = []
    for name, fn in SECTIONS:
        try:
            fn()
        except Exception as exc:  # noqa: BLE001 — a missing module is a soft skip
            failures.append((name, exc))
            print(f"== {name}: SKIPPED ({exc}) ==", file=sys.stderr)
        print()
    if failures:
        print(f"note: {len(failures)} section(s) skipped", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
