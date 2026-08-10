//! Cross-check: the Rust peer-wire / ut_metadata builders and `parse_info` match
//! the Python reference (`legacy-python/torrentds/metadata.py`) exactly — so the
//! Rust fetcher speaks a byte-identical BEP-3/9/10 to real peers. Golden hex and
//! parsed fields were emitted by driving the Python `metadata` module directly.

use torrentds::metadata::{
    build_ext_handshake, build_handshake, build_message, build_ut_metadata_data,
    build_ut_metadata_reject, build_ut_metadata_request, decode_info_dict, parse_info,
};
use torrentds::{encode, Ben};

fn to_hex(bytes: &[u8]) -> String {
    let mut s = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        s.push_str(&format!("{b:02x}"));
    }
    s
}

fn dict(pairs: Vec<(&[u8], Ben)>) -> std::collections::BTreeMap<Vec<u8>, Ben> {
    pairs.into_iter().map(|(k, v)| (k.to_vec(), v)).collect()
}

#[test]
fn wire_builders_match_python() {
    let ih = [0x11u8; 20];
    let pid = [0x22u8; 20];

    assert_eq!(to_hex(&build_handshake(&ih, &pid, true)), "13426974546f7272656e742070726f746f636f6c000000000010000011111111111111111111111111111111111111112222222222222222222222222222222222222222");
    assert_eq!(to_hex(&build_handshake(&ih, &pid, false)), "13426974546f7272656e742070726f746f636f6c000000000000000011111111111111111111111111111111111111112222222222222222222222222222222222222222");
    assert_eq!(to_hex(&build_message(5, b"hello")), "000000060568656c6c6f");
    assert_eq!(
        to_hex(&build_ext_handshake(None, 1)),
        "0000001a140064313a6d6431313a75745f6d657461646174616931656565"
    );
    assert_eq!(to_hex(&build_ext_handshake(Some(1234), 2)), "00000030140064313a6d6431313a75745f6d657461646174616932656531333a6d657461646174615f73697a6569313233346565");
    assert_eq!(
        to_hex(&build_ut_metadata_request(0, 1)),
        "0000001b140164383a6d73675f74797065693065353a706965636569306565"
    );
    assert_eq!(to_hex(&build_ut_metadata_data(2, 1234, b"PIECEDATA", 3)), "00000037140364383a6d73675f74797065693165353a706965636569326531303a746f74616c5f73697a6569313233346565504945434544415441");
    assert_eq!(
        to_hex(&build_ut_metadata_reject(5, 1)),
        "0000001b140164383a6d73675f74797065693265353a706965636569356565"
    );
}

#[test]
fn parse_info_matches_python() {
    // single-file
    let single = encode(&Ben::Dict(dict(vec![
        (b"name", Ben::Bytes(b"movie.mkv".to_vec())),
        (b"length", Ben::Int(5000)),
        (b"piece length", Ben::Int(16384)),
        (b"pieces", Ben::Bytes(vec![0u8; 20])),
    ])));
    let m = parse_info(&decode_info_dict(&single).unwrap(), None, None).unwrap();
    assert_eq!(m.name, "movie.mkv");
    assert_eq!(m.total_size, 5000);
    assert_eq!(m.piece_length, 16384);
    assert_eq!(m.piece_count, 1);
    assert_eq!(m.files, vec![("movie.mkv".to_string(), 5000)]);

    // multi-file
    let mkfile = |parts: &[&[u8]], len: i64| {
        Ben::Dict(dict(vec![
            (b"length", Ben::Int(len)),
            (
                b"path",
                Ben::List(parts.iter().map(|p| Ben::Bytes(p.to_vec())).collect()),
            ),
        ]))
    };
    let multi = encode(&Ben::Dict(dict(vec![
        (b"name", Ben::Bytes(b"pack".to_vec())),
        (
            b"files",
            Ben::List(vec![
                mkfile(&[b"a", b"1.bin"], 100),
                mkfile(&[b"b.bin"], 50),
            ]),
        ),
        (b"piece length", Ben::Int(16384)),
        (b"pieces", Ben::Bytes(vec![0u8; 40])),
    ])));
    let m2 = parse_info(&decode_info_dict(&multi).unwrap(), None, None).unwrap();
    assert_eq!(m2.name, "pack");
    assert_eq!(m2.total_size, 150);
    assert_eq!(m2.piece_length, 16384);
    assert_eq!(m2.piece_count, 2);
    assert_eq!(
        m2.files,
        vec![("a/1.bin".to_string(), 100), ("b.bin".to_string(), 50)]
    );
}

// BEP-52 v2 / hybrid: build the same info-dict as the Python golden and check the
// SHA-256 infohash, the truncated/v1 primary, files, piece math and content id.
fn v2_leaf(length: i64, root: [u8; 32]) -> Ben {
    Ben::Dict(dict(vec![(
        b"",
        Ben::Dict(dict(vec![
            (b"length", Ben::Int(length)),
            (b"pieces root", Ben::Bytes(root.to_vec())),
        ])),
    )]))
}

fn v2_info(hybrid: bool) -> Vec<u8> {
    let file_tree = dict(vec![
        (b"a.txt", v2_leaf(100, [0u8; 32])),
        (
            b"sub",
            Ben::Dict(dict(vec![(b"b.bin", v2_leaf(200, [0x11u8; 32]))])),
        ),
    ]);
    let mut pairs: Vec<(&[u8], Ben)> = vec![
        (b"file tree", Ben::Dict(file_tree)),
        (b"meta version", Ben::Int(2)),
        (b"name", Ben::Bytes(b"mydir".to_vec())),
        (b"piece length", Ben::Int(16384)),
    ];
    if hybrid {
        pairs.push((b"pieces", Ben::Bytes(vec![0u8; 20])));
    }
    encode(&Ben::Dict(dict(pairs)))
}

#[test]
fn parse_v2_and_hybrid_match_python() {
    let files = vec![("a.txt".to_string(), 100), ("sub/b.bin".to_string(), 200)];
    let content_id = "b495297305bc7100ec5cf953b5694a321fcb980fda370a0fcc4f8397d4f71f75";

    let v2 = parse_info(&decode_info_dict(&v2_info(false)).unwrap(), None, None).unwrap();
    assert_eq!(v2.version, "v2");
    assert_eq!(
        to_hex(&v2.info_hash_v2.unwrap()),
        "3cfb8ef1263a1cdf854f4ea8c01ba5f14dae758dcef70b1f6a3bbb4ed1035727"
    );
    assert_eq!(
        to_hex(&v2.info_hash),
        "3cfb8ef1263a1cdf854f4ea8c01ba5f14dae758d"
    ); // truncated
    assert_eq!(v2.total_size, 300);
    assert_eq!(v2.piece_count, 1);
    assert_eq!(v2.files, files);
    assert_eq!(to_hex(&v2.content_id.unwrap()), content_id);

    let hy = parse_info(&decode_info_dict(&v2_info(true)).unwrap(), None, None).unwrap();
    assert_eq!(hy.version, "hybrid");
    assert_eq!(
        to_hex(&hy.info_hash_v2.unwrap()),
        "909675acc9064e42a3cee2a3e0c41c097072f03a047ba4f5f22ab2040a9f2da4"
    );
    assert_eq!(
        to_hex(&hy.info_hash),
        "3693f61bfc97855b89c3de10ff67e0d642e41d0b"
    ); // v1 sha1
    assert_eq!(hy.files, files);
    assert_eq!(to_hex(&hy.content_id.unwrap()), content_id);
}
