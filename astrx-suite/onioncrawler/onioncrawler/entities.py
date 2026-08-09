"""Entity-extraction verticals for the onion index.

The intel angle that Recon / Kilos made their signature: let an analyst pivot
from a page to *every other indexed onion that carries the same PGP key or
cryptocurrency address*.  This module is the pure extractor -- linear stdlib
regex over page text, no network, hard-bounded so a hostile 10 MB page can't
blow up a crawl worker.  It never raises.

Extracted kinds:
  * ``pgp`` -- an ASCII-armored PGP PUBLIC KEY BLOCK, identified by a stable
    fingerprint (SHA-1 of the whitespace-stripped armor body) so the *same* key
    on two sites yields the same value to pivot on.  (We don't parse the packet
    stream to derive the real OpenPGP fingerprint -- that needs a full parser;
    the armor-body hash is a dependable, dedupable surrogate.)
  * ``btc`` -- Bitcoin addresses: legacy/P2SH base58 and bech32 (``bc1...``).
  * ``xmr`` -- Monero standard addresses (95 chars, ``4...``).
  * ``eth`` -- Ethereum/EVM addresses (``0x`` + 40 hex).

Extraction is heuristic (regex, no base58/bech32 checksum verification), which
is the norm for these crawlers -- an operator treats it as a lead, not proof.
"""

import hashlib
import re

__all__ = ["extract", "KINDS", "KIND_PGP", "KIND_BTC", "KIND_XMR", "KIND_ETH"]

KIND_PGP = "pgp"
KIND_BTC = "btc"
KIND_XMR = "xmr"
KIND_ETH = "eth"
KINDS = (KIND_PGP, KIND_BTC, KIND_XMR, KIND_ETH)

_MAX_TEXT = 2_000_000        # scan at most ~2 MB of page text
_MAX_PER_KIND = 100          # cap entities of each kind per page

_PGP_BEGIN = "-----BEGIN PGP PUBLIC KEY BLOCK-----"
_PGP_END = "-----END PGP PUBLIC KEY BLOCK-----"
_PGP_BODY_CAP = 100_000     # a real armored key is a few KB; cap crafted giants
# Bitcoin: legacy/P2SH base58 (1/3 + 25-34 base58 chars) OR bech32 (bc1 + body).
_BTC = re.compile(
    r"\b(?:[13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[a-z0-9]{11,71})\b")
# Monero standard address: starts 4, then [0-9AB], then 93 base58 chars.
_XMR = re.compile(r"\b4[0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b")
# Ethereum / EVM: 0x + exactly 40 hex (the trailing \b rejects longer hashes).
_ETH = re.compile(r"\b0x[a-fA-F0-9]{40}\b")

_WS = re.compile(r"\s+")


def _pgp_fingerprint(armor_body: str) -> str:
    """Stable identifier for an armored key: SHA-1 of its whitespace-free body."""
    body = _WS.sub("", armor_body or "")
    return hashlib.sha1(body.encode("utf-8", "replace")).hexdigest()


def extract(text):
    """Return a de-duplicated list of ``(kind, value)`` entities found in *text*.

    Order: PGP keys first (in document order), then btc, xmr, eth.  Bounded in
    both scan length and per-kind count.
    """
    if not text:
        return []
    t = text[:_MAX_TEXT]
    out = []
    seen = set()

    def add(kind, value):
        key = (kind, value)
        if key not in seen:
            seen.add(key)
            out.append((kind, value))

    # PGP blocks are matched with a LINEAR str.find scan (not a lazy regex): a
    # hostile page with many BEGIN markers and no END would make a regex rescan
    # quadratically and pin the crawl worker.  str.find is C-level substring
    # search; a missing END simply ends the scan.
    n = 0
    pos = 0
    while n < _MAX_PER_KIND:
        b = t.find(_PGP_BEGIN, pos)
        if b == -1:
            break
        e = t.find(_PGP_END, b + len(_PGP_BEGIN))
        if e == -1:
            break                 # no closing marker -> no more complete blocks
        body = t[b + len(_PGP_BEGIN):e][:_PGP_BODY_CAP]
        add(KIND_PGP, _pgp_fingerprint(body))
        pos = e + len(_PGP_END)
        n += 1

    for rx, kind in ((_BTC, KIND_BTC), (_XMR, KIND_XMR), (_ETH, KIND_ETH)):
        n = 0
        for m in rx.finditer(t):
            add(kind, m.group(0))
            n += 1
            if n >= _MAX_PER_KIND:
                break
    return out
