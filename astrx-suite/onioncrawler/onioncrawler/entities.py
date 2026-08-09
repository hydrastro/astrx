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

_PGP = re.compile(
    r"-----BEGIN PGP PUBLIC KEY BLOCK-----(.{0,200000}?)"
    r"-----END PGP PUBLIC KEY BLOCK-----", re.DOTALL)
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

    n = 0
    for m in _PGP.finditer(t):
        add(KIND_PGP, _pgp_fingerprint(m.group(1)))
        n += 1
        if n >= _MAX_PER_KIND:
            break

    for rx, kind in ((_BTC, KIND_BTC), (_XMR, KIND_XMR), (_ETH, KIND_ETH)):
        n = 0
        for m in rx.finditer(t):
            add(kind, m.group(0))
            n += 1
            if n >= _MAX_PER_KIND:
                break
    return out
