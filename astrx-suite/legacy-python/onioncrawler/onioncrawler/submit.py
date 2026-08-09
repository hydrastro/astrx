"""Runtime onion submission: validate + abuse-check a URL, then enqueue it as a
seed. Shared by the `submit` CLI and the search server's `/add` endpoint, so
both intake paths apply exactly the same onion-only + blocklist gate.

Trust model: the operator CLI and the *authenticated* admin `/add` are trusted
seeds (caps=None -> a forced enqueue that bypasses the trap budgets). An
*unauthenticated* public `/add` submission is UNTRUSTED: the caller passes a
*caps* dict so the enqueue honours the frontier backstops (max_unique_urls /
per-host / template / skeleton) and can never grow the frontier past the caps.
"""

from __future__ import annotations

from .canonical import canonicalize

# enqueue reason codes that mean "refused by a trap/budget cap or an inactive
# host" -> surfaced to the submitter as a single 'capped' status.
_CAPPED = frozenset(
    {"unique-budget", "host-budget", "template-cap", "skeleton-cap", "host-dead"}
)


def submit_seed(storage, abuse, url: str, allow_v2: bool = False,
                caps: dict | None = None, allow_i2p: bool = False) -> dict:
    """Try to enqueue *url* as a crawl seed.

    Returns a dict with 'status' one of:
      'ok'        - newly enqueued
      'dup'       - already known (any frontier status)
      'not-onion' - failed darknet-only validation / canonicalization
      'blocked'   - host is on the abuse blocklist
      'capped'    - refused by a frontier trap/budget cap or an inactive host
    plus the canonical 'url' / 'host' when applicable.

    *caps* is None for a trusted (operator/authed) seed and a caps dict for an
    untrusted (public) submission, which then honours the frontier backstops.
    *allow_i2p* additionally admits .i2p hosts (off => .onion only).
    """
    raw = (url or "").strip()
    cu = canonicalize(raw, allow_v2=allow_v2, allow_i2p=allow_i2p)
    if cu is None and "://" not in raw:
        # accept a bare host / host+path submission by defaulting the scheme
        cu = canonicalize("http://" + raw, allow_v2=allow_v2, allow_i2p=allow_i2p)
    if cu is None:
        return {"status": "not-onion", "input": url}
    if abuse is not None and abuse.host_blocked(cu.host):
        return {"status": "blocked", "host": cu.host, "url": cu.url}
    # Trusted seed (caps is None) -> force past the trap budgets; untrusted
    # public submission (caps given) -> non-force enqueue that honours them.
    # Note: no ensure_host() here -- enqueue creates the host row only when it
    # actually admits the URL, so a capped/blocked submission cannot grow the
    # hosts table either.
    res = storage.add_seed(cu, caps=caps, force=(caps is None))
    if res == "ok":
        status = "ok"
    elif res == "dup-url":
        status = "dup"
    elif res in _CAPPED:
        status = "capped"
    else:
        status = res
    return {"status": status, "host": cu.host, "url": cu.url}


def submit_many(storage, abuse, urls, allow_v2: bool = False,
                caps: dict | None = None, max_urls: int | None = None,
                allow_i2p: bool = False) -> dict:
    """Bulk import: submit many URLs, return aggregate counts + per-URL results.

    *caps* is threaded to submit_seed (None = trusted, dict = public). *max_urls*
    caps how many non-comment URLs are accepted in one call (public /add); the
    remainder are reported under 'skipped' and never touched. *allow_i2p* admits
    .i2p hosts (off => .onion only).
    """
    out = {"ok": 0, "dup": 0, "not-onion": 0, "blocked": 0, "capped": 0,
           "skipped": 0, "results": []}
    processed = 0
    for u in urls:
        u = (u or "").strip()
        if not u or u.startswith("#"):
            continue
        if max_urls is not None and processed >= max_urls:
            out["skipped"] += 1
            continue
        processed += 1
        r = submit_seed(storage, abuse, u, allow_v2=allow_v2, caps=caps,
                        allow_i2p=allow_i2p)
        out["results"].append(r)
        out[r["status"]] = out.get(r["status"], 0) + 1
    return out
