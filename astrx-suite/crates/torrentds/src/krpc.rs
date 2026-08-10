//! KRPC (BEP-5) message codec — the bencoded RPC of the Mainline DHT.
//!
//! Every message is a bencoded dict with `t` (transaction id, echoed in the
//! reply) and `y` (type: `q` query / `r` response / `e` error). Queries carry
//! `q` (method) + `a` (args); responses carry `r`; errors carry `e = [code,msg]`.
//!
//! This is the pure, transport-free codec: it turns hostile datagrams from
//! anonymous peers into a typed [`KrpcMessage`] (or a [`ParseError`] — it never
//! panics). The async UDP transport (with transaction matching and off-path
//! response-injection defence) layers on top separately.

use crate::bencode::{decode, encode, Ben, BencodeError};
use std::collections::BTreeMap;

/// Standard KRPC error codes (BEP-5).
pub const ERR_GENERIC: i64 = 201;
pub const ERR_SERVER: i64 = 202;
pub const ERR_PROTOCOL: i64 = 203;
pub const ERR_METHOD_UNKNOWN: i64 = 204;

/// A bencode dict (the `a` args and `r` response payloads).
pub type Dict = BTreeMap<Vec<u8>, Ben>;

/// A parsed KRPC message. `txn` and `method` stay raw bytes (they are opaque /
/// latin1 on the wire); callers compare `method` against byte literals.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum KrpcMessage {
    Query {
        txn: Vec<u8>,
        method: Vec<u8>,
        args: Dict,
    },
    Response {
        txn: Vec<u8>,
        response: Dict,
    },
    Error {
        txn: Vec<u8>,
        code: i64,
        message: String,
    },
}

/// A KRPC protocol error a query handler can raise to reply with `y=e`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct KrpcError {
    pub code: i64,
    pub message: String,
}

impl std::fmt::Display for KrpcError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "krpc error {}: {}", self.code, self.message)
    }
}
impl std::error::Error for KrpcError {}

/// A failure to parse a datagram as a structurally valid KRPC message.
#[derive(Debug, PartialEq, Eq)]
pub struct ParseError(pub String);

impl ParseError {
    /// The human-readable failure message.
    #[must_use]
    pub fn message(&self) -> &str {
        &self.0
    }
}

impl std::fmt::Display for ParseError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "krpc: {}", self.0)
    }
}
impl std::error::Error for ParseError {}
impl From<BencodeError> for ParseError {
    fn from(e: BencodeError) -> Self {
        ParseError(format!("bencode: {}", e.message()))
    }
}

fn parse_err<T>(msg: impl Into<String>) -> Result<T, ParseError> {
    Err(ParseError(msg.into()))
}

/// Encode a query: `{t, y:q, q:method, a:args}`.
pub fn encode_query(txn: &[u8], method: &[u8], args: Dict) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"t".to_vec(), Ben::Bytes(txn.to_vec()));
    d.insert(b"y".to_vec(), Ben::Bytes(b"q".to_vec()));
    d.insert(b"q".to_vec(), Ben::Bytes(method.to_vec()));
    d.insert(b"a".to_vec(), Ben::Dict(args));
    encode(&Ben::Dict(d))
}

/// Encode a response: `{t, y:r, r:response}`.
pub fn encode_response(txn: &[u8], response: Dict) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"t".to_vec(), Ben::Bytes(txn.to_vec()));
    d.insert(b"y".to_vec(), Ben::Bytes(b"r".to_vec()));
    d.insert(b"r".to_vec(), Ben::Dict(response));
    encode(&Ben::Dict(d))
}

/// Encode an error: `{t, y:e, e:[code, message]}`.
pub fn encode_error(txn: &[u8], code: i64, message: &str) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"t".to_vec(), Ben::Bytes(txn.to_vec()));
    d.insert(b"y".to_vec(), Ben::Bytes(b"e".to_vec()));
    d.insert(
        b"e".to_vec(),
        Ben::List(vec![
            Ben::Int(code),
            Ben::Bytes(message.as_bytes().to_vec()),
        ]),
    );
    encode(&Ben::Dict(d))
}

/// Parse a datagram into a [`KrpcMessage`]. Returns `Err` for malformed bencode
/// or structurally invalid KRPC; never panics on hostile input.
pub fn parse_message(data: &[u8]) -> Result<KrpcMessage, ParseError> {
    let Ben::Dict(d) = decode(data)? else {
        return parse_err("KRPC message must be a dict");
    };
    let txn = match d.get(b"t".as_slice()) {
        Some(Ben::Bytes(t)) => t.clone(),
        _ => return parse_err("missing transaction id"),
    };
    let y = match d.get(b"y".as_slice()) {
        Some(Ben::Bytes(y)) => y.clone(),
        _ => return parse_err("missing message type"),
    };
    match y.as_slice() {
        b"q" => {
            let method = match d.get(b"q".as_slice()) {
                Some(Ben::Bytes(m)) => m.clone(),
                _ => return parse_err("malformed query"),
            };
            let args = match d.get(b"a".as_slice()) {
                Some(Ben::Dict(a)) => a.clone(),
                _ => return parse_err("malformed query"),
            };
            Ok(KrpcMessage::Query { txn, method, args })
        }
        b"r" => {
            let response = match d.get(b"r".as_slice()) {
                Some(Ben::Dict(r)) => r.clone(),
                _ => return parse_err("malformed response"),
            };
            Ok(KrpcMessage::Response { txn, response })
        }
        b"e" => {
            let list = match d.get(b"e".as_slice()) {
                Some(Ben::List(l)) if l.len() >= 2 => l,
                _ => return parse_err("malformed error"),
            };
            let code = match &list[0] {
                Ben::Int(c) => *c,
                _ => return parse_err("malformed error code"),
            };
            let message = match &list[1] {
                Ben::Bytes(b) => String::from_utf8_lossy(b).into_owned(),
                Ben::Int(n) => n.to_string(),
                _ => String::new(),
            };
            Ok(KrpcMessage::Error { txn, code, message })
        }
        other => parse_err(format!(
            "unknown message type {:?}",
            String::from_utf8_lossy(other)
        )),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn dict(pairs: &[(&[u8], Ben)]) -> Dict {
        pairs.iter().map(|(k, v)| (k.to_vec(), v.clone())).collect()
    }

    #[test]
    fn query_round_trip() {
        let args = dict(&[(b"id", Ben::Bytes(vec![0xAB; 20]))]);
        let wire = encode_query(b"aa", b"ping", args.clone());
        match parse_message(&wire).unwrap() {
            KrpcMessage::Query {
                txn,
                method,
                args: a,
            } => {
                assert_eq!(txn, b"aa");
                assert_eq!(method, b"ping");
                assert_eq!(a, args);
            }
            other => panic!("expected query, got {other:?}"),
        }
    }

    #[test]
    fn response_round_trip() {
        let r = dict(&[(b"id", Ben::Bytes(vec![1; 20]))]);
        let wire = encode_response(b"z9", r.clone());
        assert_eq!(
            parse_message(&wire).unwrap(),
            KrpcMessage::Response {
                txn: b"z9".to_vec(),
                response: r
            }
        );
    }

    #[test]
    fn error_round_trip() {
        let wire = encode_error(b"t1", ERR_METHOD_UNKNOWN, "Method Unknown");
        assert_eq!(
            parse_message(&wire).unwrap(),
            KrpcMessage::Error {
                txn: b"t1".to_vec(),
                code: 204,
                message: "Method Unknown".into(),
            }
        );
    }

    #[test]
    fn rejects_structurally_invalid() {
        // not a dict
        assert!(parse_message(b"li1ee").is_err());
        // missing txn
        assert!(parse_message(&encode(&Ben::Dict(dict(&[(
            b"y",
            Ben::Bytes(b"q".to_vec())
        )]))))
        .is_err());
        // query missing args
        let q = dict(&[
            (b"t", Ben::Bytes(b"aa".to_vec())),
            (b"y", Ben::Bytes(b"q".to_vec())),
            (b"q", Ben::Bytes(b"ping".to_vec())),
        ]);
        assert!(parse_message(&encode(&Ben::Dict(q))).is_err());
        // error list too short
        let e = dict(&[
            (b"t", Ben::Bytes(b"aa".to_vec())),
            (b"y", Ben::Bytes(b"e".to_vec())),
            (b"e", Ben::List(vec![Ben::Int(201)])),
        ]);
        assert!(parse_message(&encode(&Ben::Dict(e))).is_err());
        // unknown type
        let u = dict(&[
            (b"t", Ben::Bytes(b"aa".to_vec())),
            (b"y", Ben::Bytes(b"x".to_vec())),
        ]);
        assert!(parse_message(&encode(&Ben::Dict(u))).is_err());
    }

    #[test]
    fn hostile_bytes_never_panic() {
        // a sampling of malformed datagrams — all must return Err, none panic.
        for bad in [
            &b""[..],
            b"d",
            b"de",
            b"d1:ti1ee",        // txn is an int, not bytes
            b"d1:t2:aa1:y1:qe", // query missing q/a
            b"d1:t2:aa1:y1:re", // response missing r
        ] {
            let _ = parse_message(bad); // must not panic
        }
    }
}
