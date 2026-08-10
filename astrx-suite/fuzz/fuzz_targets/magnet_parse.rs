#![no_main]
//! Fuzz the `magnet:` URI parser — hex/base32 btih, btmh multihash and percent /
//! `+` decoding of the display name. `parse_magnet` takes `&str`, so we derive
//! text from the raw fuzz bytes and require it to never panic.
//!
//! `parse_magnet` bails immediately unless the input starts with the literal
//! `magnet:`, so feeding raw random bytes would almost always stop at byte 0.
//! To keep the fuzzer productive INSIDE the query / xt-decoding logic (the real
//! attack surface) we also probe a `magnet:?`-prefixed view of the same bytes.

use libfuzzer_sys::fuzz_target;
use torrentds::metadata::parse_magnet;

fuzz_target!(|data: &[u8]| {
    // Lossy view: always valid UTF-8, so every input reaches the parser.
    let lossy = String::from_utf8_lossy(data);
    let _ = parse_magnet(&lossy);

    // Drive past the `magnet:` prefix gate to reach the query-string parser.
    let _ = parse_magnet(&format!("magnet:?{lossy}"));

    // Exact bytes when they happen to be valid UTF-8 (no replacement chars).
    if let Ok(s) = std::str::from_utf8(data) {
        let _ = parse_magnet(s);
    }
});
