//! Runtime onion submission: validate + abuse-check a URL, then enqueue it as a
//! crawl seed — the shared intake path for the `/add` endpoint (and any submit
//! CLI), ported from the Python `onioncrawler.submit`.
//!
//! Trust model (unchanged from Python): a trusted seed (operator / authenticated
//! admin) passes `caps = None`, which forces the enqueue past the frontier trap
//! budgets. An untrusted (public) submission passes `Some(caps)`, so the enqueue
//! honours the backstops (`max_unique_urls` / per-host / template / skeleton) and
//! can never grow the frontier past them. `.onion` only unless `allow_i2p`.

use crate::abuse::AbuseFilter;
use crate::canonical::canonicalize;
use crate::store::{Caps, Enqueued, Store};

/// The outcome of a single [`submit_seed`].
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum SubmitStatus {
    /// Newly enqueued.
    Ok,
    /// Already known (any frontier status).
    Dup,
    /// Failed darknet-only validation / canonicalization.
    NotOnion,
    /// Host is on the abuse blocklist.
    Blocked,
    /// Refused by a frontier trap/budget cap or an inactive host.
    Capped,
}

impl SubmitStatus {
    /// The status string, identical to the Python reference.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            SubmitStatus::Ok => "ok",
            SubmitStatus::Dup => "dup",
            SubmitStatus::NotOnion => "not-onion",
            SubmitStatus::Blocked => "blocked",
            SubmitStatus::Capped => "capped",
        }
    }
}

/// The result of submitting one URL.
#[derive(Clone, Debug, PartialEq)]
pub struct SubmitResult {
    pub status: SubmitStatus,
    pub host: Option<String>,
    pub url: Option<String>,
}

/// Try to enqueue `url` as a crawl seed. `caps = None` is a trusted seed (forced
/// enqueue); `Some(caps)` is an untrusted public submission that honours the
/// frontier backstops. `now` timestamps the seed.
pub fn submit_seed(
    store: &mut Store,
    abuse: Option<&AbuseFilter>,
    url: &str,
    allow_v2: bool,
    caps: Option<Caps>,
    allow_i2p: bool,
    now: f64,
) -> SubmitResult {
    let raw = url.trim();
    // Accept a bare host / host+path by defaulting the scheme, exactly as Python.
    let cu = canonicalize(raw, None, allow_v2, allow_i2p).or_else(|| {
        if !raw.contains("://") {
            canonicalize(&format!("http://{raw}"), None, allow_v2, allow_i2p)
        } else {
            None
        }
    });
    let Some(cu) = cu else {
        return SubmitResult {
            status: SubmitStatus::NotOnion,
            host: None,
            url: None,
        };
    };
    if abuse.is_some_and(|a| a.host_blocked(&cu.host)) {
        return SubmitResult {
            status: SubmitStatus::Blocked,
            host: Some(cu.host.clone()),
            url: Some(cu.url.clone()),
        };
    }
    // Trusted seed (caps None) → force; untrusted (caps Some) → honour the caps.
    let force = caps.is_none();
    let res = store.add_seed(&cu, 0, 0, caps.unwrap_or_default(), now, force);
    let status = match res {
        Enqueued::Ok => SubmitStatus::Ok,
        Enqueued::DupUrl => SubmitStatus::Dup,
        Enqueued::UniqueBudget
        | Enqueued::HostBudget
        | Enqueued::TemplateCap
        | Enqueued::SkeletonCap
        | Enqueued::HostDead => SubmitStatus::Capped,
    };
    SubmitResult {
        status,
        host: Some(cu.host),
        url: Some(cu.url),
    }
}

/// Aggregate counts + per-URL results from [`submit_many`].
#[derive(Clone, Debug, Default, PartialEq)]
pub struct SubmitSummary {
    pub ok: usize,
    pub dup: usize,
    pub not_onion: usize,
    pub blocked: usize,
    pub capped: usize,
    pub skipped: usize,
    pub results: Vec<SubmitResult>,
}

/// Bulk import: submit many URLs, returning aggregate counts + per-URL results.
/// Blank lines and `#` comments are ignored; `max_urls` caps how many non-comment
/// URLs are accepted (the rest are counted as `skipped`), mirroring Python.
#[allow(clippy::too_many_arguments)]
pub fn submit_many(
    store: &mut Store,
    abuse: Option<&AbuseFilter>,
    urls: impl IntoIterator<Item = String>,
    allow_v2: bool,
    caps: Option<Caps>,
    max_urls: Option<usize>,
    allow_i2p: bool,
    now: f64,
) -> SubmitSummary {
    let mut out = SubmitSummary::default();
    let mut processed = 0usize;
    for u in urls {
        let u = u.trim();
        if u.is_empty() || u.starts_with('#') {
            continue;
        }
        if max_urls.is_some_and(|m| processed >= m) {
            out.skipped += 1;
            continue;
        }
        processed += 1;
        let r = submit_seed(store, abuse, u, allow_v2, caps, allow_i2p, now);
        match r.status {
            SubmitStatus::Ok => out.ok += 1,
            SubmitStatus::Dup => out.dup += 1,
            SubmitStatus::NotOnion => out.not_onion += 1,
            SubmitStatus::Blocked => out.blocked += 1,
            SubmitStatus::Capped => out.capped += 1,
        }
        out.results.push(r);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_clearnet() {
        let mut s = Store::new();
        let r = submit_seed(&mut s, None, "http://example.com/", false, None, false, 1.0);
        assert_eq!(r.status, SubmitStatus::NotOnion);
        // a bare clearnet host is also refused
        let r = submit_seed(&mut s, None, "example.com", false, None, false, 1.0);
        assert_eq!(r.status, SubmitStatus::NotOnion);
    }

    #[test]
    fn accepts_onion_and_dedupes() {
        let mut s = Store::new();
        // a valid 56-char v3 onion host
        let host = "a".repeat(56) + ".onion";
        let url = format!("http://{host}/");
        let r = submit_seed(&mut s, None, &url, false, None, false, 1.0);
        assert_eq!(r.status, SubmitStatus::Ok);
        assert_eq!(r.host.as_deref(), Some(host.as_str()));
        // same URL again → dup
        let r = submit_seed(&mut s, None, &url, false, None, false, 1.0);
        assert_eq!(r.status, SubmitStatus::Dup);
    }

    #[test]
    fn bare_host_gets_scheme_defaulted() {
        let mut s = Store::new();
        let host = "b".repeat(56) + ".onion";
        let r = submit_seed(&mut s, None, &host, false, None, false, 1.0);
        assert_eq!(r.status, SubmitStatus::Ok);
    }

    #[test]
    fn untrusted_caps_are_enforced() {
        let mut s = Store::new();
        let caps = Caps {
            max_unique_urls: Some(1),
            ..Caps::default()
        };
        let h1 = "c".repeat(56) + ".onion";
        let h2 = "d".repeat(56) + ".onion";
        assert_eq!(
            submit_seed(
                &mut s,
                None,
                &format!("http://{h1}/"),
                false,
                Some(caps),
                false,
                1.0
            )
            .status,
            SubmitStatus::Ok
        );
        // budget of 1 reached → capped
        assert_eq!(
            submit_seed(
                &mut s,
                None,
                &format!("http://{h2}/"),
                false,
                Some(caps),
                false,
                1.0
            )
            .status,
            SubmitStatus::Capped
        );
    }

    #[test]
    fn many_counts_and_skips() {
        let mut s = Store::new();
        let h1 = format!("http://{}.onion/", "e".repeat(56));
        let h2 = format!("http://{}.onion/", "f".repeat(56));
        let urls = vec![
            "# a comment".to_string(),
            h1.clone(),
            h2.clone(),
            "not a url".to_string(),
        ];
        let out = submit_many(&mut s, None, urls, false, None, Some(1), false, 1.0);
        assert_eq!(out.ok, 1); // only the first non-comment URL processed
        assert_eq!(out.skipped, 2); // h2 + "not a url" skipped by max_urls
    }
}
