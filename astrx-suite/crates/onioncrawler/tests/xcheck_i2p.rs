//! Cross-check: the Rust I2P proxy encoders match the Python reference
//! (`legacy-python/onioncrawler/i2p.py`) — the HTTP `CONNECT` request (for https
//! eepsites) and the absolute-form proxy GET target (for plain-http eepsites,
//! with default-port omission and empty-path → `/`). Expected values were emitted
//! by driving the Python module.

use onioncrawler::i2p::{build_http_connect, build_proxy_get_target};

#[test]
fn i2p_encoders_xcheck() {
    assert_eq!(
        build_http_connect("stats.i2p", 443).unwrap(),
        b"CONNECT stats.i2p:443 HTTP/1.1\r\nHost: stats.i2p:443\r\nUser-Agent: OnionCrawler-I2P/1.0\r\nProxy-Connection: close\r\n\r\n"
    );
    assert_eq!(
        build_proxy_get_target("http", "stats.i2p", None, "/path"),
        "http://stats.i2p/path"
    );
    assert_eq!(
        build_proxy_get_target("http", "stats.i2p", Some(8080), "/x"),
        "http://stats.i2p:8080/x"
    );
    assert_eq!(
        build_proxy_get_target("http", "stats.i2p", Some(80), "/"),
        "http://stats.i2p/"
    );
    assert_eq!(
        build_proxy_get_target("https", "site.i2p", Some(443), ""),
        "https://site.i2p/"
    );
}
