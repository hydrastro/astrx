//! Pure recrawl-scheduling arithmetic (no DB, no clock ownership). Both crawlers
//! refresh on a per-page interval and back it off when a page is seen unchanged.

/// True if a page fetched at `fetched_at` on `interval` is due at `now`. A page
/// never fetched (`fetched_at <= 0`) is not scheduled here.
pub fn is_due(fetched_at: f64, interval: f64, now: f64) -> bool {
    if fetched_at <= 0.0 {
        return false;
    }
    fetched_at + interval <= now
}

/// The timestamp at which the page becomes due for recrawl.
pub fn next_due(fetched_at: f64, interval: f64) -> f64 {
    fetched_at + interval
}

/// Grow a recrawl interval multiplicatively when a page is unchanged: fall back
/// to `base` when there is no current interval, multiply by `factor`, and cap at
/// `max_interval` (0 = uncapped). Returns 0 when there is nothing to grow.
pub fn backoff_interval(current: f64, factor: f64, max_interval: f64, base: f64) -> f64 {
    let cur = if current == 0.0 { base } else { current };
    let mut next = if cur != 0.0 { cur * factor } else { 0.0 };
    if max_interval != 0.0 && next != 0.0 && next > max_interval {
        next = max_interval;
    }
    next
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn is_due_boundary() {
        assert!(is_due(1000.0, 100.0, 1100.0));
        assert!(is_due(1000.0, 100.0, 1200.0));
        assert!(!is_due(1000.0, 100.0, 1099.9));
        assert!(!is_due(0.0, 100.0, 1e9));
    }

    #[test]
    fn next_due_adds() {
        assert_eq!(next_due(1000.0, 250.0), 1250.0);
    }

    #[test]
    fn backoff_grows_caps_and_bases() {
        assert_eq!(backoff_interval(100.0, 2.0, 0.0, 0.0), 200.0);
        assert_eq!(backoff_interval(100.0, 2.0, 150.0, 0.0), 150.0);
        assert_eq!(backoff_interval(0.0, 2.0, 0.0, 50.0), 100.0);
        assert_eq!(backoff_interval(0.0, 2.0, 0.0, 0.0), 0.0);
    }
}
