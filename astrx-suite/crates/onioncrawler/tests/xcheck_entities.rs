//! Cross-check: the Rust `entities::extract` produces the exact same
//! `(kind, value)` list as the Python reference
//! (`legacy-python/onioncrawler/entities.py`) — same PGP armor-body SHA-1
//! fingerprint, same base58/bech32/hex address recognition (with the `\b`
//! boundary + length semantics that reject over-long blobs and adjacency), same
//! document order (pgp, btc, xmr, eth) and de-duplication. The corpus and every
//! expected value were emitted by driving the Python module directly.

use onioncrawler::entities::{extract, Kind};

/// The exact corpus the Python golden was generated from (lines joined by `\n`).
const LINES: &[&str] = &[
    "Contact our PGP key below:",
    "-----BEGIN PGP PUBLIC KEY BLOCK-----",
    "Version: OnionMail",
    "",
    "mQENBFabc123DEF456ghiJKLmno789PQRstu",
    "wxyz0123456789ABCDEFabcdef+/=ZZZZ",
    "=Ab9",
    "-----END PGP PUBLIC KEY BLOCK-----",
    "Donations: BTC 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
    "bech32 bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4",
    "XMR 44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A",
    "ETH 0x52908400098527886E0F7030069857D2E4169EE7",
    "duplicate BTC again 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
    "toolong 0x52908400098527886E0F7030069857D2E4169EE7abcd should not match",
    "adjacency xxxx1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2xxxx blocked",
    "second eth 0xde0B295669a9FD93d5F28D9Ec85E40f4cb697BAe end",
];

#[test]
fn extract_xcheck() {
    let text = LINES.join("\n");
    let got: Vec<(&str, String)> = extract(&text)
        .into_iter()
        .map(|(k, v)| (k.as_str(), v))
        .collect();
    let want: Vec<(&str, String)> = vec![
        ("pgp", "1a0c8ed2e12fb789f25527971d3b3a7e1a88ffaa".into()),
        ("btc", "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2".into()),
        ("btc", "bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4".into()),
        ("xmr", "44AFFq5kSiGBoZ4NMDwYtN18obc8AemS33DBLWs3H7otXft3XjrpDtQGv7SqSsaBYBb98uNbr2VBBEt7f2wfn3RVGQBEP3A".into()),
        ("eth", "0x52908400098527886E0F7030069857D2E4169EE7".into()),
        ("eth", "0xde0B295669a9FD93d5F28D9Ec85E40f4cb697BAe".into()),
    ];
    assert_eq!(got, want);
}

#[test]
fn empty_and_no_match() {
    assert!(extract("").is_empty());
    assert!(extract("just some words with no crypto or keys here").is_empty());
    // sanity: Kind tags match the Python strings
    assert_eq!(Kind::Pgp.as_str(), "pgp");
    assert_eq!(Kind::Xmr.as_str(), "xmr");
}
