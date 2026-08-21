//! Loopback round-trip of the federation aggregator (net feature): stand up two
//! mock shard `/api/search` servers with canned JSON, fan a query out through the
//! real [`websearch::federated_search`], and assert (1) the cross-host SimHash
//! near-duplicate is collapsed while the combined results come back ranked and
//! `partial=false`, and (2) when one shard is down the response is `partial=true`
//! and only the live shard's results survive.
#![cfg(feature = "net")]

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;
use websearch::{federated_search, FederatedOpts};

fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
    if b.len() < sep.len() {
        return None;
    }
    (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
}

/// A mock shard that answers every request with the same canned JSON body until
/// aborted.
fn serve_shard(listener: TcpListener, body: &'static str) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                break;
            };
            // Drain the request head so the client's write completes.
            let mut buf = Vec::new();
            let mut tmp = [0u8; 1024];
            while find(&buf, b"\r\n\r\n").is_none() {
                match sock.read(&mut tmp).await {
                    Ok(0) | Err(_) => break,
                    Ok(n) => buf.extend_from_slice(&tmp[..n]),
                }
            }
            let resp = format!(
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\n\
                 Connection: close\r\n\r\n{body}",
                body.len()
            );
            let _ = sock.write_all(resp.as_bytes()).await;
        }
    })
}

/// The down shard's address: `127.0.0.1` port 0.
///
/// This used to be a scavenged ephemeral port — bind one, drop the listener,
/// assume nothing takes it back. Something can: the competing binder is not some
/// other test binary, since `cargo test` runs those one at a time, but the
/// sibling test in *this* file. libtest runs up to `available_parallelism()`
/// tests at once — 2 on the 2-core CI runner, and this binary holds exactly 2 —
/// so `merges_ranks_and_collapses_cross_host_mirror` is binding its own pair of
/// `127.0.0.1:0` shards at the same instant, out of the same ephemeral range,
/// and either of them could be handed the number this one had just released.
/// Any other process on the machine can take it too. If one did, the "down"
/// shard would be up. The test mostly survived that (a squatter's reply is not
/// federation JSON, so the shard still counts as failed) but not for the reason
/// it claims to be testing.
///
/// Port 0 removes the race rather than narrowing it: it is not a usable
/// destination port, and no process can come to occupy it, because `bind(0)`
/// means "assign me an ephemeral port" and never yields port 0 itself. The
/// kernel refuses the connect immediately — measured here as
/// `connect:Connection refused (os error 111)` in ~0.3 ms — so the shard still
/// fails the way the assertions below describe, at `connect`, having gone
/// through URL parse and resolution first, and costs no wall clock waiting on
/// the 2 s fetch timeout.
const DEAD_SHARD: &str = "http://127.0.0.1:0";

// Shard A: one high-scoring result on host `a.example`. Its SimHash and shard
// B's `b.example/y` fingerprint differ in only 2 bits (Hamming 2 <= 3), so they
// are cross-host near-duplicates — but the two 64-bit integers are far apart in
// magnitude, so this ONLY collapses when the simhash survives the JSON round-trip
// exactly (an `f64` would round them into a Hamming-4 pair and miss the mirror).
const SHARD_A: &str = concat!(
    "{\"query\":\"q\",\"total\":1,\"results\":[",
    "{\"url\":\"http://a.example/x\",\"title\":\"Alpha\",\"host\":\"a.example\",",
    "\"snippet_html\":\"<b>alpha</b>\",\"score\":9.5,\"fetched_at\":1700000000.0,",
    "\"lang\":\"en\",\"simhash\":6531626834570772771}]}"
);

// Shard B: the mirror `b.example/y` (lower score -> should be dropped) and a
// distinct `b.example/z` (kept).
const SHARD_B: &str = concat!(
    "{\"query\":\"q\",\"total\":2,\"results\":[",
    "{\"url\":\"http://b.example/y\",\"title\":\"Beta mirror\",\"host\":\"b.example\",",
    "\"snippet_html\":\"beta\",\"score\":8.0,\"fetched_at\":1700000001.0,",
    "\"lang\":\"en\",\"simhash\":-385902193070309085},",
    "{\"url\":\"http://b.example/z\",\"title\":\"Gamma\",\"host\":\"b.example\",",
    "\"snippet_html\":\"gamma\",\"score\":7.0,\"fetched_at\":1700000002.0,",
    "\"lang\":\"en\",\"simhash\":555}]}"
);

#[tokio::test]
async fn merges_ranks_and_collapses_cross_host_mirror() {
    let la = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let lb = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let (pa, pb) = (
        la.local_addr().unwrap().port(),
        lb.local_addr().unwrap().port(),
    );
    let sa = serve_shard(la, SHARD_A);
    let sb = serve_shard(lb, SHARD_B);

    let bases = vec![
        format!("http://127.0.0.1:{pa}"),
        format!("http://127.0.0.1:{pb}/"), // trailing slash -> normalised away
    ];
    let fed = federated_search(&bases, "alpha", &FederatedOpts::default()).await;
    sa.abort();
    sb.abort();

    assert!(!fed.partial, "all shards responded: {fed:?}");
    assert_eq!(fed.shard_count, 2);
    assert_eq!(fed.ok_count, 2);
    // The mirror `b.example/y` is collapsed; the two survivors are ranked by score.
    let urls: Vec<&str> = fed.results.iter().map(|r| r.url.as_str()).collect();
    assert_eq!(
        urls,
        ["http://a.example/x", "http://b.example/z"],
        "{fed:?}"
    );
    assert!(
        !fed.results.iter().any(|r| r.url == "http://b.example/y"),
        "cross-host mirror was not collapsed: {fed:?}"
    );
    // total = min(summed shard totals (1+2=3), merged candidate count (2)).
    assert_eq!(fed.total, 2);
}

#[tokio::test]
async fn partial_when_a_shard_is_down() {
    let la = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let pa = la.local_addr().unwrap().port();
    let sa = serve_shard(la, SHARD_A);

    let bases = vec![format!("http://127.0.0.1:{pa}"), DEAD_SHARD.to_string()];
    let opts = FederatedOpts {
        timeout: std::time::Duration::from_secs(2),
        ..FederatedOpts::default()
    };
    let fed = federated_search(&bases, "alpha", &opts).await;
    sa.abort();

    assert!(fed.partial, "a shard was down: {fed:?}");
    assert_eq!(fed.shard_count, 2);
    assert_eq!(fed.ok_count, 1);
    let urls: Vec<&str> = fed.results.iter().map(|r| r.url.as_str()).collect();
    assert_eq!(urls, ["http://a.example/x"], "only the live shard: {fed:?}");
}
