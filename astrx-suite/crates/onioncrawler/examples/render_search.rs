//! Render the no-JS search UI to stdout — a tiny demo of the serving layer that
//! needs no network (it drives `SearchServer::route` directly against an
//! in-memory store). Also doubles as documentation for standing the server up.
//!
//!     cargo run -p onioncrawler --example render_search > /tmp/onioncrawler.html
//!
//! For the live server, enable the `net` feature and call
//! `onioncrawler::serve::serve(listener, server)` on a loopback `TcpListener`.

use std::sync::{Arc, Mutex};

use onioncrawler::store::Store;
use onioncrawler::SearchServer;

fn main() {
    let mut s = Store::new();

    // A handful of placeholder hidden-service pages so the demo has something to
    // rank. (Innocuous sample content — this is a UI demo, not a real index.)
    let pages = [
        (
            "http://exampledirectoryv3id.onion/",
            "exampledirectoryv3id.onion",
            "Onion Directory — curated hidden services",
            "A curated directory of verified onion services: search engines, email \
             providers, forums and privacy tools. Updated regularly with uptime \
             checks and PGP-verified mirrors.",
        ),
        (
            "http://exampleforumv3address.onion/index",
            "exampleforumv3address.onion",
            "Privacy Forum — discussion board",
            "Community forum for privacy, operational security and self-hosting. \
             Threads on Tor relays, hardened messaging and anonymous publishing.",
        ),
        (
            "http://examplemailv3address00.onion/about",
            "examplemailv3address00.onion",
            "Onion Mail — encrypted email over Tor",
            "Encrypted email provider reachable only as a hidden service. Supports \
             PGP, disposable aliases and a no-JavaScript webmail interface.",
        ),
        (
            "http://examplewikiv3address000.onion/wiki/Main",
            "examplewikiv3address000.onion",
            "Hidden Wiki — the onion index",
            "An index wiki of onion links organised by category, with a directory \
             of search engines and privacy resources. Edited by volunteers.",
        ),
    ];

    let now = 1_700_000_000.0;
    for (i, (url, host, title, body)) in pages.iter().enumerate() {
        s.ensure_host(host, now);
        s.store_page(
            url,
            host,
            Some(title),
            Some(body),
            Some(&format!("h{i}")),
            Some(200),
            Some("text/html"),
            Some(body.len() as i64),
            now + i as f64,
            false,
            None,
            None,
            None,
        );
    }

    let server = SearchServer::new(Arc::new(Mutex::new(s)), "http://127.0.0.1:8888");
    let resp = server.route("GET", "/search?q=directory+privacy", "", None);
    print!("{}", String::from_utf8_lossy(&resp.body));
}
