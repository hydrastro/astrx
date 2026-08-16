//! Blob store vs segment store: the same corpus, the same queries, the same
//! answers — in the same order.
//!
//! # Why this test and not a round-trip test
//!
//! A round-trip test ("write it, read it, the fields match") would pass while the
//! engine served different results, because search output is not a projection of
//! the stored fields alone: it depends on corpus-wide statistics (document
//! frequency, average field length), on the ranking signals, and — where scores
//! tie — on the *order documents sit in*. Any of those can diverge between two
//! persistence paths while every individual document round-trips perfectly.
//!
//! So this drives one identical sequence of writes into two indexes, persists one
//! through [`Index::snapshot`] and the other through [`SegmentedIndex`], reloads
//! both, and compares what a user would actually see: whole [`SearchResponse`]s
//! (results, their order, scores, snippets and totals), stats, suggestions, and
//! the media verticals. `assert_eq!` on the response is doing the work — it
//! compares the `Vec<SearchResult>` element-wise and in order.
//!
//! The segmented side is deliberately built the awkward way: many small flushes,
//! documents rewritten so later segments shadow earlier ones, media rows cleared
//! so tombstones are in play, and a compaction at the end. If shadow order,
//! tombstones or the fold were wrong, the queries would not tie.

use websearch::index::{DocFields, Index};
use websearch::ranking::{search, SearchOpts, SearchResponse};
use websearch::segindex::SegmentedIndex;
use websearch::structured::Video;
use websearch::{htmlparse::Image, suggest::suggest};

use std::path::PathBuf;

const HOSTS: [&str; 4] = [
    "alpha.example",
    "beta.example",
    "gamma.example",
    "delta.test",
];
const LANGS: [&str; 3] = ["en", "fr", "de"];

fn scratch(tag: &str) -> PathBuf {
    use std::sync::atomic::{AtomicU64, Ordering};
    static SEQ: AtomicU64 = AtomicU64::new(0);
    let n = SEQ.fetch_add(1, Ordering::Relaxed);
    let d = std::env::temp_dir().join(format!("websearch-diff-{tag}-{}-{n}", std::process::id()));
    let _ = std::fs::remove_dir_all(&d);
    std::fs::create_dir_all(&d).expect("mkdir");
    d
}

fn url(i: usize) -> String {
    format!("http://{}/page/{i}", HOSTS[i % HOSTS.len()])
}

fn body(i: usize) -> String {
    // Enough shared vocabulary for BM25 to have opinions, enough variation for
    // the ranking to be a real ordering rather than a tie everywhere.
    format!(
        "lorem ipsum dolor sit amet consectetur document number {i} \
         {} {} searching indexing segments manifest {}",
        "adipiscing ".repeat(1 + i % 4),
        if i % 3 == 0 {
            "kitten photograph"
        } else {
            "elit sed"
        },
        "tempor incididunt ut labore ".repeat(1 + i % 2)
    )
}

/// Drive one identical write sequence into `ix`, calling `checkpoint` at the
/// points where a segmented driver would flush.
///
/// Both stores see byte-identical writes in byte-identical order; the ONLY
/// difference between the two runs is what `checkpoint` does.
fn build_corpus(ix: &mut Index, mut checkpoint: impl FnMut(&mut Index)) {
    const N: usize = 60;
    for i in 0..N {
        let u = url(i);
        let id = ix.upsert_document(
            &u,
            DocFields {
                title: &format!("Document {i} about segments"),
                description: &format!("A description of page {i}"),
                body: &body(i),
                host: HOSTS[i % HOSTS.len()],
                lang: LANGS[i % LANGS.len()],
                fetched_at: 1_700_000_000.0 + (i as f64) * 3600.0,
                http_status: 200,
                content_type: "text/html",
                simhash: i as i64 * 7919,
                ..DocFields::default()
            },
        );
        // Links, including cross-domain ones, so PageRank and host authority are
        // both non-trivial.
        let edges: Vec<(String, bool)> = (1..=3)
            .map(|k| {
                let j = (i + k * 7) % N;
                (url(j), HOSTS[j % HOSTS.len()] == HOSTS[i % HOSTS.len()])
            })
            .collect();
        ix.add_links(&u, &edges);

        if i % 3 == 0 {
            ix.replace_images(
                id,
                &u,
                HOSTS[i % HOSTS.len()],
                &[
                    Image {
                        src: format!("http://{}/img/{i}.png", HOSTS[i % HOSTS.len()]),
                        alt: format!("kitten number {i}"),
                        title: "a photograph".to_string(),
                        context: format!("photograph of a kitten on page {i}"),
                    },
                    Image {
                        src: format!("http://{}/img/{i}b.png", HOSTS[i % HOSTS.len()]),
                        alt: "another kitten".to_string(),
                        title: String::new(),
                        context: "more kitten context".to_string(),
                    },
                ],
            );
        }
        if i % 5 == 0 {
            ix.replace_videos(
                id,
                &u,
                HOSTS[i % HOSTS.len()],
                &[Video {
                    video_url: format!("http://{}/v/{i}.mp4", HOSTS[i % HOSTS.len()]),
                    title: format!("documentary about segments {i}"),
                    context: "a documentary".to_string(),
                    source: "direct".to_string(),
                    duration: Some(60 + i as i64),
                    ..Video::default()
                }],
            );
        }
        // A flush point every 7 documents: many small segments, so shadowing and
        // the k-way merge are genuinely exercised rather than nominally present.
        if i % 7 == 6 {
            checkpoint(ix);
        }
    }

    // Rewrite some documents so later segments shadow earlier ones, and revalidate
    // others so a `Doc` record is superseded by a metadata-only change.
    for i in (0..N).step_by(9) {
        let u = url(i);
        ix.upsert_document(
            &u,
            DocFields {
                title: &format!("Document {i} about segments, revised"),
                description: &format!("A revised description of page {i}"),
                body: &format!("{} revised edition", body(i)),
                host: HOSTS[i % HOSTS.len()],
                lang: LANGS[i % LANGS.len()],
                fetched_at: 1_700_500_000.0 + (i as f64),
                http_status: 200,
                content_type: "text/html",
                simhash: i as i64 * 104_729,
                ..DocFields::default()
            },
        );
    }
    checkpoint(ix);
    for i in (1..N).step_by(11) {
        ix.touch_revalidated(
            &url(i),
            1_700_600_000.0 + (i as f64),
            Some("\"etag\""),
            None,
        );
    }
    // Clear one document's media entirely: a tombstone whose rows are sitting in
    // an older segment.
    if let Some(d) = ix.get_doc(&url(0)) {
        let id = d.id;
        ix.replace_images(id, &url(0), HOSTS[0], &[]);
    }
    checkpoint(ix);

    ix.finalize();
    checkpoint(ix);
}

/// Every question a user can ask this engine, asked the same way of both stores.
fn compare(blob: &Index, segd: &Index) {
    assert_eq!(segd.doc_count(), blob.doc_count(), "document count");
    assert_eq!(segd.stats(), blob.stats(), "index statistics");

    for d in blob.all_docs() {
        assert_eq!(
            segd.get_doc(&d.url),
            Some(d),
            "document {} differs between stores",
            d.url
        );
    }
    // Ordering, not just membership: `all_docs` is the corpus iteration order
    // every search pass walks, and a difference here is a difference in tie-break.
    let blob_order: Vec<&str> = blob.all_docs().map(|d| d.url.as_str()).collect();
    let seg_order: Vec<&str> = segd.all_docs().map(|d| d.url.as_str()).collect();
    assert_eq!(seg_order, blob_order, "corpus iteration order");

    for h in HOSTS {
        assert_eq!(
            segd.host_authority(h),
            blob.host_authority(h),
            "host authority for {h}"
        );
    }

    let queries = [
        "segments",
        "lorem ipsum",
        "\"document number 5\"",
        "+segments -revised",
        "document adipiscing",
        "site:alpha.example segments",
        "-site:beta.example lorem",
        "lang:fr document",
        "intitle:revised",
        "host:gamma.example",
        "after:2023-11-01 segments",
        "boost:alpha.example segments",
        "penalize:delta.test segments",
        "kitten photograph",
        "manifest",
        "nothingmatchesthisatall",
    ];
    let mut nonempty = 0usize;
    for q in queries {
        for sort in ["relevance", "fresh"] {
            for page in 1..=3 {
                let opts = SearchOpts {
                    page,
                    page_size: 7,
                    now: 1_700_700_000.0,
                    sort: sort.to_string(),
                    only_files: false,
                };
                let a: SearchResponse = search(blob, q, &opts);
                let b: SearchResponse = search(segd, q, &opts);
                assert_eq!(
                    b, a,
                    "query {q:?} sort={sort} page={page} differs between stores"
                );
                nonempty += usize::from(!a.results.is_empty());
            }
        }
    }
    assert!(
        nonempty > 40,
        "only {nonempty} of the query/sort/page combinations returned anything — \
         the comparison is not exercising the ranker"
    );

    // The typeahead reads the vocabulary, which is derived from document text —
    // a shadowing bug that left an old body visible would show up here even if
    // the scores happened to tie.
    let popular = vec!["segments".to_string()];
    for prefix in ["seg", "lor", "doc", "kitt", "revi", "zzz"] {
        assert_eq!(
            suggest(segd, prefix, &popular, 8),
            suggest(blob, prefix, &popular, 8),
            "suggest({prefix:?}) differs"
        );
        assert_eq!(
            segd.vocab_prefix(prefix, 12),
            blob.vocab_prefix(prefix, 12),
            "vocab_prefix({prefix:?}) differs"
        );
    }

    // Media verticals: same rows, same relevance order.
    for q in ["kitten", "photograph", "kitten photograph", "documentary"] {
        assert_eq!(
            segd.image_search(q, 20),
            blob.image_search(q, 20),
            "image_search({q:?}) differs"
        );
        assert_eq!(
            segd.video_search(q, 20),
            blob.video_search(q, 20),
            "video_search({q:?}) differs"
        );
    }
}

#[test]
fn a_segmented_store_answers_every_query_exactly_as_the_blob_does() {
    let dir = scratch("differential");

    // Blob: build, finalise, snapshot, restore. The checkpoints do nothing —
    // a blob store has no notion of a partial save.
    let mut blob_ix = Index::new();
    build_corpus(&mut blob_ix, |_| {});
    let blob = Index::restore(&blob_ix.snapshot()).expect("blob restore");

    // Segments: the same writes, but committed as they happen.
    let mut seg = SegmentedIndex::open(&dir).expect("open store");
    let mut seg_ix = Index::new();
    seg_ix.track_changes(true);
    let mut flushes = 0usize;
    {
        let seg = &mut seg;
        build_corpus(&mut seg_ix, |ix| {
            seg.flush(ix).expect("flush");
            flushes += 1;
        });
    }
    assert!(
        flushes >= 10,
        "only {flushes} flushes — not enough segments"
    );
    assert!(
        seg.segment_count() > 1,
        "the store collapsed to one segment; shadowing is not being tested"
    );
    let segd = seg.load().expect("load");

    compare(&blob, &segd);

    // And again after a fold: compaction must be invisible from the outside.
    let outcome = seg
        .maybe_compact(1)
        .expect("compact")
        .expect("there is something to fold");
    assert!(
        outcome.records_out < outcome.records_in,
        "the fold reclaimed nothing ({} in, {} out) — shadowed versions and \
         tombstones should have gone",
        outcome.records_in,
        outcome.records_out
    );
    assert_eq!(
        seg.segment_count(),
        1,
        "a fold should leave exactly one segment"
    );
    let folded = seg.load().expect("load after fold");
    compare(&blob, &folded);

    // And after a reopen from cold, which is the case an operator actually hits.
    let reopened = SegmentedIndex::open(&dir)
        .expect("reopen")
        .load()
        .expect("load");
    compare(&blob, &reopened);

    let _ = std::fs::remove_dir_all(&dir);
}

#[test]
fn media_row_order_is_canonicalised_by_the_segmented_store() {
    // The one documented behavioural difference between the two stores, pinned so
    // it is a decision rather than a surprise. `replace_images` moves a
    // document's rows to the END of the row vector; the blob preserves that, the
    // segmented store groups by document id. It can only matter where two media
    // rows score EXACTLY equal, because that is the only time row position is
    // consulted (`media_search_indices` sorts stably by score).
    let dir = scratch("mediaorder");
    let mut seg = SegmentedIndex::open(&dir).expect("open");
    let mut ix = Index::new();
    ix.track_changes(true);

    let mut ids = Vec::new();
    for i in 0..3 {
        let u = format!("http://x/{i}");
        let id = ix.upsert_document(
            &u,
            DocFields {
                title: "T",
                body: "body",
                host: "x",
                fetched_at: 100.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        ids.push((id, u));
    }
    for (id, u) in &ids {
        ix.replace_images(
            *id,
            u,
            "x",
            &[Image {
                src: format!("{u}/i.png"),
                alt: "identical alt".to_string(),
                title: String::new(),
                context: "identical context".to_string(),
            }],
        );
    }
    // Re-replace the FIRST document's rows: in the blob its group now sits last.
    let (id0, u0) = ids[0].clone();
    ix.replace_images(
        id0,
        &u0,
        "x",
        &[Image {
            src: format!("{u0}/i.png"),
            alt: "identical alt".to_string(),
            title: String::new(),
            context: "identical context".to_string(),
        }],
    );
    seg.flush(&mut ix).expect("flush");

    let blob = Index::restore(&ix.snapshot()).expect("restore");
    let segd = seg.load().expect("load");

    let blob_srcs: Vec<String> = blob
        .image_search("identical", 10)
        .into_iter()
        .map(|r| r.src)
        .collect();
    let seg_srcs: Vec<String> = segd
        .image_search("identical", 10)
        .into_iter()
        .map(|r| r.src)
        .collect();

    // Same rows either way — nothing is lost or duplicated.
    let (mut a, mut b) = (blob_srcs.clone(), seg_srcs.clone());
    a.sort();
    b.sort();
    assert_eq!(a, b, "the two stores must hold the same media rows");

    // The blob puts the re-replaced document last; the segmented store puts it
    // first, because it groups by document id.
    assert_eq!(
        blob_srcs.last().map(String::as_str),
        Some("http://x/0/i.png")
    );
    assert_eq!(
        seg_srcs.first().map(String::as_str),
        Some("http://x/0/i.png")
    );
    let _ = std::fs::remove_dir_all(&dir);
}
