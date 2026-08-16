//! A size budget that cannot be overflowed — the type form of "cap this read".
//!
//! # Why this exists
//!
//! Every engine in the suite had the same bug, five times over, in five
//! separately-written chunked-body readers:
//!
//! ```text
//! if out.len() + size > cap { … }     // `size` is the peer's hex chunk header
//! ```
//!
//! `size` is attacker-controlled and can be `usize::MAX`, so the **sum wraps**
//! to something small, passes the check, and the read is then handed an
//! unbounded length. In release that buffers until the socket idles — one
//! measured case turned 3 MB of wire into 529 MB of RSS in 0.6 s, another was
//! OOM-killed outright; in debug it panics the task.
//!
//! Each site was fixed by hand. That leaves the *shape* available to the next
//! reader anyone writes, so the shape is what this module removes: a [`Budget`]
//! never exposes the arithmetic. You ask it how much you may take, and it
//! answers with a number that is always within what remains.
//!
//! ```
//! # use crawlcore::budget::Budget;
//! let mut b = Budget::new(1024);
//! // A peer declares an absurd chunk length…
//! let grant = b.take(usize::MAX);
//! assert_eq!(grant, 1024);          // …and gets exactly what is left, never more.
//! assert!(b.is_exhausted());
//! assert!(b.overrun());             // and we know the peer asked for too much
//! ```
//!
//! The rule of thumb: if a number reaching an allocation or a read length came
//! off the wire, it should pass through a `Budget` rather than through `+` and
//! `>`.

/// A remaining-bytes budget. Construct with the cap, then [`take`](Budget::take)
/// against it; the arithmetic is saturating and unobservable.
///
/// `Budget` is deliberately **not** `Copy`: a budget that can be silently
/// duplicated is a budget that can be silently spent twice.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Budget {
    remaining: usize,
    limit: usize,
    overrun: bool,
}

impl Budget {
    /// A budget of `limit` bytes.
    #[must_use]
    pub fn new(limit: usize) -> Self {
        Budget {
            remaining: limit,
            limit,
            overrun: false,
        }
    }

    /// A budget that is already spent — useful as a "refuse everything" value.
    #[must_use]
    pub fn exhausted() -> Self {
        Budget::new(0)
    }

    /// Ask for `want` bytes; get what is actually available.
    ///
    /// Never panics, never wraps, never returns more than [`remaining`]. When
    /// `want` exceeds what is left, the shortfall is recorded so the caller can
    /// distinguish "the body ended" from "the peer wanted more than we allow"
    /// (see [`overrun`](Budget::overrun)) — which is exactly the distinction a
    /// `truncated` flag on an HTTP body needs.
    ///
    /// [`remaining`]: Budget::remaining
    pub fn take(&mut self, want: usize) -> usize {
        let grant = want.min(self.remaining);
        if want > grant {
            self.overrun = true;
        }
        self.remaining -= grant;
        grant
    }

    /// Whether `want` would fit without consuming anything.
    #[must_use]
    pub fn fits(&self, want: usize) -> bool {
        want <= self.remaining
    }

    /// Bytes still available.
    #[must_use]
    pub fn remaining(&self) -> usize {
        self.remaining
    }

    /// The budget this was constructed with.
    #[must_use]
    pub fn limit(&self) -> usize {
        self.limit
    }

    /// Bytes granted so far.
    #[must_use]
    pub fn spent(&self) -> usize {
        self.limit - self.remaining
    }

    /// Nothing left.
    #[must_use]
    pub fn is_exhausted(&self) -> bool {
        self.remaining == 0
    }

    /// True once any [`take`](Budget::take) asked for more than was available —
    /// i.e. the input was larger than the cap, which is what an HTTP body's
    /// `truncated` flag means.
    #[must_use]
    pub fn overrun(&self) -> bool {
        self.overrun
    }

    /// Give `n` bytes back (a read that returned short, a chunk abandoned mid
    /// framing). Saturates at the original limit, so a buggy caller cannot
    /// inflate a budget past what it was created with.
    pub fn give_back(&mut self, n: usize) {
        self.remaining = self.remaining.saturating_add(n).min(self.limit);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The bug this type exists to make unrepresentable: `out.len() + size`
    /// wrapping past a cap when `size` is the peer's declared chunk length.
    #[test]
    fn an_absurd_request_gets_only_what_remains() {
        let mut b = Budget::new(1024);
        assert_eq!(b.take(usize::MAX), 1024);
        assert!(b.is_exhausted());
        assert!(b.overrun());
        // …and asking again yields nothing rather than panicking or wrapping.
        assert_eq!(b.take(usize::MAX), 0);
        assert_eq!(b.remaining(), 0);
    }

    /// The equivalent of the old `out.len() + size > cap` check, done safely:
    /// a sequence of chunks that together exceed the cap is cut at the cap.
    #[test]
    fn a_sequence_of_chunks_is_cut_at_the_cap_not_after_it() {
        let mut b = Budget::new(10);
        assert_eq!(b.take(4), 4);
        assert_eq!(b.take(4), 4);
        assert!(!b.overrun(), "nothing has exceeded the cap yet");
        assert_eq!(b.take(4), 2, "the third chunk is cut to what is left");
        assert!(b.overrun());
        assert_eq!(b.spent(), 10);
    }

    #[test]
    fn exact_fit_is_not_an_overrun() {
        let mut b = Budget::new(8);
        assert_eq!(b.take(8), 8);
        assert!(b.is_exhausted());
        assert!(
            !b.overrun(),
            "a body exactly the size of the cap is not truncated"
        );
    }

    #[test]
    fn fits_never_consumes() {
        let b = Budget::new(4);
        assert!(b.fits(4));
        assert!(!b.fits(5));
        assert_eq!(b.remaining(), 4, "fits() must be a pure query");
    }

    #[test]
    fn give_back_cannot_inflate_past_the_limit() {
        let mut b = Budget::new(10);
        b.take(6);
        b.give_back(2);
        assert_eq!(b.remaining(), 6);
        b.give_back(usize::MAX);
        assert_eq!(b.remaining(), 10, "capped at the original limit");
        assert_eq!(b.spent(), 0);
    }

    #[test]
    fn a_zero_budget_grants_nothing() {
        let mut b = Budget::exhausted();
        assert_eq!(b.take(1), 0);
        assert!(b.overrun());
        assert!(b.is_exhausted());
    }

    /// Whatever the sequence, the invariants hold: never over the limit, never
    /// a panic, `spent + remaining == limit`.
    #[test]
    fn invariants_hold_over_a_hostile_sequence() {
        let wants = [
            usize::MAX,
            0,
            1,
            usize::MAX / 2,
            7,
            usize::MAX,
            3,
            usize::MAX - 1,
        ];
        for limit in [0usize, 1, 7, 4096, usize::MAX / 4] {
            let mut b = Budget::new(limit);
            let mut total: usize = 0;
            for w in wants {
                let g = b.take(w);
                assert!(g <= w);
                total += g;
                assert_eq!(b.spent(), total);
                assert_eq!(b.spent() + b.remaining(), b.limit());
            }
            assert!(total <= limit);
        }
    }
}
