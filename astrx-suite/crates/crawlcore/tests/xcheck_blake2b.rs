//! Cross-check: hand-rolled BLAKE2b matches Python's `hashlib.blake2b`
//! (unkeyed) across a spread of inputs and output lengths, including a >128-byte
//! message that spans two compression blocks. Golden hex digests were emitted by
//! driving CPython's `hashlib`. Regenerate with `tests/regen_goldens.py`.

use crawlcore::blake2b::blake2b;
use crawlcore::hash::to_hex;

/// (out_len, input_hex, expected_digest_hex)
const GOLDENS: &[(usize, &str, &str)] = &[
    (8, "", "e4a6a0577479b2b4"),
    (8, "61", "40f89e395b66422f"),
    (8, "616263", "d8bb14d833d59559"),
    (8, "68656c6c6f20776f726c64", "878633aa32a3b150"),
    (8, "6f6e696f6e", "09ffa17859799303"),
    (
        8,
        "54686520717569636b2062726f776e20666f78206a756d7073206f76657220746865206c617a7920646f67",
        "4c4531b978d589f8",
    ),
    (
        32,
        "616263",
        "bddd813c634239723171ef3fee98579b94964e3bb1cb3e427262c8c068d52319",
    ),
    (
        64,
        "616263",
        "ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d1\
         7d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923",
    ),
];

fn unhex(s: &str) -> Vec<u8> {
    let s: String = s.chars().filter(|c| !c.is_whitespace()).collect();
    (0..s.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
        .collect()
}

#[test]
fn blake2b_matches_python() {
    for (out_len, input_hex, expected) in GOLDENS {
        let input = unhex(input_hex);
        let got = to_hex(&blake2b(&input, *out_len));
        let want: String = expected.chars().filter(|c| !c.is_whitespace()).collect();
        assert_eq!(got, want, "blake2b({input_hex}, {out_len})");
    }
}

#[test]
fn two_block_message() {
    // 200 bytes 0x00..0xC7 → digest_size 16, from Python hashlib.
    let msg: Vec<u8> = (0..200u16).map(|b| b as u8).collect();
    assert_eq!(
        to_hex(&blake2b(&msg, 16)),
        "61479efa6267fea757b3f881e2979bbc"
    );
}
