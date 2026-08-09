"""Curated known-onions seed list: import + validate + dedup + scheduled reseed.

An operator keeps a file of known-good darknet roots (one URL/host per line).
`load_seed_list` reads + canonicalizes + dedups it (dropping any clearnet line
so nothing can leak); `reseed` re-enqueues those roots against the frontier so
the index keeps rediscovering roots even after they were crawled to 'done'.

Both paths go through the SAME onion-only (or darknet-only, when --enable-i2p)
canonicalization + abuse blocklist gate as every other intake, so a curated
seed can never bypass the anti-leak or the abuse filter.
"""

from __future__ import annotations

from .canonical import canonicalize

# reseed_url / enqueue reason codes that mean "refused by a cap or inactive host"
_CAPPED = frozenset(
    {"unique-budget", "host-budget", "template-cap", "skeleton-cap", "host-dead"}
)


def _canon(line: str, allow_v2: bool, allow_i2p: bool):
    """Canonicalize a seed line, defaulting the scheme for a bare host."""
    s = (line or "").strip()
    if not s or s.startswith("#"):
        return None
    cu = canonicalize(s, allow_v2=allow_v2, allow_i2p=allow_i2p)
    if cu is None and "://" not in s:
        cu = canonicalize("http://" + s, allow_v2=allow_v2, allow_i2p=allow_i2p)
    return cu


# Bounds so a huge or malicious seed file cannot exhaust memory / CPU. The file
# is streamed line-by-line (never readlines()): each read is length-capped (so a
# gigabyte-with-no-newline can't be buffered as one line), the number of accepted
# roots is capped, and the total lines scanned is capped (so an all-junk file
# can't spin forever).
_MAX_SEED_LINE_BYTES = 4096
_MAX_SEEDS = 100_000
_MAX_SEED_LINES = 5_000_000


def load_seed_list(path: str, allow_v2: bool = False,
                   allow_i2p: bool = False,
                   max_seeds: int = _MAX_SEEDS) -> list[str]:
    """Read a curated seed file and return deduped, validated canonical URL
    strings (order-preserving). '#'-comments + blanks are ignored; any line
    that is not a valid darknet URL is silently dropped (no clearnet leak).

    Streamed with bounded per-line reads and a cap on accepted roots + total
    lines scanned, so a huge/malicious --seed-list file cannot OOM the crawler
    (this runs at startup and periodically from the reseed loop)."""
    out: list[str] = []
    seen: set[str] = set()
    lines_scanned = 0
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            while len(out) < max_seeds and lines_scanned < _MAX_SEED_LINES:
                # readline(size) caps how much a single (possibly newline-less)
                # line can buffer; a valid darknet URL is far under the cap, so a
                # split long junk line just fails canonicalization and is dropped.
                raw = fh.readline(_MAX_SEED_LINE_BYTES)
                if not raw:
                    break
                lines_scanned += 1
                line = raw.split("#", 1)[0].strip()
                if not line:
                    continue
                cu = _canon(line, allow_v2, allow_i2p)
                if cu is None or cu.url in seen:
                    continue
                seen.add(cu.url)
                out.append(cu.url)
    except OSError:
        return []
    return out


def reseed(storage, abuse, seeds, allow_v2: bool = False,
           allow_i2p: bool = False, caps: dict | None = None,
           force: bool = True) -> dict:
    """Re-enqueue curated seed roots. Each seed is canonicalized (darknet-only),
    blocklist-checked, then handed to storage.reseed_url (which requeues an
    existing root or enqueues a new one, respecting host-state + trap caps).

    Returns aggregate counts: reseeded (existing root requeued), added (new
    root enqueued), blocked (abuse host), capped (trap/budget/inactive host),
    not-onion (invalid/clearnet).
    """
    out = {"reseeded": 0, "added": 0, "blocked": 0, "capped": 0, "not-onion": 0}
    for s in seeds:
        cu = _canon(s, allow_v2, allow_i2p)
        if cu is None:
            out["not-onion"] += 1
            continue
        if abuse is not None and abuse.host_blocked(cu.host):
            out["blocked"] += 1
            continue
        res = storage.reseed_url(cu, caps=caps, force=force)
        if res == "requeued":
            out["reseeded"] += 1
        elif res == "ok":
            out["added"] += 1
        elif res in _CAPPED:
            out["capped"] += 1
        else:
            out[res] = out.get(res, 0) + 1
    return out
