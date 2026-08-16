//! A small, self-contained robots.txt parser — a dependency-free port of the
//! Python `websearch.robots`.
//!
//! Implements the parts a polite crawler needs: grouping under `User-agent`
//! lines (consecutive UA lines share a group), `Allow` / `Disallow` with `*` and
//! `$` wildcards, longest-match-wins with `Allow` breaking ties (Google's rule),
//! and `Crawl-delay`. Unknown / empty robots → allow everything. The glob match
//! is the shared ReDoS-safe [`crawlcore::globmatch`], so a hostile
//! `/a*a*a*…*$` can't cause catastrophic backtracking.

use crawlcore::globmatch::{compile_glob, glob_match};

/// The largest `Crawl-delay` this parser will honour, in seconds (24 h). A site
/// asking for more is asking never to be crawled again, which it can say
/// properly with `Disallow: /`; accepting the number instead lets one host's
/// robots.txt park a crawler slot indefinitely.
const MAX_CRAWL_DELAY: f64 = 86_400.0;

struct Rule {
    allow: bool,
    length: usize,
    anchored: bool,
    segments: Vec<String>,
}

impl Rule {
    fn new(allow: bool, pattern: &str) -> Self {
        let (anchored, segments) = compile_glob(pattern);
        Rule {
            allow,
            length: pattern.chars().count(),
            anchored,
            segments,
        }
    }

    fn matches(&self, path: &str) -> bool {
        glob_match(&self.segments, self.anchored, path)
    }
}

/// Compiled robots rules for one user-agent, plus the crawl-delay.
pub struct Robots {
    rules: Vec<Rule>,
    crawl_delay: Option<f64>,
    allow_all: bool,
}

impl Robots {
    /// True if `path` may be fetched. Longest matching rule wins; on a tie an
    /// `Allow` beats a `Disallow`. No matching rule → allowed.
    #[must_use]
    pub fn can_fetch(&self, path: &str) -> bool {
        if self.allow_all || self.rules.is_empty() {
            return true;
        }
        let path = if path.is_empty() { "/" } else { path };
        let mut best: Option<(usize, bool)> = None;
        for r in &self.rules {
            if r.matches(path) {
                let replace = match best {
                    None => true,
                    Some((blen, _)) => r.length > blen || (r.length == blen && r.allow),
                };
                if replace {
                    best = Some((r.length, r.allow));
                }
            }
        }
        best.map_or(true, |(_, allow)| allow)
    }

    /// The `Crawl-delay` for the chosen agent group, if any.
    #[must_use]
    pub fn crawl_delay(&self) -> Option<f64> {
        self.crawl_delay
    }
}

type Group = (Vec<String>, Vec<(bool, String)>, Option<f64>);
/// A chosen agent group's rules + crawl-delay, borrowed during `parse`.
type ChosenGroup<'a> = (&'a Vec<(bool, String)>, Option<f64>);

/// Group directives under their `User-agent` lines (a run of consecutive UA
/// lines starts / extends a group; the first non-agent directive closes the
/// agent list, and the next UA line begins a fresh group).
fn iter_groups(text: &str) -> Vec<Group> {
    let mut groups: Vec<Group> = Vec::new();
    let mut agents: Option<Vec<String>> = None;
    let mut rules: Vec<(bool, String)> = Vec::new();
    let mut delay: Option<f64> = None;
    let mut last_was_agent = false;

    // A UTF-8 BOM is common on robots.txt files saved by Windows editors, and
    // it is NOT `char::is_whitespace`, so leaving it attached makes the first
    // field `"\u{feff}user-agent"` — no group is ever opened, every rule is
    // dropped by the `agents.is_none()` guard, and the whole file silently
    // becomes allow-all. Strip it before parsing, as every real parser does.
    let text = text.strip_prefix('\u{feff}').unwrap_or(text);

    for raw in text.lines() {
        let line = raw.split('#').next().unwrap_or("").trim();
        if line.is_empty() || !line.contains(':') {
            continue;
        }
        let (field, value) = line.split_once(':').unwrap();
        let field = field.trim().to_lowercase();
        let value = value.trim();

        if field == "user-agent" {
            if !last_was_agent && agents.is_some() {
                let a = agents.take().unwrap();
                if !a.is_empty() {
                    groups.push((a, std::mem::take(&mut rules), delay.take()));
                }
                rules = Vec::new();
                delay = None;
            }
            let a = agents.get_or_insert_with(Vec::new);
            if !value.is_empty() {
                let v = value.to_lowercase();
                if !a.contains(&v) {
                    a.push(v);
                }
            }
            last_was_agent = true;
            continue;
        }
        last_was_agent = false;
        if agents.is_none() {
            continue; // rule before any User-agent → ignore
        }
        match field.as_str() {
            "disallow" => rules.push((false, value.to_string())),
            "allow" => rules.push((true, value.to_string())),
            "crawl-delay" => {
                // Rust's f64 parser accepts "inf"/"nan"; a site serving either
                // used to reserve its host until `now + inf`, so the host was
                // never leasable again while still counting as queued — the
                // crawl loop then span forever without fetching a page. Only a
                // finite, non-negative, sanely-bounded delay is a delay.
                if let Ok(d) = value.parse::<f64>() {
                    if d.is_finite() && (0.0..=MAX_CRAWL_DELAY).contains(&d) {
                        delay = Some(d);
                    }
                }
            }
            _ => {}
        }
    }
    if let Some(a) = agents.take() {
        if !a.is_empty() {
            groups.push((a, rules, delay));
        }
    }
    groups
}

/// Parse robots.txt `text` for `user_agent`.
#[must_use]
pub fn parse(text: &str, user_agent: &str) -> Robots {
    if text.is_empty() {
        return Robots {
            rules: Vec::new(),
            crawl_delay: None,
            allow_all: true,
        };
    }
    let ua = user_agent.to_lowercase();
    let groups = iter_groups(text);

    // groups whose token is a substring of our UA (specific) beat the '*' group
    let mut specific: Vec<ChosenGroup> = Vec::new();
    let mut star: Vec<ChosenGroup> = Vec::new();
    for (agents, rules, delay) in &groups {
        if agents.iter().any(|a| a == "*") {
            star.push((rules, *delay));
        }
        if agents
            .iter()
            .any(|tok| !tok.is_empty() && tok != "*" && ua.contains(tok.as_str()))
        {
            specific.push((rules, *delay));
        }
    }

    let chosen = if specific.is_empty() { star } else { specific };
    if chosen.is_empty() {
        return Robots {
            rules: Vec::new(),
            crawl_delay: None,
            allow_all: true,
        };
    }

    let mut merged = Vec::new();
    let mut delay: Option<f64> = None;
    for (rules, d) in chosen {
        for (allow, pattern) in rules {
            if pattern.is_empty() {
                continue; // an empty Disallow means "allow all" — no restriction
            }
            merged.push(Rule::new(*allow, pattern));
        }
        if let Some(dv) = d {
            if delay.map_or(true, |cur| dv > cur) {
                delay = Some(dv);
            }
        }
    }
    let allow_all = merged.is_empty();
    Robots {
        rules: merged,
        crawl_delay: delay,
        allow_all,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn longest_match_and_allow_tiebreak() {
        let r = parse(
            "User-agent: *\nDisallow: /private\nAllow: /private/ok\nCrawl-delay: 2.5\n",
            "mybot",
        );
        assert!(!r.can_fetch("/private/x"));
        assert!(r.can_fetch("/private/ok/y")); // longer Allow wins
        assert!(r.can_fetch("/public"));
        assert_eq!(r.crawl_delay(), Some(2.5));
    }

    #[test]
    fn specific_group_beats_star() {
        let r = parse(
            "User-agent: mybot\nDisallow: /\nUser-agent: *\nDisallow: /x\n",
            "mybot",
        );
        assert!(!r.can_fetch("/anything"));
    }

    #[test]
    fn empty_and_dollar_anchor() {
        assert!(parse("", "any").can_fetch("/"));
        assert!(parse("User-agent: *\nDisallow:\n", "any").can_fetch("/anything"));
        let r = parse("User-agent: *\nDisallow: /*.pdf$\n", "any");
        assert!(!r.can_fetch("/a.pdf"));
        assert!(r.can_fetch("/a.pdf?x")); // $ anchors the end
        assert!(r.can_fetch("/a.html"));
    }
}

#[cfg(test)]
mod audit_regression {
    use super::*;

    /// A UTF-8 BOM is not `char::is_whitespace`, so it used to make the first
    /// field `"\u{feff}user-agent"`: no group was ever opened, every rule was
    /// dropped, and the whole file silently became allow-all.
    #[test]
    fn a_utf8_bom_does_not_make_the_file_allow_all() {
        let r = parse(
            "\u{feff}User-agent: *\nDisallow: /private\n",
            "astrx-websearch/1.0",
        );
        assert!(!r.can_fetch("/private"), "BOM made the file allow-all");
        assert!(r.can_fetch("/public"));
    }

    /// Rust's f64 parser accepts "inf"/"nan"; a site serving either reserved its
    /// host until `now + inf`, so it was never leasable again while still
    /// counting as queued and the crawl loop span forever without fetching.
    #[test]
    fn a_nonsense_crawl_delay_is_ignored() {
        for bad in ["inf", "-inf", "NaN", "1e400", "-5", "999999999"] {
            let text = format!("User-agent: *\nCrawl-delay: {bad}\nDisallow:\n");
            let d = parse(&text, "astrx-websearch/1.0").crawl_delay();
            assert!(
                d.is_none_or(|v| v.is_finite() && (0.0..=86_400.0).contains(&v)),
                "Crawl-delay: {bad} yielded {d:?}"
            );
        }
        // A sane delay still comes through.
        let r = parse(
            "User-agent: *\nCrawl-delay: 2.5\nDisallow:\n",
            "astrx-websearch/1.0",
        );
        assert_eq!(r.crawl_delay(), Some(2.5));
    }
}
