"""A small, correct-enough robots.txt parser.

Honors User-agent groups, Allow/Disallow (with '*' wildcards and '$' end
anchor), and Crawl-delay. Matching follows the de-facto rule: the most
specific (longest) matching rule wins; on equal length, Allow wins.
"""

from __future__ import annotations

from urllib.parse import unquote

from crawlcore.globmatch import compile_glob, glob_match


class _Rule:
    __slots__ = ("allow", "pattern", "length", "anchored", "segments")

    def __init__(self, allow: bool, pattern: str):
        self.allow = allow
        self.pattern = pattern
        self.length = len(pattern)
        # LINEAR matcher (no regex). A robots pattern translated to a
        # backtracking regex (`*` -> `.*`) can ReDoS on a hostile robots.txt
        # (e.g. `/a*a*a*...*$` vs a long path); the shared glob matcher scans
        # with str find/startswith/endswith only, so it can never backtrack.
        self.anchored, self.segments = compile_glob(pattern)

    def matches(self, path: str) -> bool:
        return glob_match(self.segments, self.anchored, path)


class RobotsRules:
    def __init__(self, groups: dict[str, list[_Rule]], delays: dict[str, float],
                 present: bool = True, sitemaps: list[str] | None = None):
        self._groups = groups
        self._delays = delays
        self.present = present
        # Sitemap: directives are global (not tied to a User-agent group).
        self.sitemaps = sitemaps or []

    def _select_group(self, agent: str):
        agent = agent.lower()
        # exact-ish match: choose the most specific matching user-agent token
        best = None
        best_len = -1
        for ua in self._groups:
            if ua == "*":
                continue
            if ua in agent and len(ua) > best_len:
                best = ua
                best_len = len(ua)
        if best is not None:
            return best
        return "*" if "*" in self._groups else None

    def allowed(self, path: str, agent: str = "onioncrawler") -> bool:
        if not path.startswith("/"):
            path = "/" + path
        path = unquote(path)
        grp = self._select_group(agent)
        if grp is None:
            return True  # no applicable group => allowed
        rules = self._groups.get(grp, [])
        match = None
        for r in rules:
            if r.matches(path):
                if match is None or r.length > match.length or (
                    r.length == match.length and r.allow and not match.allow
                ):
                    match = r
        if match is None:
            return True
        return match.allow

    def crawl_delay(self, agent: str = "onioncrawler"):
        grp = self._select_group(agent)
        if grp is not None and grp in self._delays:
            return self._delays[grp]
        if "*" in self._delays:
            return self._delays["*"]
        return None


def parse_robots(text: str) -> RobotsRules:
    groups: dict[str, list[_Rule]] = {}
    delays: dict[str, float] = {}
    sitemaps: list[str] = []
    current_agents: list[str] = []
    # A blank User-agent line starts a fresh group; consecutive UA lines share
    last_was_directive = False

    for raw in text.splitlines():
        line = raw.split("#", 1)[0].strip()
        if not line or ":" not in line:
            continue
        field, _, value = line.partition(":")
        field = field.strip().lower()
        value = value.strip()

        if field == "user-agent":
            if last_was_directive:
                current_agents = []
            ua = value.lower()
            current_agents.append(ua)
            groups.setdefault(ua, [])
            last_was_directive = False
        elif field in ("allow", "disallow"):
            last_was_directive = True
            if not current_agents:
                current_agents = ["*"]
                groups.setdefault("*", [])
            # An empty Disallow means "allow all" -> no rule needed.
            if field == "disallow" and value == "":
                continue
            for ua in current_agents:
                groups.setdefault(ua, []).append(_Rule(field == "allow", value))
        elif field == "crawl-delay":
            last_was_directive = True
            try:
                d = float(value)
            except ValueError:
                continue
            for ua in (current_agents or ["*"]):
                delays[ua] = d
        elif field == "sitemap":
            # Global directive (independent of any User-agent group).
            last_was_directive = True
            if value:
                sitemaps.append(value)
        else:
            # unknown extension - ignore, don't reset group
            last_was_directive = True

    return RobotsRules(groups, delays, present=True, sitemaps=sitemaps)


def empty_rules() -> RobotsRules:
    """Used when robots.txt is missing / 404 => allow everything."""
    return RobotsRules({}, {}, present=False)
