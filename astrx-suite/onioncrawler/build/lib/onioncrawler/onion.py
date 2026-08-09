"""Darknet host validation and enforcement (Tor .onion + optional I2P .i2p).

The single source of truth for "is this a darknet host we are allowed to
touch". Everything that opens a socket or enqueues a URL must go through here
so that a clearnet / localhost / IP-literal host can never leak out over the
network.

v3 onion:  56 base32 chars + ".onion"   (ed25519 pubkey + checksum + version)
v2 onion:  16 base32 chars + ".onion"   (deprecated, off by default)
i2p b32 :  52 base32 chars + ".b32.i2p" (base32 of the destination hash)
i2p name:  any hostname ending in ".i2p" (e.g. stats.i2p) -- anchored, so it
           can never be a clearnet name.

The onion anti-leak invariant is UNCHANGED: `require_onion` still accepts ONLY
.onion. I2P is a *separate* network gated behind an explicit flag; each fetcher
is locked to exactly one network (an onion crawl only ever calls require_onion,
an i2p crawl only ever calls require_i2p), so the two can never cross-leak.

Base32 alphabet (RFC 4648, lowercase): a-z and 2-7.
"""

from __future__ import annotations

import re

# RFC 4648 base32 alphabet, lowercased. Note: no 0, 1, 8, 9.
_B32 = "[a-z2-7]"
_V3_RE = re.compile(r"^" + _B32 + r"{56}\.onion$")
_V2_RE = re.compile(r"^" + _B32 + r"{16}\.onion$")

# I2P eepsites (darknet-only extension, off unless --enable-i2p):
#   * base32 destination:  52 base32 chars + ".b32.i2p"
#   * named eepsite:        a hostname ending in ".i2p"
# Both anchor on ".i2p$", so no clearnet TLD (.com/.org/...) can ever match and
# "foo.i2p.evil.com" is refused. A bare ".i2p"/"i2p" is refused (needs a label).
_I2P_B32_RE = re.compile(r"^[a-z2-7]{52}\.b32\.i2p$")
_I2P_NAME_RE = re.compile(r"^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+i2p$")

# Scanner for finding .onion references embedded in *body text* (not just in
# <a href>). Same v3/v2 alphabet and lengths as the validators above, but with
# boundaries so a 56-char host inside a longer base32 blob is not mis-sliced and
# a longer bogus TLD (.oniony) is not matched. Optional scheme/port/path are
# captured so a full URL can be reconstructed. Case-insensitive: hosts are
# normalized (lowercased) afterwards.
_ONION_IN_TEXT = re.compile(
    r"(?:(https?)://)?"                    # 1: optional scheme
    r"(?<![a-z2-7])"                        # not preceded by a base32 char
    r"([a-z2-7]{56}|[a-z2-7]{16})\.onion"   # 2: v3 (56) or v2 (16) body
    r"(?::(\d{1,5}))?"                       # 3: optional port
    r"(/[^\s\"'<>)\]}]*)?",                 # 4: optional path
    re.IGNORECASE,
)


class NotOnionError(ValueError):
    """Raised when a host/URL is not a permitted .onion address."""


class NotDarknetError(NotOnionError):
    """Raised when a host/URL is neither a permitted .onion nor .i2p address.

    Subclasses NotOnionError so existing ``except NotOnionError`` anti-leak
    handlers (e.g. in the fetcher) catch an i2p/clearnet refusal too.
    """


def normalize_host(host: str) -> str:
    """Lowercase, strip ALL trailing dots and any :port. Never raises."""
    if host is None:
        return ""
    h = host.strip().lower()
    # strip userinfo if somehow present
    if "@" in h:
        h = h.rsplit("@", 1)[1]
    # strip port (onion hosts contain no ':', so a ':' is always a port sep)
    if h.startswith("["):  # bracketed IPv6 - never an onion, drop bracket body
        h = h[1:].split("]", 1)[0]
    elif ":" in h:
        h = h.rsplit(":", 1)[0]
    # Strip ALL trailing dots (FQDN root), not just one. Stripping a single dot
    # left "<h>.onion." as a canonical host DISTINCT from "<h>.onion": both route
    # to the same hidden service, but the dotted form escaped the host blocklist
    # (keyed on the dotless host) and split per-host politeness/budget. rstrip
    # makes normalization idempotent so every trailing-dot variant collapses to
    # the one key used by canonicalize / require_onion / host_blocked / dedup.
    h = h.rstrip(".")
    return h


def is_onion_host(host: str, allow_v2: bool = False) -> bool:
    """True iff *host* is a syntactically valid .onion address.

    Port and case are normalized first. v2 is only accepted when *allow_v2*.
    """
    h = normalize_host(host)
    if not h:
        return False
    if _V3_RE.match(h):
        return True
    if allow_v2 and _V2_RE.match(h):
        return True
    return False


def onion_version(host: str) -> int | None:
    """Return 3, 2, or None for the given host (ignores allow flag)."""
    h = normalize_host(host)
    if _V3_RE.match(h):
        return 3
    if _V2_RE.match(h):
        return 2
    return None


def require_onion(host: str, allow_v2: bool = False) -> str:
    """Return the normalized host or raise NotOnionError. Use before connect.

    CROWN INVARIANT: this accepts ONLY .onion, never .i2p or clearnet. The Tor
    fetcher gates every socket through here, so a Tor crawl can never touch an
    i2p or clearnet host.
    """
    h = normalize_host(host)
    if not is_onion_host(h, allow_v2=allow_v2):
        raise NotOnionError(f"refusing non-onion host: {host!r}")
    return h


# ------------------------------------------------------------------- i2p / darknet
def is_i2p_host(host: str) -> bool:
    """True iff *host* is a syntactically valid .i2p eepsite (b32 or named)."""
    h = normalize_host(host)
    if not h:
        return False
    return bool(_I2P_B32_RE.match(h) or _I2P_NAME_RE.match(h))


def i2p_kind(host: str) -> str | None:
    """Return 'b32', 'name', or None for the given host."""
    h = normalize_host(host)
    if _I2P_B32_RE.match(h):
        return "b32"
    if _I2P_NAME_RE.match(h):
        return "name"
    return None


def require_i2p(host: str) -> str:
    """Return the normalized host or raise. Use before an i2p connect.

    Accepts ONLY .i2p (never .onion or clearnet); the I2P fetcher gates every
    socket through here, so an i2p crawl can never touch an onion/clearnet host.
    """
    h = normalize_host(host)
    if not is_i2p_host(h):
        raise NotDarknetError(f"refusing non-i2p host: {host!r}")
    return h


def is_darknet_host(host: str, allow_v2: bool = False,
                    allow_i2p: bool = False) -> bool:
    """True iff *host* is a permitted darknet host: an .onion always, and an
    .i2p only when *allow_i2p*. Clearnet / localhost / IP-literals are always
    False. This is the anti-leak admission test used at every frontier /
    submission boundary; per-network socket locking is done by the fetcher's
    require_onion / require_i2p gate."""
    if is_onion_host(host, allow_v2=allow_v2):
        return True
    if allow_i2p and is_i2p_host(host):
        return True
    return False


def require_darknet(host: str, allow_v2: bool = False,
                    allow_i2p: bool = False) -> str:
    """Return the normalized host or raise NotDarknetError."""
    h = normalize_host(host)
    if not is_darknet_host(h, allow_v2=allow_v2, allow_i2p=allow_i2p):
        raise NotDarknetError(f"refusing non-darknet host: {host!r}")
    return h


def find_onion_urls(text: str, allow_v2: bool = False, limit: int = 100,
                    default_scheme: str = "http") -> list[str]:
    """Scan arbitrary *text* for embedded .onion references and return a list of
    candidate URL strings (deduplicated, order-preserving, capped at *limit*).

    Each candidate still has to pass canonicalize() + onion-only + the abuse
    blocklist in the caller; this only does the syntactic extraction. v2 hosts
    are dropped unless *allow_v2* (they would fail is_onion_host anyway, but we
    skip them early to keep the cap meaningful).
    """
    if not text:
        return []
    out: list[str] = []
    seen: set[str] = set()
    for m in _ONION_IN_TEXT.finditer(text):
        scheme = (m.group(1) or default_scheme).lower()
        host = (m.group(2) + ".onion").lower()
        if not is_onion_host(host, allow_v2=allow_v2):
            continue
        port = m.group(3)
        path = m.group(4) or "/"
        netloc = host if not port else f"{host}:{port}"
        url = f"{scheme}://{netloc}{path}"
        if url in seen:
            continue
        seen.add(url)
        out.append(url)
        if len(out) >= limit:
            break
    return out
