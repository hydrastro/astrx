//! Pure BEP-3 / BEP-10 / BEP-9 peer-wire framing: the handshake, length-prefixed
//! messages, the extended handshake and the ut_metadata request/data/reject
//! builders, plus the ut_metadata piece math. No I/O and no third-party deps —
//! every byte here is cross-checked against the Python reference.

use super::{
    merr, MetadataError, BT_PROTOCOL, EXT_MSG_ID, HANDSHAKE_LEN, PIECE_SIZE, UT_DATA, UT_REJECT,
    UT_REQUEST,
};
use crate::bencode::{encode, Ben};
use crate::krpc::Dict;

// --- BEP-3 handshake -------------------------------------------------------

/// Build the 68-byte peer handshake; sets the BEP-10 extension bit when asked.
#[must_use]
pub fn build_handshake(info_hash: &[u8; 20], peer_id: &[u8; 20], extensions: bool) -> Vec<u8> {
    let mut reserved = [0u8; 8];
    if extensions {
        reserved[5] |= 0x10; // BEP-10 extension-protocol bit
    }
    let mut out = Vec::with_capacity(HANDSHAKE_LEN);
    out.push(BT_PROTOCOL.len() as u8);
    out.extend_from_slice(BT_PROTOCOL);
    out.extend_from_slice(&reserved);
    out.extend_from_slice(info_hash);
    out.extend_from_slice(peer_id);
    out
}

/// `(reserved, info_hash, peer_id)` decoded from a peer handshake.
pub type Handshake = ([u8; 8], [u8; 20], [u8; 20]);

/// Parse a handshake into `(reserved, info_hash, peer_id)`.
pub fn parse_handshake(data: &[u8]) -> Result<Handshake, MetadataError> {
    if data.len() != HANDSHAKE_LEN {
        return merr("handshake must be 68 bytes");
    }
    if data[0] as usize != BT_PROTOCOL.len() || &data[1..20] != BT_PROTOCOL {
        return merr("not a BitTorrent handshake");
    }
    let mut reserved = [0u8; 8];
    let mut info_hash = [0u8; 20];
    let mut peer_id = [0u8; 20];
    reserved.copy_from_slice(&data[20..28]);
    info_hash.copy_from_slice(&data[28..48]);
    peer_id.copy_from_slice(&data[48..68]);
    Ok((reserved, info_hash, peer_id))
}

/// True if the reserved bytes advertise BEP-10 extension support.
#[must_use]
pub fn supports_extensions(reserved: &[u8; 8]) -> bool {
    reserved[5] & 0x10 != 0
}

// --- BEP-3 framing + BEP-10 extended messages ------------------------------

/// Length-prefixed peer message: `<u32 len><msg_id><payload>`.
#[must_use]
pub fn build_message(msg_id: u8, payload: &[u8]) -> Vec<u8> {
    let len = 1 + payload.len();
    let mut out = Vec::with_capacity(4 + len);
    out.extend_from_slice(&(len as u32).to_be_bytes());
    out.push(msg_id);
    out.extend_from_slice(payload);
    out
}

/// An extended (BEP-10) message: `msg_id = 20`, first payload byte is `ext_id`.
#[must_use]
pub fn build_ext_message(ext_id: u8, payload: &[u8]) -> Vec<u8> {
    let mut body = Vec::with_capacity(1 + payload.len());
    body.push(ext_id);
    body.extend_from_slice(payload);
    build_message(EXT_MSG_ID, &body)
}

/// The extended handshake (ext id 0). `ut_metadata_id` is the id we ask the peer
/// to use for ut_metadata sent to us; `metadata_size` is set by whoever holds it.
#[must_use]
pub fn build_ext_handshake(metadata_size: Option<i64>, ut_metadata_id: i64) -> Vec<u8> {
    let mut m = Dict::new();
    m.insert(b"ut_metadata".to_vec(), Ben::Int(ut_metadata_id));
    let mut d = Dict::new();
    d.insert(b"m".to_vec(), Ben::Dict(m));
    if let Some(n) = metadata_size {
        d.insert(b"metadata_size".to_vec(), Ben::Int(n));
    }
    build_ext_message(0, &encode(&Ben::Dict(d)))
}

/// A ut_metadata request for `piece` (BEP-9 `msg_type = 0`).
#[must_use]
pub fn build_ut_metadata_request(piece: i64, ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_REQUEST));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    build_ext_message(ext_id, &encode(&Ben::Dict(d)))
}

/// A ut_metadata data message (BEP-9 `msg_type = 1`): the bencoded header then the
/// raw piece bytes.
#[must_use]
pub fn build_ut_metadata_data(piece: i64, total_size: i64, data: &[u8], ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_DATA));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    d.insert(b"total_size".to_vec(), Ben::Int(total_size));
    let mut payload = encode(&Ben::Dict(d));
    payload.extend_from_slice(data);
    build_ext_message(ext_id, &payload)
}

/// A ut_metadata reject message (BEP-9 `msg_type = 2`).
#[must_use]
pub fn build_ut_metadata_reject(piece: i64, ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_REJECT));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    build_ext_message(ext_id, &encode(&Ben::Dict(d)))
}

// --- ut_metadata piece math ------------------------------------------------

/// Number of 16 KiB pieces an info-dict of `metadata_size` bytes spans.
#[must_use]
pub fn num_pieces(metadata_size: usize) -> usize {
    metadata_size.div_ceil(PIECE_SIZE)
}

/// Exact byte length ut_metadata piece `idx` must carry: [`PIECE_SIZE`] for every
/// piece but the last, which is the remainder. Enforcing this bounds retained
/// memory to the advertised (bounded) total instead of `pieces * MAX_MESSAGE_LEN`.
#[must_use]
pub fn expected_piece_len(idx: usize, metadata_size: usize, total_pieces: usize) -> usize {
    if total_pieces == 0 {
        0
    } else if idx + 1 < total_pieces {
        PIECE_SIZE
    } else {
        metadata_size - (total_pieces - 1) * PIECE_SIZE
    }
}
