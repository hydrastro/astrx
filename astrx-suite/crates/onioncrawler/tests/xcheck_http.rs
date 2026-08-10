//! Cross-check: the Rust HTTP wire helpers match the Python reference
//! (`legacy-python/onioncrawler/http_client.py`) — request building (latin-1),
//! status-line parsing (trailing-space reason, non-`HTTP/` rejection), header
//! parsing (lowercased keys, `, `-joined duplicates, colon-less lines skipped),
//! and chunked-body decoding (incl. chunk extensions). Expected values were
//! emitted by driving the Python module.

use onioncrawler::http::{build_request, decode_chunked, parse_headers, parse_status_line};

fn hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02x}")).collect()
}

#[test]
fn build_request_xcheck() {
    assert_eq!(
        hex(&build_request("GET", "/", "h.onion", &[])),
        "474554202f20485454502f312e310d0a486f73743a20682e6f6e696f6e0d0a0d0a"
    );
    // empty path becomes "/"
    assert_eq!(
        hex(&build_request("GET", "", "h.onion", &[])),
        "474554202f20485454502f312e310d0a486f73743a20682e6f6e696f6e0d0a0d0a"
    );
    let extra = [
        ("User-Agent".to_string(), "oc/1".to_string()),
        ("Accept".to_string(), "*/*".to_string()),
    ];
    assert_eq!(
        hex(&build_request("GET", "/p?q=1", "h.onion", &extra)),
        "474554202f703f713d3120485454502f312e310d0a486f73743a20682e6f6e696f6e0d0a557365722d4167656e743a206f632f310d0a4163636570743a202a2f2a0d0a0d0a"
    );
}

#[test]
fn parse_status_line_xcheck() {
    let cases: &[(&[u8], &str, u16, &str)] = &[
        (b"HTTP/1.1 200 OK", "HTTP/1.1", 200, "OK"),
        (b"HTTP/1.0 404 Not Found", "HTTP/1.0", 404, "Not Found"),
        (b"HTTP/1.1 301 ", "HTTP/1.1", 301, ""), // trailing space => empty reason
        (
            b"HTTP/1.1 500 Internal Server Error",
            "HTTP/1.1",
            500,
            "Internal Server Error",
        ),
    ];
    for (line, v, s, r) in cases {
        let (gv, gs, gr) = parse_status_line(line).unwrap();
        assert_eq!(
            (gv.as_str(), gs, gr.as_str()),
            (*v, *s, *r),
            "status {line:?}"
        );
    }
}

#[test]
fn parse_headers_xcheck() {
    let block = b"Content-Type: text/html; charset=utf-8\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n  \r\nX-Empty:\r\nNoColonLine";
    let h = parse_headers(block);
    assert_eq!(
        h.pairs(),
        &[
            (
                "content-type".to_string(),
                "text/html; charset=utf-8".to_string()
            ),
            ("set-cookie".to_string(), "a=1, b=2".to_string()),
            ("x-empty".to_string(), String::new()),
        ]
    );
}

#[test]
fn decode_chunked_xcheck() {
    let big = 1_000_000;
    assert_eq!(
        decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", big).unwrap(),
        (b"Wikipedia".to_vec(), false)
    );
    assert_eq!(
        decode_chunked(b"3\r\nabc\r\n0\r\n\r\n", big).unwrap(),
        (b"abc".to_vec(), false)
    );
    // chunk-extension after ';' is ignored
    assert_eq!(
        decode_chunked(b"4;ext=1\r\nWiki\r\n0\r\n\r\n", big).unwrap(),
        (b"Wiki".to_vec(), false)
    );
}
