#![no_main]
//! Fuzz the canonical bencode decoder — the single most exposed hostile-input
//! surface (every DHT datagram, info-dict and tracker payload flows through it).
//!
//! Invariants asserted (all hold by construction; a failure is a real bug):
//!   * `decode` never panics on arbitrary bytes.
//!   * `decode` accepts ONLY canonical bencode, so `encode(decode(x)) == x`
//!     byte-for-byte for every accepted `x` (round-trip stability).
//!   * `decode_lenient` never panics; its canonicalising re-encode is always
//!     strict-decodable and stable.
//!   * `decode_prefix` never panics; it consumes at most the whole input and its
//!     value re-encodes to exactly the consumed prefix.

use libfuzzer_sys::fuzz_target;
use torrentds::bencode::{decode, decode_lenient, decode_prefix, encode};

fuzz_target!(|data: &[u8]| {
    // --- strict decode: canonical in => byte-identical round-trip out ---
    if let Ok(value) = decode(data) {
        let reencoded = encode(&value);
        assert_eq!(
            reencoded.as_slice(),
            data,
            "strict decode accepts only canonical bencode, so encode(decode(x)) must equal x"
        );
        // Re-decoding the canonical form must reproduce the identical value.
        let redecoded = decode(&reencoded).expect("canonical re-encode must strict-decode");
        assert_eq!(redecoded, value, "decode is a left inverse of encode on values");
    }

    // --- lenient decode: accepts a non-canonical superset; check canonical
    //     output is stable rather than byte-equal to the (possibly sloppy) input.
    if let Ok(value) = decode_lenient(data) {
        let reencoded = encode(&value);
        let strict = decode(&reencoded)
            .expect("canonicalised lenient output must be accepted by the strict decoder");
        assert_eq!(
            encode(&strict),
            reencoded,
            "the canonical form of a lenient decode must be a fixed point"
        );
    }

    // --- prefix decode: one value off the front, trailing bytes permitted ---
    if let Ok((value, used)) = decode_prefix(data) {
        assert!(used <= data.len(), "decode_prefix consumed past the end of input");
        let prefix = encode(&value);
        assert_eq!(
            prefix.as_slice(),
            &data[..used],
            "decode_prefix must consume exactly the canonical encoding of its value"
        );
    }
});
