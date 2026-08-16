//! A real crawl into a segment store: durable as it goes, and resumable.
//!
//! The other segmented tests drive the store directly. This one runs the actual
//! pipeline — frontier → SSRF-checked fetch → htmlparse → index — through
//! [`websearch::segindex::crawl_into`], which is the function `websearch crawl
//! --store=segments` calls. What it checks is the two things the layout is for:
//!
//! 1. the store is durable *during* the crawl, not only after it — the
//!    generation advances slice by slice, and a reader that opens the directory
//!    mid-crawl sees a coherent partial index;
//! 2. a second crawl over the same directory **resumes**, keeping what the first
//!    one found and re-fetching nothing it already has validators for.
//!
//! Contrast `--store=blob`, where (1) is impossible by construction and (2)
//! requires `--recrawl` and rewrites the whole index to add a page.
#![cfg(feature = "net")]

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use websearch::crawler::Crawler;
use websearch::segindex::{crawl_into, SegmentedIndex};
use websearch::CrawlConfig;

const PAGES: usize = 24;

fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
    if b.len() < sep.len() {
        return None;
    }
    (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
}

async fn read_req_path(sock: &mut TcpStream) -> String {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 1024];
    while find(&buf, b"\r\n\r\n").is_none() {
        match sock.read(&mut tmp).await {
            Ok(0) | Err(_) => break,
            Ok(n) => buf.extend_from_slice(&tmp[..n]),
        }
    }
    let end = find(&buf, b"\r\n").unwrap_or(buf.len());
    String::from_utf8_lossy(&buf[..end])
        .split(' ')
        .nth(1)
        .unwrap_or("/")
        .to_string()
}

/// A site of `PAGES` pages, each linking to the next two, so the frontier has to
/// be worked through in several slices rather than drained in one.
fn body_for(path: &str) -> (&'static str, String) {
    if path == "/robots.txt" {
        return ("text/plain", "User-agent: *\nAllow: /\n".to_string());
    }
    let n: usize = path.trim_start_matches("/p").parse().unwrap_or(0);
    let links: String = (1..=2)
        .map(|k| format!("<a href=\"/p{}\">next</a> ", (n + k) % PAGES))
        .collect();
    (
        "text/html",
        format!(
            "<html><head><title>Page {n}</title>\
             <meta name=\"description\" content=\"page {n} of the corpus\"></head>\
             <body><p>lorem ipsum dolor page {n} segmented crawl corpus text \
             consectetur adipiscing elit</p>{links}</body></html>"
        ),
    )
}

fn serve_site(listener: TcpListener) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                break;
            };
            let path = read_req_path(&mut sock).await;
            let (ctype, body) = body_for(&path);
            let resp = format!(
                "HTTP/1.1 200 OK\r\nContent-Type: {ctype}\r\nContent-Length: {}\r\n\
                 ETag: \"{}\"\r\nConnection: close\r\n\r\n{body}",
                body.len(),
                path.len(),
            );
            let _ = sock.write_all(resp.as_bytes()).await;
        }
    })
}

fn crawl_config(port: u16) -> CrawlConfig {
    CrawlConfig {
        allow_hosts: vec![format!("127.0.0.1:{port}")],
        base_delay: 0.0,
        jitter: 0.0,
        // Each page links only to its two successors, so the default depth of 6
        // would stop the crawl at p12 and the site would never be covered.
        max_depth: 64,
        ..CrawlConfig::default()
    }
}

fn scratch(tag: &str) -> std::path::PathBuf {
    let d = std::env::temp_dir().join(format!("websearch-segcrawl-{tag}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&d);
    d
}

#[tokio::test]
async fn a_segmented_crawl_is_durable_as_it_goes_and_resumes() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve_site(listener);
    let dir = scratch("resume");
    let seed = format!("http://127.0.0.1:{port}/p0");

    // ---- first run: a small page budget, flushing every 4 pages -----------
    let first_docs = {
        let mut seg = SegmentedIndex::open(&dir).expect("open store");
        let mut cr = Crawler::new(crawl_config(port));
        // A resumed crawl loads first; here the store is empty, so this is the
        // empty index with change tracking already on.
        *cr.index_mut() = seg.load().expect("load");
        assert_eq!(cr.add_seeds(&[seed.as_str()]), 1);

        let stats = crawl_into(&mut cr, &mut seg, 10, 4).await.expect("crawl");
        assert!(stats.indexed >= 8, "stats={stats:?}");

        // Durable DURING the run, not after it: several commits happened, one per
        // slice, so a kill at any point would have cost at most a slice.
        assert!(
            seg.generation() >= 3,
            "only {} commit(s) for a 10-page crawl flushing every 4 — \
             the crawl is not being persisted as it goes",
            seg.generation()
        );

        // A completely independent reader of the directory sees the same thing —
        // the store is on disk, not in this process.
        let onlooker = SegmentedIndex::open(&dir)
            .expect("independent open")
            .load()
            .expect("independent load");
        assert_eq!(onlooker.doc_count(), cr.index().doc_count());
        assert_eq!(onlooker.stats(), cr.index().stats());
        assert!(onlooker.stats().links > 0, "the link graph did not persist");
        onlooker.doc_count()
    };
    assert!(first_docs >= 8, "first run indexed only {first_docs}");

    // ---- second run: same directory, resumes ------------------------------
    let mut seg = SegmentedIndex::open(&dir).expect("reopen");
    let gen_after_first = seg.generation();
    let mut cr = Crawler::new(crawl_config(port));
    *cr.index_mut() = seg.load().expect("load");
    assert_eq!(
        cr.index().doc_count(),
        first_docs,
        "the second crawl did not start from what the first one found"
    );
    cr.add_seeds(&[seed.as_str()]);
    crawl_into(&mut cr, &mut seg, 60, 4).await.expect("crawl");
    server.abort();

    let total = cr.index().doc_count();
    assert!(
        total > first_docs,
        "the resumed crawl added nothing ({first_docs} -> {total})"
    );
    assert!(seg.generation() > gen_after_first);

    // The whole site ended up indexed, once each, across the two runs.
    let final_ix = SegmentedIndex::open(&dir)
        .expect("final open")
        .load()
        .expect("final load");
    assert_eq!(final_ix.doc_count(), total);
    assert_eq!(
        final_ix.doc_count(),
        PAGES,
        "the crawl did not cover the site"
    );
    for n in 0..PAGES {
        let u = format!("http://127.0.0.1:{port}/p{n}");
        let d = final_ix
            .get_doc(&u)
            .unwrap_or_else(|| panic!("{u} is missing after two runs"));
        assert_eq!(d.title, format!("Page {n}"));
        assert!(!d.etag.is_empty(), "{u} lost its conditional-GET validator");
    }
    // Rowids are unique and contiguous: a resumed run continued the numbering
    // rather than restarting it, which is what the `Meta` record is for.
    let mut ids: Vec<i64> = final_ix.all_docs().map(|d| d.id).collect();
    ids.sort_unstable();
    assert_eq!(ids, (1..=PAGES as i64).collect::<Vec<_>>());

    let _ = std::fs::remove_dir_all(&dir);
}
