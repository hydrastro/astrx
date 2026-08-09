"""Operator-configurable abuse filtering (REQUIRED, not optional).

Three lists, shipped empty for the operator to fill:
  * host blocklist    - onion addresses that must never be indexed
  * keyword blocklist - if any keyword appears in a page's title/text, the page
                        is DROPPED from the index (and its host can be trapped)
  * media blocklist   - one hex SHA-256 per line; when the crawler downloads a
                        media/non-text resource its bytes are hashed and, on a
                        match, the page is DROPPED and its host flagged (the
                        Ahmia-grade media hash path). See the README.

Operators of any legitimate onion search index MUST configure this to exclude
abusive material, in particular CSAM. See the README ("Abuse filtering").

This is a first-class, tested code path: abuse.check_* is called on every page
before it can be stored, and a hit means the page is never indexed.
"""

from __future__ import annotations

import hashlib
import os
import re

from .onion import normalize_host


class AbuseFilter:
    def __init__(self, hosts=None, keywords=None, media_hashes=None,
                 host_md5s=None):
        self.hosts = set(normalize_host(h) for h in (hosts or []) if h.strip())
        # keywords are matched case-insensitively as whole-ish tokens
        self._keywords = [k.lower() for k in (keywords or []) if k.strip()]
        self._kw_regexes = [self._compile_kw(k) for k in self._keywords]
        # media: a set of lowercase hex sha256 digests to drop on
        self.media = set(
            self._norm_hash(h) for h in (media_hashes or []) if h and h.strip())
        # Ahmia-format host banlist: md5(onion_domain) hex digests. Lets an
        # operator subscribe to Ahmia's published banned-domain hash list without
        # ever holding the plaintext onion addresses; a host whose md5 is here is
        # blocked exactly like an explicit host entry.
        self.host_md5s = set(
            self._norm_hash(h) for h in (host_md5s or []) if h and h.strip())

    @staticmethod
    def _compile_kw(kw: str) -> re.Pattern:
        # Word-boundary-ish match so 'scam' doesn't hit 'scamper'... but still
        # catch multi-word phrases and punctuation. Use boundaries around the
        # whole phrase.
        return re.compile(r"(?<![0-9a-z])" + re.escape(kw) + r"(?![0-9a-z])",
                          re.IGNORECASE)

    # -- host --------------------------------------------------------------
    def host_blocked(self, host: str) -> bool:
        h = normalize_host(host)
        if h in self.hosts:
            return True
        return bool(self.host_md5s) and self.host_md5(h) in self.host_md5s

    @staticmethod
    def host_md5(host: str) -> str:
        """Ahmia's ban key: md5 hex of the normalised onion host (not a security
        hash -- a fixed interop format)."""
        return hashlib.md5(
            normalize_host(host).encode("utf-8", "replace")).hexdigest()

    def banned_host_md5s(self):
        """Our explicit host blocklist, republished in Ahmia's md5(domain)
        format so others can subscribe to it."""
        return sorted(self.host_md5(h) for h in self.hosts)

    # -- content -----------------------------------------------------------
    def content_hit(self, *texts: str):
        """Return the first matched keyword, or None."""
        hay = "\n".join(t for t in texts if t)
        if not hay:
            return None
        for kw, rx in zip(self._keywords, self._kw_regexes):
            if rx.search(hay):
                return kw
        return None

    def page_blocked(self, host: str, title: str, text: str):
        """Return a reason string if the page must be dropped, else None."""
        if self.host_blocked(host):
            return f"blocked-host:{normalize_host(host)}"
        kw = self.content_hit(title or "", text or "")
        if kw:
            return f"blocked-keyword:{kw}"
        return None

    # -- media -------------------------------------------------------------
    @staticmethod
    def _norm_hash(h: str) -> str:
        return h.strip().lower()

    @property
    def has_media_blocklist(self) -> bool:
        return bool(self.media)

    @staticmethod
    def hash_media(data: bytes) -> str:
        """SHA-256 hex digest of raw media bytes (the media blocklist key)."""
        return hashlib.sha256(data or b"").hexdigest()

    def media_blocked(self, hash_hex: str) -> bool:
        """True iff *hash_hex* (a hex sha256) is on the media blocklist."""
        if not hash_hex or not self.media:
            return False
        return self._norm_hash(hash_hex) in self.media

    def media_bytes_blocked(self, data: bytes):
        """Hash *data* and return the offending hex digest if blocklisted, else
        None. No-op (returns None) when no media blocklist is configured."""
        if not self.media or not data:
            return None
        h = self.hash_media(data)
        return h if h in self.media else None

    @property
    def keywords(self):
        return list(self._keywords)

    @property
    def media_hashes(self):
        return sorted(self.media)


def _read_list_file(path: str) -> list[str]:
    if not path or not os.path.exists(path):
        return []
    out = []
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.split("#", 1)[0].strip()
            if line:
                out.append(line)
    return out


def load_abuse_filter(hosts_path: str | None, keywords_path: str | None,
                      media_path: str | None = None,
                      host_md5_path: str | None = None) -> AbuseFilter:
    return AbuseFilter(
        hosts=_read_list_file(hosts_path) if hosts_path else [],
        keywords=_read_list_file(keywords_path) if keywords_path else [],
        media_hashes=_read_list_file(media_path) if media_path else [],
        host_md5s=_read_list_file(host_md5_path) if host_md5_path else [],
    )
