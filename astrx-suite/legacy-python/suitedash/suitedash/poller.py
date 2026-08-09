"""Poll every configured service concurrently, bounded so the page never hangs.

Each service is probed on its own worker thread.  Results are gathered against
a single wall-clock deadline of roughly ``timeout`` (probes run in parallel, so
the whole sweep costs about one timeout, not the sum).  A service that blows the
deadline — a black hole that accepts the connection then never answers — is
reported DOWN and its straggler thread is abandoned (its socket timeout reaps
it shortly after); we never wait on it, which is what keeps the dashboard
responsive.
"""

from __future__ import annotations

import time
from collections import OrderedDict
from concurrent.futures import Future, ThreadPoolExecutor
from concurrent.futures import TimeoutError as FutureTimeout
from typing import Dict, Optional, Sequence

from .config import ServiceConfig
from .probe import ServiceResult, probe_service

#: Slack added to the gather deadline over the per-service timeout, to allow a
#: probe that legitimately finishes right at the timeout to be counted.
POLL_SLACK = 0.5


def poll_all(
    services: Sequence[ServiceConfig],
    timeout: float,
    executor: Optional[ThreadPoolExecutor] = None,
) -> "OrderedDict[str, ServiceResult]":
    """Probe all ``services`` concurrently and return results in input order.

    If ``executor`` is given (the long-lived server pool) it is reused and left
    running; otherwise a transient pool is created and torn down without waiting
    on stragglers.  Total wall time is bounded by ``timeout + POLL_SLACK``
    regardless of how many services hang.
    """
    own = executor is None
    ex = executor or ThreadPoolExecutor(
        max_workers=max(4, len(services) * 2), thread_name_prefix="suitedash-probe"
    )
    try:
        deadline = time.monotonic() + timeout + POLL_SLACK
        futures: Dict[str, Future] = {
            s.name: ex.submit(probe_service, s, timeout) for s in services
        }
        results: "OrderedDict[str, ServiceResult]" = OrderedDict()
        for s in services:
            fut = futures[s.name]
            remaining = max(0.0, deadline - time.monotonic())
            try:
                results[s.name] = fut.result(timeout=remaining)
            except FutureTimeout:
                fut.cancel()
                results[s.name] = ServiceResult.down(s, error="timeout")
            except Exception as exc:  # pragma: no cover - probe_service never raises
                results[s.name] = ServiceResult.down(s, error=str(exc) or "error")
        return results
    finally:
        if own:
            # Do not block on stragglers; their socket timeout reaps them.
            ex.shutdown(wait=False, cancel_futures=True)


def summarize(results: "OrderedDict[str, ServiceResult]") -> dict:
    """Overall roll-up: total / up / down counts and an ``all_up`` flag."""
    total = len(results)
    up = sum(1 for r in results.values() if r.up)
    return {"total": total, "up": up, "down": total - up, "all_up": up == total}
