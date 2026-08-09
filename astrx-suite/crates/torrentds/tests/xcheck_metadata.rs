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
    let m = parse_info(&decode_info_dict(&single).unwrap(), None, None);
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
    let m2 = parse_info(&decode_info_dict(&multi).unwrap(), None, None);
    assert_eq!(m2.name, "pack");
    assert_eq!(m2.total_size, 150);
    assert_eq!(m2.piece_length, 16384);
    assert_eq!(m2.piece_count, 2);
    assert_eq!(
        m2.files,
        vec![("a/1.bin".to_string(), 100), ("b.bin".to_string(), 50)]
    );
}
