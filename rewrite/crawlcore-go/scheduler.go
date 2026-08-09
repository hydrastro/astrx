package crawlcore

// Pure recrawl-scheduling arithmetic (no DB, no clock ownership). Both crawlers
// refresh on a per-page interval and back it off when a page is seen unchanged.

// IsDue reports whether a page fetched at fetchedAt on the given interval is due
// at now. A page never fetched (fetchedAt <= 0) is not scheduled here.
func IsDue(fetchedAt, interval, now float64) bool {
	if fetchedAt <= 0 {
		return false
	}
	return fetchedAt+interval <= now
}

// NextDue is the timestamp at which the page becomes due for recrawl.
func NextDue(fetchedAt, interval float64) float64 { return fetchedAt + interval }

// BackoffInterval grows a recrawl interval multiplicatively when a page is
// unchanged: fall back to base when there is no current interval, multiply by
// factor, and cap at maxInterval (0 = uncapped). Returns 0 when there is nothing
// to grow (no current interval and no base) — the "leave it alone" branch.
func BackoffInterval(current, factor, maxInterval, base float64) float64 {
	cur := current
	if cur == 0 {
		cur = base
	}
	var next float64
	if cur != 0 {
		next = cur * factor
	}
	if maxInterval != 0 && next != 0 && next > maxInterval {
		next = maxInterval
	}
	return next
}
