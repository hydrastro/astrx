//! Cross-check: the hand-rolled inflater decompresses byte-identically to
//! Python's `zlib`. For each corpus item, Python produced the raw-DEFLATE,
//! zlib-wrapped, and gzip-wrapped compressed forms; the Rust inflater must
//! recover the exact original. Also covers the output cap (bomb defense) and a
//! gzip header with an FNAME field. Compressed blobs were emitted by driving
//! Python `zlib`.

use crawlcore::inflate::{inflate_gzip, inflate_raw, inflate_zlib};

fn unhex(s: &str) -> Vec<u8> {
    (0..s.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
        .collect()
}

const M: usize = 10_000_000;

/// (name, plain, raw_hex, zlib_hex, gzip_hex)
fn corpus() -> Vec<(
    &'static str,
    Vec<u8>,
    &'static str,
    &'static str,
    &'static str,
)> {
    let binary: Vec<u8> = (0..4).flat_map(|_| 0u8..=255).collect();
    let onion_page: Vec<u8> = [
        b"<html><head><title>Test Onion</title></head><body>".as_slice(),
        &b"<p>content</p>".repeat(50),
        b"</body></html>".as_slice(),
    ]
    .concat();
    vec![
        ("empty", b"".to_vec(), "0300", "78da030000000001", "1f8b080000000000020303000000000000000000"),
        (
            "short",
            b"hello world".to_vec(),
            "cb48cdc9c95728cf2fca490100",
            "78dacb48cdc9c95728cf2fca4901001a0b045d",
            "1f8b0800000000000203cb48cdc9c95728cf2fca49010085114a0d0b000000",
        ),
        (
            "repetitive",
            b"ab".repeat(300),
            "4b4c4a1c85a390ea1000",
            "78da4b4c4a1c85a390ea10004e68e485",
            "1f8b08000000000002034b4c4a1c85a390ea1000f3f0f52458020000",
        ),
        (
            "text",
            b"The quick brown fox jumps over the lazy dog. ".repeat(30),
            "0bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c2a1e553caa7854f1a86254c500",
            "78da0bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c2a1e553caa7854f1a86254c500e8b8e4a2",
            "1f8b08000000000002030bc94855282ccd4cce56482aca2fcf5348cbaf50c82acd2d2856c82f4b2d5228014ae72456552aa4e4a7eb29848c2a1e553caa7854f1a86254c5002bf11f6746050000",
        ),
        (
            "binary",
            binary,
            "6360646266616563e7e0e4e2e6e1e5e3171014121611151397909492969195935750545256515553d7d0d4d2d6d1d5d33730343236313533b7b0b4b2b6b1b5b37770747276717573f7f0f4f2f6f1f5f30f080c0a0e090d0b8f888c8a8e898d8b4f484c4a4e494d4bcfc8cccacec9cdcb2f282c2a2e292d2bafa8acaaaea9adab6f686c6a6e696d6befe8eceaeee9edeb9f3071d2e42953a74d9f3173d6ec3973e7cd5fb070d1e2254b972d5fb172d5ea356bd7addfb071d3e62d5bb76ddfb173d7ee3d7bf7ed3f70f0d0e123478f1d3f71f2d4e93367cf9dbf70f1d2e52b57af5dbf71f3d6ed3b77efdd7ff0f0d1e3274f9f3d7ff1f2d5eb376fdfbdfff0f1d3e72f5fbf7dfff1f3d7ef3f7ffffd6718f5ffa8ff47b0ff01",
            "78da6360646266616563e7e0e4e2e6e1e5e3171014121611151397909492969195935750545256515553d7d0d4d2d6d1d5d33730343236313533b7b0b4b2b6b1b5b37770747276717573f7f0f4f2f6f1f5f30f080c0a0e090d0b8f888c8a8e898d8b4f484c4a4e494d4bcfc8cccacec9cdcb2f282c2a2e292d2bafa8acaaaea9adab6f686c6a6e696d6befe8eceaeee9edeb9f3071d2e42953a74d9f3173d6ec3973e7cd5fb070d1e2254b972d5fb172d5ea356bd7addfb071d3e62d5bb76ddfb173d7ee3d7bf7ed3f70f0d0e123478f1d3f71f2d4e93367cf9dbf70f1d2e52b57af5dbf71f3d6ed3b77efdd7ff0f0d1e3274f9f3d7ff1f2d5eb376fdfbdfff0f1d3e72f5fbf7dfff1f3d7ef3f7ffffd6718f5ffa8ff47b0ff01e4c9fe10",
            "1f8b08000000000002036360646266616563e7e0e4e2e6e1e5e3171014121611151397909492969195935750545256515553d7d0d4d2d6d1d5d33730343236313533b7b0b4b2b6b1b5b37770747276717573f7f0f4f2f6f1f5f30f080c0a0e090d0b8f888c8a8e898d8b4f484c4a4e494d4bcfc8cccacec9cdcb2f282c2a2e292d2bafa8acaaaea9adab6f686c6a6e696d6befe8eceaeee9edeb9f3071d2e42953a74d9f3173d6ec3973e7cd5fb070d1e2254b972d5fb172d5ea356bd7addfb071d3e62d5bb76ddfb173d7ee3d7bf7ed3f70f0d0e123478f1d3f71f2d4e93367cf9dbf70f1d2e52b57af5dbf71f3d6ed3b77efdd7ff0f0d1e3274f9f3d7ff1f2d5eb376fdfbdfff0f1d3e72f5fbf7dfff1f3d7ef3f7ffffd6718f5ffa8ff47b0ff01264c0bb700040000",
        ),
        (
            "onion_page",
            onion_page,
            "b3c928c9cdb1b3c9484d4cb1b329c92cc949b50b492d2e51f0cfcbcccfb3d18788d8e843e493f2532aed6c0aec92f3f34a52f34a6cf40b4679a3bca1c4d38724617d70aa0700",
            "78dab3c928c9cdb1b3c9484d4cb1b329c92cc949b50b492d2e51f0cfcbcccfb3d18788d8e843e493f2532aed6c0aec92f3f34a52f34a6cf40b4679a3bca1c4d38724617d70aa070028841029",
            "1f8b0800000000000203b3c928c9cdb1b3c9484d4cb1b329c92cc949b50b492d2e51f0cfcbcccfb3d18788d8e843e493f2532aed6c0aec92f3f34a52f34a6cf40b4679a3bca1c4d38724617d70aa070076a789cefc020000",
        ),
    ]
}

#[test]
fn inflate_roundtrip_xcheck() {
    for (name, plain, raw, zl, gz) in corpus() {
        assert_eq!(
            inflate_raw(&unhex(raw), M).unwrap(),
            (plain.clone(), false),
            "{name} raw"
        );
        assert_eq!(
            inflate_zlib(&unhex(zl), M).unwrap(),
            (plain.clone(), false),
            "{name} zlib"
        );
        assert_eq!(
            inflate_gzip(&unhex(gz), M).unwrap(),
            (plain.clone(), false),
            "{name} gzip"
        );
    }
}

#[test]
fn output_cap_xcheck() {
    // "hello world" raw deflate, capped at 5 decompressed bytes.
    let raw = unhex("cb48cdc9c95728cf2fca490100");
    assert_eq!(inflate_raw(&raw, 5).unwrap(), (b"hello".to_vec(), true));
}

#[test]
fn gzip_fname_header_xcheck() {
    // gzip stream with the FNAME flag set ("file.txt\0" in the header).
    let gz =
        unhex("1f8b08080000000000ff66696c652e74787400cb4bcc4d4d5148492c4904004d51cf2b0a000000");
    assert_eq!(
        inflate_gzip(&gz, M).unwrap(),
        (b"named data".to_vec(), false)
    );
}
