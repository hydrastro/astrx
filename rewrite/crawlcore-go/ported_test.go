package crawlcore

import "testing"

// ---- dedup (SimHash bit-math) ----

func TestHammingBasic(t *testing.T) {
	if Hamming(0, 0) != 0 || Hamming(0b1011, 0b1110) != 2 || Hamming(0, ^uint64(0)) != 64 {
		t.Error("hamming basic")
	}
}

func TestSigned64Roundtrip(t *testing.T) {
	for _, u := range []uint64{0, 1, (1 << 63) - 1, 1 << 63, ^uint64(0)} {
		if Hamming(uint64(Signed64(u)), u) != 0 { // signed & unsigned share bits
			t.Errorf("signed64 bits differ for %d", u)
		}
	}
}

func TestNear(t *testing.T) {
	if !Near(0b1111, 0b1110, 1) || Near(0b1111, 0b1000, 1) || Near(0, 123, 3) || Near(123, 0, 3) {
		t.Error("near")
	}
}

func TestSimhashEmptyAndWeighting(t *testing.T) {
	if SimhashVector(nil) != 0 || SimhashVector([]WeightedHash{}) != 0 {
		t.Error("empty simhash must be 0")
	}
	h1, h2 := uint64(0xDEADBEEFCAFEF00D), uint64(0x0123456789ABCDEF)
	weighted := SimhashVector([]WeightedHash{{h1, 3}, {h2, 1}})
	expanded := SimhashVector([]WeightedHash{{h1, 1}, {h1, 1}, {h1, 1}, {h2, 1}})
	if weighted != expanded {
		t.Error("weight-by-count must equal per-occurrence")
	}
}

// ---- scheduler ----

func TestScheduler(t *testing.T) {
	if !IsDue(1000, 100, 1100) || !IsDue(1000, 100, 1200) || IsDue(1000, 100, 1099.9) || IsDue(0, 100, 1e9) {
		t.Error("is_due")
	}
	if NextDue(1000, 250) != 1250 {
		t.Error("next_due")
	}
	if BackoffInterval(100, 2, 0, 0) != 200 || BackoffInterval(100, 2, 150, 0) != 150 ||
		BackoffInterval(0, 2, 0, 50) != 100 || BackoffInterval(0, 2, 0, 0) != 0 {
		t.Error("backoff")
	}
}

// ---- traps ----

func TestTraps(t *testing.T) {
	if TooDeep("/a/b/c", 5) || !TooDeep("/a/b/c/d/e/f/g", 5) {
		t.Error("too_deep")
	}
	if RepeatedSegment("/a/b/a/b", 2) || !RepeatedSegment("/a/a/a/a", 2) {
		t.Error("repeated_segment")
	}
	if SegmentRepeatMax("/a/b/a/a") != 3 {
		t.Error("segment_repeat_max")
	}
	if !CyclicPath("/a/b/a/b/a/b", 3, 2) || CyclicPath("/a/b/c/d", 3, 2) {
		t.Error("cyclic_path")
	}
	for _, v := range []string{"123", "2020", "2020-01", "2020-1-2"} {
		if !Numericish(v) {
			t.Errorf("%q should be numericish", v)
		}
	}
	for _, v := range []string{"abc", "12a"} {
		if Numericish(v) {
			t.Errorf("%q should not be numericish", v)
		}
	}
	if !LooksLikePagination([][2]string{{"page", "2"}, {"year", "2020"}}) ||
		LooksLikePagination([][2]string{{"q", "hello"}}) || LooksLikePagination(nil) {
		t.Error("looks_like_pagination")
	}
	if QueryParamCount("a=1&b=2") != 2 || QueryParamCount("") != 0 {
		t.Error("query_param_count")
	}
}
