//! A small, correct-enough robots.txt parser.
//!
//! Honors User-agent groups, Allow/Disallow (with `*` wildcards and a `$` end
//! anchor), Crawl-delay, and global Sitemap directives. Matching follows the
//! de-facto rule: the most specific (longest) matching rule wins; on equal
//! length, Allow wins. Path matching uses the shared, ReDoS-safe
//! [`crawlcore::globmatch`] (no regex → a hostile `/a*a*a*…*$` can't hang the
//! crawl).
//!
//! Ported from the Python `robots.py`; cross-checked byte-identical in
//! `tests/xcheck_robots.rs`.

use crawlcore::globmatch::{compile_glob, glob_match};
use crawlcore::urlparse::unquote;
use std::collections::HashMap;

/// One Allow/Disallow rule, pre-compiled to backtracking-free glob segments.
#[derive(Debug, Clone)]
struct Rule {
    allow: bool,
    length: usize, // char length of the ORIGINAL pattern (specificity key)
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

/// Parsed robots.txt rules for a host.
#[derive(Debug, Clone, Default)]
pub struct RobotsRules {
    /// User-agent groups in insertion order (the tie-break for equal-length
    /// agent-token matches favours the earliest-declared group).
    groups: Vec<(String, Vec<Rule>)>,
    delays: HashMap<String, f64>,
    /// Whether a robots.txt was actually present (vs. a 404 → allow-all).
    pub present: bool,
    /// Global `Sitemap:` URLs (independent of any User-agent group).
    pub sitemaps: Vec<String>,
}

impl RobotsRules {
    /// Rules for a missing / 404 robots.txt — allow everything.
    #[must_use]
    pub fn empty() -> Self {
        RobotsRules {
            groups: Vec::new(),
            delays: HashMap::new(),
            present: false,
            sitemaps: Vec::new(),
        }
    }

    /// Index of the most specific User-agent group applying to *agent*: the
    /// longest declared agent token that is a substring of *agent* (ties → the
    /// earliest declared), else the `*` group if present, else `None`.
    fn select_group(&self, agent: &str) -> Option<usize> {
        let agent = agent.to_lowercase();
        let mut best: Option<usize> = None;
        let mut best_len: i64 = -1;
        for (i, (ua, _)) in self.groups.iter().enumerate() {
            if ua == "*" {
                continue;
            }
            let ualen = ua.chars().count() as i64;
            if agent.contains(ua.as_str()) && ualen > best_len {
                best = Some(i);
                best_len = ualen;
            }
        }
        best.or_else(|| self.groups.iter().position(|(ua, _)| ua == "*"))
    }

    /// Whether *agent* may fetch *path* (default agent: `onioncrawler`).
    #[must_use]
    pub fn allowed(&self, path: &str, agent: &str) -> bool {
        let path = if path.starts_with('/') {
            path.to_string()
        } else {
            format!("/{path}")
        };
        let path = unquote(&path);
        let Some(gi) = self.select_group(agent) else {
            return true; // no applicable group => allowed
        };
        let mut best: Option<&Rule> = None;
        for r in &self.groups[gi].1 {
            if r.matches(&path) {
                let take = match best {
                    None => true,
                    Some(m) => r.length > m.length || (r.length == m.length && r.allow && !m.allow),
                };
                if take {
                    best = Some(r);
                }
            }
        }
        best.map_or(true, |m| m.allow)
    }

    /// The Crawl-delay applying to *agent*, if any.
    #[must_use]
    pub fn crawl_delay(&self, agent: &str) -> Option<f64> {
        if let Some(gi) = self.select_group(agent) {
            if let Some(d) = self.delays.get(&self.groups[gi].0) {
                return Some(*d);
            }
        }
        self.delays.get("*").copied()
    }
}

/// Find-or-create a group by user-agent key, returning its index (preserves
/// insertion order — the Python `dict.setdefault`).
fn group_slot(groups: &mut Vec<(String, Vec<Rule>)>, ua: &str) -> usize {
    if let Some(i) = groups.iter().position(|(k, _)| k == ua) {
        i
    } else {
        groups.push((ua.to_string(), Vec::new()));
        groups.len() - 1
    }
}

/// Parse a robots.txt document.
#[must_use]
pub fn parse_robots(text: &str) -> RobotsRules {
    let mut groups: Vec<(String, Vec<Rule>)> = Vec::new();
    let mut delays: HashMap<String, f64> = HashMap::new();
    let mut sitemaps: Vec<String> = Vec::new();
    let mut current_agents: Vec<String> = Vec::new();
    // A blank User-agent line starts a fresh group; consecutive UA lines share.
    let mut last_was_directive = false;

    for raw in text.lines() {
        let line = raw.split('#').next().unwrap_or("").trim();
        if line.is_empty() || !line.contains(':') {
            continue;
        }
        let (field_raw, value_raw) = line.split_once(':').unwrap();
        let field = field_raw.trim().to_lowercase();
        let value = value_raw.trim();

        match field.as_str() {
            "user-agent" => {
                if last_was_directive {
                    current_agents = Vec::new();
                }
                let ua = value.to_lowercase();
                group_slot(&mut groups, &ua);
                current_agents.push(ua);
                last_was_directive = false;
            }
            "allow" | "disallow" => {
                last_was_directive = true;
                if current_agents.is_empty() {
                    current_agents.push("*".to_string());
                    group_slot(&mut groups, "*");
                }
                // An empty Disallow means "allow all" -> no rule needed.
                if field == "disallow" && value.is_empty() {
                    continue;
                }
                for ua in &current_agents {
                    let idx = group_slot(&mut groups, ua);
                    groups[idx].1.push(Rule::new(field == "allow", value));
                }
            }
            "crawl-delay" => {
                last_was_directive = true;
                let Ok(d) = value.parse::<f64>() else {
                    continue;
                };
                if current_agents.is_empty() {
                    delays.insert("*".to_string(), d);
                } else {
                    for ua in &current_agents {
                        delays.insert(ua.clone(), d);
                    }
                }
            }
            "sitemap" => {
                last_was_directive = true;
                if !value.is_empty() {
                    sitemaps.push(value.to_string());
                }
            }
            _ => {
                // unknown extension — ignore, don't reset the group
                last_was_directive = true;
            }
        }
    }

    RobotsRules {
        groups,
        delays,
        present: true,
        sitemaps,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn most_specific_and_allow_wins() {
        let r = parse_robots("User-agent: *\nDisallow: /private/\nAllow: /private/ok\n");
        assert!(!r.allowed("/private/secret", "anybot"));
        assert!(r.allowed("/private/ok", "anybot")); // longer Allow wins
        assert!(r.allowed("/public", "anybot"));
        assert!(r.present);
    }

    #[test]
    fn missing_is_allow_all() {
        let r = RobotsRules::empty();
        assert!(r.allowed("/anything", "onioncrawler"));
        assert!(!r.present);
    }

    #[test]
    fn crawl_delay_and_sitemaps() {
        let r =
            parse_robots("User-agent: *\nCrawl-delay: 2.5\nSitemap: http://x.onion/sitemap.xml\n");
        assert_eq!(r.crawl_delay("bot"), Some(2.5));
        assert_eq!(r.sitemaps, vec!["http://x.onion/sitemap.xml".to_string()]);
    }
}
