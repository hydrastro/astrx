#![no_main]
//! Fuzz the KRPC (BEP-5) message parser — raw UDP datagrams from anonymous DHT
//! peers. `parse_message` must turn any bytes into a typed message or a
//! `ParseError`, and must never panic.
//!
//! On a successful parse we additionally round-trip through the reply encoders
//! (`encode_query` / `encode_response` / `encode_error`) and re-parse. Because
//! `parse_message` ignores unknown dict keys, the fixed point is the TYPED value
//! (not the original bytes): `parse(encode(parse(x))) == parse(x)`. This exercises
//! the encode path and pins message stability.

use libfuzzer_sys::fuzz_target;
use torrentds::krpc::{encode_error, encode_query, encode_response, parse_message, KrpcMessage};

fuzz_target!(|data: &[u8]| {
    let Ok(msg) = parse_message(data) else {
        return; // malformed datagram — the common, must-not-panic case
    };

    let rewire = match &msg {
        KrpcMessage::Query { txn, method, args } => encode_query(txn, method, args.clone()),
        KrpcMessage::Response { txn, response } => encode_response(txn, response.clone()),
        KrpcMessage::Error { txn, code, message } => encode_error(txn, *code, message),
    };

    match parse_message(&rewire) {
        Ok(reparsed) => assert_eq!(
            reparsed, msg,
            "re-encoding a parsed KRPC message must round-trip to an equal message"
        ),
        Err(e) => panic!("a freshly re-encoded KRPC message failed to parse: {e}"),
    }
});
