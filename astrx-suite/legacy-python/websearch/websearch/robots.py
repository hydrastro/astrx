"""A small, self-contained robots.txt parser.

Implements the parts of the Robots Exclusion Protocol that a polite crawler
needs:

  * grouping of rules under ``User-agent`` lines (consecutive UA lines share a
    group),
  * ``Allow`` / ``Disallow`` with ``*`` and ``$`` wildcards,
  * longest-match wins, ``Allow`` breaks ties (Google's rule),
  * ``Crawl-delay``.

``parse`` returns a :class:`Robots` object with ``can_fetch(path)`` and a
``crawl_delay`` attribute.  Unknown/empty robots -> allow everything.
"""

# The linear, backtracking-free path-glob matcher lives in crawlcore so both
# crawlers share ONE ReDoS-safe implementation (`*` = any run, `$` = end-anchor,
# start-anchored). str find/startswith/endswith only -- never a regex, so a
# hostile robots.txt (`/a*a*a*...*$`) cannot cause catastrophic backtracking.
from crawlcore.globmatch import compile_glob as _compile, glob_match as _glob_match


class _Rule:
    __slots__ = ("allow", "pattern", "length", "anchored", "segments")

    def __init__(self, allow, pattern):
        self.allow = allow
        self.pattern = pattern
        self.length = len(pattern)
        self.anchored, self.segments = _compile(pattern)

    def matches(self, path):
        return _glob_match(self.segments, self.anchored, path)


class Robots:
    """Compiled rules for one user-agent, plus crawl-delay."""

    def __init__(self, rules, crawl_delay=None, allow_all=False):
        self.rules = rules            # list[_Rule]
        self.crawl_delay = crawl_delay
        self.allow_all = allow_all

    def can_fetch(self, path):
        if self.allow_all or not self.rules:
            return True
        if not path:
            path = "/"
        best = None  # (length, allow)
        for r in self.rules:
            if r.matches(path):
                if best is None or r.length > best[0] or (
                    r.length == best[0] and r.allow
                ):
                    best = (r.length, r.allow)
        if best is None:
            return True
        return best[1]


def _iter_groups(text):
    """Return a list of ``(agents:set[str], rules:list[(allow,pattern)], delay)``.

    A run of consecutive ``User-agent`` lines starts (or extends) a group; the
    first non-agent directive closes the agent list, and the next agent line
    begins a fresh group.
    """
    groups = []
    agents = None
    rules = None
    delay = None
    last_was_agent = False

    def flush():
        if agents:
            groups.append((agents, rules, delay))

    for raw in text.splitlines():
        line = raw.split("#", 1)[0].strip()
        if not line or ":" not in line:
            continue
        field, _, value = line.partition(":")
        field = field.strip().lower()
        value = value.strip()
        if field == "user-agent":
            if not last_was_agent and agents is not None:
                flush()
                agents, rules, delay = None, None, None
            if agents is None:
                agents, rules, delay = set(), [], None
            if value:
                agents.add(value.lower())
            last_was_agent = True
            continue
        last_was_agent = False
        if agents is None:
            continue  # rule before any User-agent -> ignore
        if field == "disallow":
            rules.append((False, value))
        elif field == "allow":
            rules.append((True, value))
        elif field == "crawl-delay":
            try:
                delay = float(value)
            except ValueError:
                pass
    flush()
    return groups


def parse(text, user_agent="*"):
    """Parse robots.txt *text* for *user_agent* -> :class:`Robots`."""
    if not text:
        return Robots([], None, allow_all=True)
    ua = user_agent.lower()
    groups = _iter_groups(text)

    specific = []   # groups whose token is a substring of our UA
    star = []       # groups matching '*'
    for agents, rules, delay in groups:
        if "*" in agents:
            star.append((rules, delay))
        if any(tok and tok != "*" and tok in ua for tok in agents):
            specific.append((rules, delay))

    chosen = specific if specific else star
    if not chosen:
        return Robots([], None, allow_all=True)

    merged_rules = []
    delay = None
    for rules, d in chosen:
        for allow, pattern in rules:
            # An empty Disallow means "allow all" -> contributes no restriction.
            if pattern == "":
                continue
            merged_rules.append(_Rule(allow, pattern))
        if d is not None and (delay is None or d > delay):
            delay = d
    return Robots(merged_rules, delay, allow_all=not merged_rules)
