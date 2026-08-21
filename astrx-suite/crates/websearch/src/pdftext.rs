//! Optional, best-effort, dependency-free PDF text extraction — a faithful,
//! byte-for-byte port of the Python `websearch.pdftext`.
//!
//! It inflates `/FlateDecode` content streams (via the stdlib-only
//! [`crawlcore::inflate`]) and pulls text out of the `(...)` / `<...>` string
//! operands of the text-showing operators inside `BT`/`ET` blocks — which
//! covers the common "text-first" PDF produced by ordinary tooling.
//!
//! It does NOT implement font encodings / CID fonts, embedded-image OCR, or
//! encrypted PDFs; for those it returns whatever plain text it can recover, or
//! `""`. Nothing here fakes a result: if extraction finds no text, the caller
//! simply skips the page.
//!
//! # Byte-identity with the Python reference
//!
//! Every cap, the escape/octal handling, the operator handling and the
//! joining/whitespace rules mirror the Python module exactly (see
//! `tests/xcheck_pdftext.rs`, which drives the real Python on the same bytes).
//! PDF strings are byte strings; like Python (`.decode("latin-1", "replace")`)
//! we map each byte `b` to the code point `U+00bb` — so every recovered
//! character is `<= U+00FF`. The whitespace class used by `strip()` / `\s`
//! is Python's, which (unlike Rust's `char::is_whitespace`) also treats the
//! information separators `0x1C..=0x1F` as whitespace; [`is_py_ws`] reproduces
//! that set.
//!
//! ## The one non-byte-identical edge (documented, not faked)
//!
//! Python's `zlib.decompressobj().decompress(data, cap)` verifies the trailing
//! Adler-32 when a stream fully inflates under the cap, raising (→ `b""`) on a
//! bad checksum. [`crawlcore::inflate::inflate_zlib`] parses past the trailer
//! without verifying it, so a stream whose DEFLATE body is valid but whose
//! Adler-32 trailer is corrupt would yield text here where Python yields `""`.
//! This cannot be produced by `zlib.compress(...)` (which writes a correct
//! trailer), so it never arises for real PDFs or for the cross-check fixtures;
//! it is noted here for honesty rather than reproduced.

use crawlcore::budget::Budget;
use crawlcore::inflate::inflate_zlib;

/// Per-stream inflated-byte ceiling (Python `_MAX_STREAM`).
const MAX_STREAM: usize = 8_000_000;
/// Max content streams inspected per document (Python `_STREAM_COUNT_CAP`).
const STREAM_COUNT_CAP: usize = 4096;

/// Default `max_chars` cap for [`extract_text`] (Python's keyword default).
pub const DEFAULT_MAX_CHARS: usize = 2_000_000;

/// True for the whitespace class Python's `str.strip()` and (Unicode-mode) `\s`
/// treat as whitespace, restricted to `<= U+00FF` (all recovered text is
/// latin-1). Note `0x1C..=0x1F` (the information separators) and `0x85`/`0xA0`
/// are whitespace to Python but not to Rust's `char::is_whitespace`.
fn is_py_ws(c: char) -> bool {
    matches!(
        c as u32,
        0x09 | 0x0A | 0x0B | 0x0C | 0x0D | 0x1C | 0x1D | 0x1E | 0x1F | 0x20 | 0x85 | 0xA0
    )
}

/// True for the whitespace class of a Python *bytes* regex `\s`
/// (`[ \t\n\r\f\v]` — note it includes `0x0B`, which Rust's
/// `u8::is_ascii_whitespace` omits, and excludes the `0x1C..=0x1F` set).
fn is_re_bytes_ws(b: u8) -> bool {
    matches!(b, 0x09 | 0x0A | 0x0B | 0x0C | 0x0D | 0x20)
}

/// Index of the first occurrence of `needle` in `hay[from..]`, or `None`
/// (mirrors `bytes.find(needle, from)`; non-empty `needle`).
fn find_from(hay: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if from > hay.len() || needle.is_empty() {
        return None;
    }
    hay[from..]
        .windows(needle.len())
        .position(|w| w == needle)
        .map(|p| p + from)
}

/// Whether `needle` occurs anywhere in `hay` (mirrors `needle in hay`).
fn contains(hay: &[u8], needle: &[u8]) -> bool {
    !needle.is_empty() && hay.windows(needle.len()).any(|w| w == needle)
}

/// Decode a latin-1 byte string to a `String` (`b -> U+00bb`), like Python's
/// `.decode("latin-1", "replace")` (which never actually replaces).
fn latin1(bytes: &[u8]) -> String {
    bytes.iter().map(|&b| b as char).collect()
}

/// Single-byte escape map (Python `_OCTAL`): the byte *after* a backslash that
/// maps to one literal byte. Returns `None` for anything else (octal digits and
/// unknown escapes are handled by the caller).
fn escape_byte(e: u8) -> Option<u8> {
    match e {
        b'n' => Some(b'\n'),
        b'r' => Some(b'\r'),
        b't' => Some(b'\t'),
        b'b' => Some(0x08),
        b'f' => Some(0x0C),
        b'(' => Some(b'('),
        b')' => Some(b')'),
        b'\\' => Some(b'\\'),
        _ => None,
    }
}

/// Value of one ASCII hex digit, or `None` (mirrors CPython's digit table).
fn hex_val(ch: u8) -> Option<u8> {
    match ch {
        b'0'..=b'9' => Some(ch - b'0'),
        b'a'..=b'f' => Some(ch - b'a' + 10),
        b'A'..=b'F' => Some(ch - b'A' + 10),
        _ => None,
    }
}

/// Port of `bytes.fromhex(str)` over an ASCII byte slice: ASCII whitespace is
/// skipped *between* byte pairs only; any non-hex/non-space byte or a dangling
/// nibble is a `ValueError` (→ `None`).
fn from_hex(s: &[u8]) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    let mut hi: Option<u8> = None;
    for &ch in s {
        if matches!(ch, 0x09 | 0x0A | 0x0B | 0x0C | 0x0D | 0x20) {
            if hi.is_some() {
                return None; // whitespace between the two nibbles of a byte
            }
            continue;
        }
        let d = hex_val(ch)?;
        match hi {
            None => hi = Some(d),
            Some(h) => {
                out.push((h << 4) | d);
                hi = None;
            }
        }
    }
    if hi.is_some() {
        return None; // dangling nibble → odd length
    }
    Some(out)
}

/// Read a balanced `(...)` string literal starting at `buf[i-1] == '('` (so `i`
/// is the first content byte). Returns `(decoded_bytes, next_index)`. Honours
/// `\` escapes, octal escapes and nested parentheses (Python `_read_literal`).
fn read_literal(buf: &[u8], mut i: usize) -> (Vec<u8>, usize) {
    let mut depth: usize = 0;
    let mut out = Vec::new();
    let n = buf.len();
    while i < n {
        let c = buf[i];
        if c == 0x5C {
            // backslash
            i += 1;
            if i >= n {
                break;
            }
            let e = buf[i];
            if let Some(b) = escape_byte(e) {
                out.push(b);
                i += 1;
            } else if (0x30..=0x37).contains(&e) {
                // up to 3 octal digits
                let mut j = i;
                let mut val: u16 = 0;
                let mut count = 0;
                while j < n && count < 3 && (0x30..=0x37).contains(&buf[j]) {
                    val = val * 8 + u16::from(buf[j] - 0x30);
                    j += 1;
                    count += 1;
                }
                out.push((val & 0xFF) as u8);
                i = j;
            } else {
                out.push(e);
                i += 1;
            }
            continue;
        }
        if c == 0x28 {
            // (
            depth += 1;
            out.push(b'(');
            i += 1;
            continue;
        }
        if c == 0x29 {
            // )
            if depth == 0 {
                return (out, i + 1);
            }
            depth -= 1;
            out.push(b')');
            i += 1;
            continue;
        }
        out.push(c);
        i += 1;
    }
    (out, i)
}

#[cfg(test)]
thread_local! {
    /// Fragments built by [`StreamFragments`] on this thread. Test-only: nothing
    /// in the shipped build counts, reads or allocates it.
    ///
    /// The lazy iterator exists so that no fragment past `extract_text`'s
    /// `max_chars` is ever *built*, and the count is that property stated
    /// directly. It replaces a wall-clock comparison that could not state it —
    /// see `the_char_cap_stops_extraction_instead_of_trimming_it_afterwards`.
    static FRAGMENTS_BUILT: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
}

/// Record one fragment built (test builds only).
#[cfg(test)]
fn note_fragment_built() {
    FRAGMENTS_BUILT.with(|c| c.set(c.get() + 1));
}

/// Run `f` and report how many fragments were built while it ran. The counter is
/// thread-local, so a test using it is unaffected by the other tests the harness
/// runs beside it.
#[cfg(test)]
fn counting_fragments<T>(f: impl FnOnce() -> T) -> (T, usize) {
    FRAGMENTS_BUILT.with(|c| c.set(0));
    let out = f();
    (out, FRAGMENTS_BUILT.with(std::cell::Cell::get))
}

/// The text fragments of a decoded content-stream body — `(...)` literals and
/// `<...>` hex strings — yielded ONE AT A TIME, exactly as Python's
/// `_extract_from_stream` produces them in order.
///
/// Lazy on purpose. Collecting them first and applying `extract_text`'s
/// `max_chars` afterwards meant a stream of `(a)Tj` became one `String` per
/// character before a single one could be refused: a 12 kB PDF inflating to 8 MB
/// of `(a)Tj` is ~2.6 million one-character `String`s, which took peak RSS from
/// 20 MB to 122 MB (~10 000× the input) — times `--workers`.
struct StreamFragments<'a> {
    body: &'a [u8],
    i: usize,
}

impl Iterator for StreamFragments<'_> {
    type Item = String;

    fn next(&mut self) -> Option<String> {
        let body = self.body;
        let n = body.len();
        while self.i < n {
            let c = body[self.i];
            if c == 0x28 {
                // (  -> literal string
                let (s, next) = read_literal(body, self.i + 1);
                self.i = next;
                if !s.is_empty() {
                    #[cfg(test)]
                    note_fragment_built();
                    return Some(latin1(&s));
                }
                continue;
            }
            if c == 0x3C && self.i + 1 < n && body[self.i + 1] != 0x3C {
                // <hex> (not the << of a dict)
                let j = find_from(body, self.i + 1, b">")?;
                let mut hexs: Vec<u8> = body[self.i + 1..j]
                    .iter()
                    .copied()
                    .filter(|&ch| !matches!(ch, b' ' | b'\r' | b'\n' | b'\t'))
                    .collect();
                if hexs.len() % 2 == 1 {
                    hexs.push(b'0');
                }
                // .decode("ascii", "ignore") drops bytes >= 0x80 before fromhex.
                let ascii: Vec<u8> = hexs.into_iter().filter(|&ch| ch < 0x80).collect();
                let decoded = from_hex(&ascii);
                self.i = j + 1;
                if let Some(d) = decoded {
                    #[cfg(test)]
                    note_fragment_built();
                    return Some(latin1(&d));
                }
                continue;
            }
            self.i += 1;
        }
        None
    }
}

/// The fragments of `body`, in order.
fn extract_from_stream(body: &[u8]) -> StreamFragments<'_> {
    StreamFragments { body, i: 0 }
}

/// Inflate a `/FlateDecode` (zlib-wrapped) stream, capping output at `cap`
/// bytes: partial output up to the cap, `b""` on error — matching Python's
/// `zlib.decompressobj().decompress(data, cap)` (see the module note for the
/// single Adler-32 edge).
fn inflate(data: &[u8], cap: usize) -> Vec<u8> {
    inflate_zlib(data, cap).map(|(b, _)| b).unwrap_or_default()
}

/// Lazy walk of `stream…endstream` blocks yielding decoded content-stream
/// bodies (Python `_content_streams`). Stops once the cumulative
/// inflated/scanned budget or the stream-count cap is hit, shrinking each
/// per-stream inflate cap to the remaining budget — so a bomb of many near-cap
/// FlateDecode streams that yields no text still terminates in bounded time.
struct ContentStreams<'a> {
    pdf: &'a [u8],
    max_total: usize,
    produced: usize,
    seen: usize,
    pos: usize,
}

impl Iterator for ContentStreams<'_> {
    type Item = Vec<u8>;

    fn next(&mut self) -> Option<Vec<u8>> {
        while self.produced < self.max_total && self.seen < STREAM_COUNT_CAP {
            let s = find_from(self.pdf, self.pos, b"stream")?;
            let mut j = s + 6; // past the 'stream' keyword
            if self.pdf.get(j) == Some(&b'\r') {
                j += 1;
            }
            if self.pdf.get(j) == Some(&b'\n') {
                // a real stream keyword is followed by EOL
                j += 1;
            } else {
                // e.g. the 'stream' inside 'endstream'
                self.pos = s + 6;
                continue;
            }
            let e = find_from(self.pdf, j, b"endstream")?; // first (shortest) match
            let mut body_end = e;
            if body_end >= 1 && self.pdf[body_end - 1] == b'\n' {
                body_end -= 1;
            }
            if body_end >= 1 && self.pdf[body_end - 1] == b'\r' {
                body_end -= 1;
            }
            let raw: &[u8] = if body_end >= j {
                &self.pdf[j..body_end]
            } else {
                &[]
            };
            self.pos = e + 9; // past 'endstream'
            self.seen += 1;
            let head = &self.pdf[s.saturating_sub(256)..s];
            let body: Vec<u8> = if contains(head, b"/FlateDecode") {
                let cap = MAX_STREAM.min(self.max_total - self.produced + 1);
                inflate(raw, cap)
            } else {
                raw.to_vec()
            };
            self.produced += body.len();
            if !body.is_empty()
                && (contains(&body, b"BT") || contains(&body, b"Tj") || contains(&body, b"TJ"))
            {
                return Some(body);
            }
        }
        None
    }
}

/// Best-effort extracted text from PDF `data`, whitespace-collapsed and capped
/// at `max_chars` code points. Empty string when `data` is empty, lacks a
/// `%PDF` marker in its first 1024 bytes, or yields no recoverable text.
///
/// This is a pure function: no I/O, no allocation beyond the recovered text and
/// the bounded inflate scratch.
#[must_use]
pub fn extract_text(data: &[u8], max_chars: usize) -> String {
    if data.is_empty() {
        return String::new();
    }
    let head = &data[..data.len().min(1024)];
    if !contains(head, b"%PDF") {
        return String::new();
    }
    // Aggregate inflate/scan budget across ALL streams, so a bomb that yields no
    // text still terminates in bounded time.
    let inflate_budget = max_chars.saturating_mul(8).max(MAX_STREAM);
    let mut pieces: Vec<String> = Vec::new();
    // The character budget is spent AS fragments arrive — `StreamFragments` is an
    // iterator precisely so no fragment past the cap is ever built. `+ 1` per
    // fragment is the separating space `pieces.join(" ")` will add.
    let mut budget = Budget::new(max_chars);
    let streams = ContentStreams {
        pdf: data,
        max_total: inflate_budget,
        produced: 0,
        seen: 0,
        pos: 0,
    };
    'outer: for body in streams {
        for frag in extract_from_stream(&body) {
            let frag = frag.trim_matches(is_py_ws);
            if frag.is_empty() {
                continue;
            }
            let flen = frag.chars().count();
            pieces.push(frag.to_string());
            budget.take(flen + 1);
            if budget.is_exhausted() {
                break 'outer;
            }
        }
    }
    let joined = pieces.join(" ");
    let collapsed = collapse_ws(&joined);
    collapsed
        .trim_matches(is_py_ws)
        .chars()
        .take(max_chars)
        .collect()
}

/// Collapse each maximal run of Python-whitespace to a single space
/// (`re.sub(r"\s+", " ", text)` over latin-1 text).
fn collapse_ws(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut in_ws = false;
    for c in s.chars() {
        if is_py_ws(c) {
            if !in_ws {
                out.push(' ');
                in_ws = true;
            }
        } else {
            out.push(c);
            in_ws = false;
        }
    }
    out
}

/// Best-effort document `/Title` (empty string if absent), whitespace-collapsed
/// and trimmed — a port of Python's `extract_title`.
#[must_use]
pub fn extract_title(data: &[u8]) -> String {
    let Some(group) = find_title_group(data) else {
        return String::new();
    };
    // raw, _ = _read_literal(b"(" + group + b")", 1)
    let mut wrapped = Vec::with_capacity(group.len() + 2);
    wrapped.push(b'(');
    wrapped.extend_from_slice(group);
    wrapped.push(b')');
    let (raw, _) = read_literal(&wrapped, 1);
    let text = latin1(&raw);
    collapse_ws(&text).trim_matches(is_py_ws).to_string()
}

/// Find the first `/Title` string operand and return the raw captured bytes
/// (group 1 of Python's `rb"/Title\s*\(((?:\\.|[^\\()])*)\)"`, `re.search`).
fn find_title_group(data: &[u8]) -> Option<&[u8]> {
    let n = data.len();
    let mut from = 0usize;
    while let Some(t) = find_from(data, from, b"/Title") {
        let mut i = t + 6; // past "/Title"
        while i < n && is_re_bytes_ws(data[i]) {
            i += 1; // \s*
        }
        if i >= n || data[i] != b'(' {
            from = t + 1; // this candidate fails; re.search advances
            continue;
        }
        i += 1; // past '('
        let group_start = i;
        // group := (?:\\.|[^\\()])* — stops at an unescaped '(' / ')', a lone
        // trailing '\', or EOF. `\\.` consumes a backslash + any non-newline.
        loop {
            if i >= n {
                break;
            }
            let c = data[i];
            if c == b'\\' {
                if i + 1 < n && data[i + 1] != b'\n' {
                    i += 2;
                    continue;
                }
                break;
            }
            if c == b'(' || c == b')' {
                break;
            }
            i += 1;
        }
        if i < n && data[i] == b')' {
            return Some(&data[group_start..i]);
        }
        from = t + 1; // group did not close with ')': try the next '/Title'
    }
    None
}

#[cfg(test)]
mod audit_regression {
    use super::*;

    /// A minimal PDF whose single content stream repeats `(a)Tj` `n` times.
    fn pdf_of_tj(n: usize) -> Vec<u8> {
        let mut out = Vec::with_capacity(n * 6 + 64);
        out.extend_from_slice(b"%PDF-1.4\n1 0 obj\n<< >>\nstream\n");
        out.extend_from_slice(b"BT ");
        for _ in 0..n {
            out.extend_from_slice(b"(a)Tj ");
        }
        out.extend_from_slice(b"\nendstream\nendobj\n%%EOF\n");
        out
    }

    /// AUDIT REGRESSION (LOW). `extract_from_stream` used to collect EVERY
    /// fragment of an inflated stream into a `Vec<String>` before `extract_text`'s
    /// `max_chars` could refuse any of them. A 12 kB PDF inflating to 8 MB of
    /// `(a)Tj` is ~2.6 million one-character `String`s: peak RSS went 20 MB →
    /// 122 MB, about 10 000× the input, and once per `--workers` worker.
    ///
    /// The observable is the number of fragments BUILT, counted by the iterator
    /// itself, which is the property word for word: under a 100-character cap 50
    /// of the 400 000 fragments are built, and without one, all 400 000. Eagerly,
    /// both runs build all 400 000.
    ///
    /// This was a stopwatch — `capped * 3 < uncapped` over the two extractions —
    /// and it failed for real (run 7 of a 40-run loop: 134 ms against 379 ms). The
    /// capped side does 1/8000 of the work, so it is a sub-millisecond
    /// measurement, and one scheduler preemption on a loaded two-core runner adds
    /// a whole quantum to it while leaving the uncapped side unmoved in relative
    /// terms; the 3× ratio was well inside that noise. The counts are 50 against
    /// 400 000 on any machine, and the eager failure mode lands at 400 000 against
    /// 400 000 — nothing about the schedule can move either number.
    #[test]
    fn the_char_cap_stops_extraction_instead_of_trimming_it_afterwards() {
        let pdf = pdf_of_tj(400_000);

        let (text, capped) = counting_fragments(|| extract_text(&pdf, 100));
        // 50 one-character fragments, space-joined, is what a 100-char budget buys.
        assert_eq!(text, "a ".repeat(49) + "a");
        assert_eq!(
            capped, 50,
            "a 100-character cap built {capped} fragments, not the 50 it can spend: \
             the cap is being applied after fragments are built, not to stop building them"
        );

        let (full, uncapped) = counting_fragments(|| extract_text(&pdf, 100_000_000));
        assert_eq!(full.chars().count(), 400_000 * 2 - 1);
        assert_eq!(uncapped, 400_000);
    }

    /// The mechanism, asserted directly: producing one fragment must not consume
    /// the stream. (Before the fix the function returned a `Vec` — there was no
    /// position to inspect, because the whole body had already been walked.)
    #[test]
    fn one_fragment_does_not_walk_the_whole_stream() {
        let body = b"BT (a)Tj "
            .iter()
            .copied()
            .chain(std::iter::repeat(b'z').take(100_000))
            .collect::<Vec<u8>>();
        let mut it = extract_from_stream(&body);
        assert_eq!(it.next().as_deref(), Some("a"));
        assert!(
            it.i < 32,
            "reading the first fragment consumed {} of {} bytes",
            it.i,
            body.len()
        );
    }

    /// …and the capped result is exactly what the eager version produced: the same
    /// prefix, from the same fragments, in the same order.
    #[test]
    fn capping_early_yields_the_same_text_as_capping_late() {
        for n in [1usize, 2, 7, 50, 400] {
            let pdf = pdf_of_tj(n);
            for cap in [1usize, 2, 3, 10, 99, 100, 101, 5_000] {
                let got = extract_text(&pdf, cap);
                // The eager reference: all fragments, then the same accounting.
                let mut pieces: Vec<String> = Vec::new();
                let mut total = 0usize;
                let all: Vec<String> =
                    extract_from_stream(&pdf[pdf.iter().position(|&b| b == b'B').unwrap()..])
                        .collect();
                for frag in all {
                    let frag = frag.trim_matches(is_py_ws);
                    if frag.is_empty() {
                        continue;
                    }
                    pieces.push(frag.to_string());
                    total += frag.chars().count() + 1;
                    if total >= cap {
                        break;
                    }
                }
                let want: String = collapse_ws(&pieces.join(" "))
                    .trim_matches(is_py_ws)
                    .chars()
                    .take(cap)
                    .collect();
                assert_eq!(got, want, "n={n} cap={cap}");
            }
        }
    }
}
