"""Structured (JSON-lines) logging helper, stdlib only.

One event per line on the chosen stream, machine-parseable for long crawls and
the search server's admin actions. Privacy note: never pass user search queries
or page contents here — only operational counters and admin events.
"""

from __future__ import annotations

import json
import sys
import time
import threading

_lock = threading.Lock()


def make_logger(stream=None, enabled: bool = True, component: str = "onioncrawler"):
    stream = stream or sys.stderr

    def log(event: str, **fields):
        if not enabled:
            return
        rec = {"ts": round(time.time(), 3), "component": component, "event": event}
        rec.update(fields)
        line = json.dumps(rec, ensure_ascii=False, sort_keys=True)
        with _lock:
            stream.write(line + "\n")
            stream.flush()

    return log


def null_logger(*a, **k):
    return None
