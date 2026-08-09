"""crawlcore - shared, zero-dependency (Python 3.11 stdlib) crawler mechanics.

This package holds the pieces two hardened crawlers (``onioncrawler`` and
``websearch``) genuinely share, extracted so there is ONE tested implementation
instead of two drifting copies:

  * :mod:`crawlcore.dedup`      - 64-bit SimHash accumulator + Hamming / signed
                                  wrap / near-duplicate helpers (the fuzzy-dup
                                  bit-math both crawlers use; each crawler keeps
                                  its OWN token hash + tokenizer and feeds this).
  * :mod:`crawlcore.traps`      - pure, stateless structural bot-trap predicates
                                  (path depth / repeats / cycles / calendar bomb)
                                  operating on already-parsed path + query.
  * :mod:`crawlcore.scheduler`  - pure recrawl arithmetic (due test + interval
                                  back-off), independent of any DB.
  * :mod:`crawlcore.globmatch`  - linear, backtracking-free robots.txt path-glob
                                  matcher (``*`` / ``$``) shared by both robots
                                  parsers so neither can ReDoS on a hostile
                                  robots.txt.
  * :mod:`crawlcore.interfaces` - the injected seams (:class:`HostPolicy`,
                                  :class:`Fetcher`, :class:`Extractor`,
                                  :class:`RobotsRules`, :class:`Store`,
                                  :class:`Scheduler`) that crawlcore CONSUMES but
                                  each crawler OWNS and implements.

Design rule enforced here: crawlcore never contains a security boundary. The
onion-only validator + anti-leak gate and the SSRF internal-IP denylist stay in
their respective crawlers behind the :class:`~crawlcore.interfaces.HostPolicy`
and :class:`~crawlcore.interfaces.Fetcher` seams, so sharing mechanics never
shares (or risks) a security gate.

stdlib only, no I/O, importable on its own.
"""

from __future__ import annotations

__version__ = "1.0.0"

__all__ = ["dedup", "traps", "scheduler", "globmatch", "interfaces"]
