"""Human-readable crawl statistics."""

from __future__ import annotations

import json


def format_stats(st) -> str:
    s = st.stats()
    lines = []
    lines.append("== onioncrawler stats ==")
    lines.append(f"pages indexed      : {s['pages']}")
    lines.append(f"pages stored (ctr) : {s['pages_stored']}")
    lines.append(f"urls enqueued      : {s['urls_enqueued']}")
    lines.append(f"duplicates skipped : {s['duplicates']}")
    lines.append(f"fetch errors       : {s['errors']}")
    lines.append(f"hosts              : {s['hosts']}  {dict(s['hosts_by_state'])}")
    lines.append("frontier:")
    for status in ("queued", "leased", "done", "error"):
        c = s["frontier_by_status"].get(status, 0)
        lines.append(f"  {status:8s}: {c}")
    if s["trapped_hosts"]:
        lines.append("trapped/blocked hosts:")
        for h in s["trapped_hosts"]:
            lines.append(f"  {h['host'][:24]}… : {h['trapped_reason']}")
    if s["recent_traps"]:
        lines.append("recent trap events:")
        for t in s["recent_traps"][:10]:
            u = (t["url"] or "")[:50]
            lines.append(f"  [{t['reason']}] {u}")
    return "\n".join(lines)


def stats_json(st) -> str:
    return json.dumps(st.stats(), indent=2)
