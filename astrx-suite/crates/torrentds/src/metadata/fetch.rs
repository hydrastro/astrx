//! Async peer-wire client + a loopback server, built on the pure builders in
//! [`super::wire`] and parsers in [`super::info`]. This is the only part of the
//! metadata module that needs an async runtime, so it lives behind the `net`
//! feature (it pulls `tokio` + `getrandom`); the pure logic it drives is fully
//! unit-testable without a runtime.

use super::{
    assemble_and_verify, assemble_and_verify_v2, build_ext_handshake, build_handshake,
    build_ut_metadata_data, build_ut_metadata_request, decode_info_dict, expected_piece_len, merr,
    num_pieces, parse_handshake, parse_info, supports_extensions, MetadataError, TorrentMeta,
    EXT_MSG_ID, HANDSHAKE_LEN, KEEPALIVE, MAX_MESSAGE_LEN, MAX_METADATA_SIZE, PIECE_SIZE, UT_DATA,
    UT_REJECT, UT_REQUEST,
};
use crate::bencode::{decode, decode_prefix, Ben};
use std::net::SocketAddr;
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

fn random_peer_id() -> [u8; 20] {
    let mut id = [0u8; 20];
    id[..8].copy_from_slice(b"-TD0001-");
    let mut rnd = [0u8; 12];
    let _ = getrandom::getrandom(&mut rnd);
    id[8..].copy_from_slice(&rnd);
    id
}

async fn read_exact_to(
    stream: &mut TcpStream,
    n: usize,
    timeout: Duration,
) -> Result<Vec<u8>, MetadataError> {
    let mut buf = vec![0u8; n];
    match tokio::time::timeout(timeout, stream.read_exact(&mut buf)).await {
        Ok(Ok(_)) => Ok(buf),
        Ok(Err(e)) => merr(format!("peer connection failed: {e}")),
        Err(_) => merr("read timed out"),
    }
}

async fn write_all_to(stream: &mut TcpStream, bytes: &[u8]) -> Result<(), MetadataError> {
    stream
        .write_all(bytes)
        .await
        .map_err(|e| MetadataError(format!("peer connection failed: {e}")))
}

/// Read one length-prefixed peer message → `(msg_id, payload)`. A keep-alive is
/// `(KEEPALIVE, [])`; for extended messages `msg_id == 20` and `payload[0]` is
/// the extended id. Frames beyond [`MAX_MESSAGE_LEN`] are rejected pre-alloc.
pub async fn read_message(
    stream: &mut TcpStream,
    timeout: Duration,
) -> Result<(i32, Vec<u8>), MetadataError> {
    let header = read_exact_to(stream, 4, timeout).await?;
    let length = u32::from_be_bytes([header[0], header[1], header[2], header[3]]) as usize;
    if length == 0 {
        return Ok((KEEPALIVE, Vec::new()));
    }
    if length > MAX_MESSAGE_LEN {
        return merr(format!("peer message too large: {length} bytes"));
    }
    let body = read_exact_to(stream, length, timeout).await?;
    Ok((i32::from(body[0]), body[1..].to_vec()))
}

/// Read messages until the peer's extended handshake (ext id 0), returning
/// `(their ut_metadata id, metadata_size)`.
async fn read_ext_handshake(
    stream: &mut TcpStream,
    timeout: Duration,
) -> Result<(Option<i64>, Option<i64>), MetadataError> {
    loop {
        let (msg_id, payload) = read_message(stream, timeout).await?;
        if msg_id == i32::from(EXT_MSG_ID) && !payload.is_empty() && payload[0] == 0 {
            if let Ok(Ben::Dict(d)) = decode(&payload[1..]) {
                let ut = match d.get(b"m".as_slice()) {
                    Some(Ben::Dict(m)) => match m.get(b"ut_metadata".as_slice()) {
                        Some(Ben::Int(id)) => Some(*id),
                        _ => None,
                    },
                    _ => None,
                };
                let size = match d.get(b"metadata_size".as_slice()) {
                    Some(Ben::Int(n)) => Some(*n),
                    _ => None,
                };
                return Ok((ut, size));
            }
        }
    }
}

/// Connect to a peer and fetch + verify the info-dict for `info_hash` (BEP-9).
///
/// `timeout` bounds the **entire** fetch, not just each read: a hostile peer that
/// trickles keep-alives (or data for already-filled pieces) one-per-read-window
/// can never make `received` advance, so a per-read timer alone would let it pin
/// the connection open forever. The overall deadline here closes that off.
/// The 20-byte `info_hash` is always what goes on the BEP-3 handshake. When
/// `info_hash_v2` (20-byte truncated or 32-byte full SHA-256) is given, the
/// assembled metadata is verified with SHA-256 (BEP-52) instead of SHA-1.
pub async fn fetch_metadata(
    info_hash: &[u8; 20],
    host: &str,
    port: u16,
    timeout: Duration,
    peer_id: Option<[u8; 20]>,
    info_hash_v2: Option<&[u8]>,
) -> Result<TorrentMeta, MetadataError> {
    let peer_id = peer_id.unwrap_or_else(random_peer_id);
    match tokio::time::timeout(
        timeout,
        fetch_inner(info_hash, host, port, timeout, peer_id, info_hash_v2),
    )
    .await
    {
        Ok(result) => result,
        Err(_) => merr("metadata fetch exceeded deadline"),
    }
}

async fn fetch_inner(
    info_hash: &[u8; 20],
    host: &str,
    port: u16,
    timeout: Duration,
    peer_id: [u8; 20],
    info_hash_v2: Option<&[u8]>,
) -> Result<TorrentMeta, MetadataError> {
    let mut stream = match tokio::time::timeout(timeout, TcpStream::connect((host, port))).await {
        Ok(Ok(s)) => s,
        Ok(Err(e)) => return merr(format!("peer connection failed: {e}")),
        Err(_) => return merr("connect timed out"),
    };

    // BEP-3 handshake.
    write_all_to(&mut stream, &build_handshake(info_hash, &peer_id, true)).await?;
    let (reserved, their_ih, _pid) =
        parse_handshake(&read_exact_to(&mut stream, HANDSHAKE_LEN, timeout).await?)?;
    if their_ih != *info_hash {
        return merr("peer served a different info_hash");
    }
    if !supports_extensions(&reserved) {
        return merr("peer does not support BEP-10 extensions");
    }

    // BEP-10 extended handshake.
    write_all_to(&mut stream, &build_ext_handshake(None, 1)).await?;
    let (peer_ut_id, metadata_size) = read_ext_handshake(&mut stream, timeout).await?;

    let peer_ut = peer_ut_id.filter(|&x| x > 0);
    let msize = metadata_size.filter(|&n| n > 0);
    let (Some(peer_ut), Some(msize)) = (peer_ut, msize) else {
        return merr("peer does not offer ut_metadata");
    };
    if msize as usize > MAX_METADATA_SIZE {
        return merr(format!("advertised metadata_size too large: {msize}"));
    }
    let ext_id =
        u8::try_from(peer_ut).map_err(|_| MetadataError("invalid ut_metadata id".into()))?;
    let metadata_size = usize::try_from(msize).unwrap_or(MAX_METADATA_SIZE);
    let total_pieces = num_pieces(metadata_size);

    // BEP-9: request every piece, then collect data messages.
    for i in 0..total_pieces {
        write_all_to(&mut stream, &build_ut_metadata_request(i as i64, ext_id)).await?;
    }
    let mut pieces: Vec<Option<Vec<u8>>> = vec![None; total_pieces];
    let mut received = 0;
    while received < total_pieces {
        let (msg_id, payload) = read_message(&mut stream, timeout).await?;
        if msg_id != i32::from(EXT_MSG_ID) || payload.is_empty() {
            continue;
        }
        let body = &payload[1..]; // strip the extended id
        let (header, consumed) = match decode_prefix(body) {
            Ok(x) => x,
            Err(_) => continue,
        };
        let Ben::Dict(h) = header else { continue };
        match h.get(b"msg_type".as_slice()) {
            Some(Ben::Int(t)) if *t == UT_REJECT => return merr("peer rejected metadata request"),
            Some(Ben::Int(t)) if *t == UT_DATA => {}
            _ => continue,
        }
        let idx = match h.get(b"piece".as_slice()) {
            Some(Ben::Int(i)) if *i >= 0 && (*i as usize) < total_pieces => *i as usize,
            _ => continue,
        };
        if pieces[idx].is_none() {
            let piece = body[consumed..].to_vec();
            // BEP-9 fixes each piece length; a wrong-sized piece is provably bogus
            // and aborting bounds memory to metadata_size, not pieces * 1 MiB.
            if piece.len() != expected_piece_len(idx, metadata_size, total_pieces) {
                return merr(format!(
                    "peer sent wrong-sized metadata piece {idx} ({} bytes)",
                    piece.len()
                ));
            }
            pieces[idx] = Some(piece);
            received += 1;
        }
    }

    let collected: Vec<Vec<u8>> = pieces.into_iter().flatten().collect();
    let metadata = match info_hash_v2 {
        Some(v2) => assemble_and_verify_v2(&collected, v2).ok_or_else(|| {
            MetadataError("assembled metadata failed SHA-256 verification".into())
        })?,
        None => assemble_and_verify(&collected, info_hash)
            .ok_or_else(|| MetadataError("assembled metadata failed SHA-1 verification".into()))?,
    };
    let info = decode_info_dict(&metadata)?;
    parse_info(&info, Some(*info_hash), Some(metadata))
}

// --- loopback peer that serves an info-dict (tests / demo) -----------------

async fn serve_one(
    mut stream: TcpStream,
    metadata: &[u8],
    corrupt: bool,
) -> Result<(), MetadataError> {
    let timeout = Duration::from_secs(15);
    let (_reserved, ih, _pid) =
        parse_handshake(&read_exact_to(&mut stream, HANDSHAKE_LEN, timeout).await?)?;
    write_all_to(&mut stream, &build_handshake(&ih, &random_peer_id(), true)).await?;

    // Learn the client's ut_metadata id from its extended handshake.
    let (client_ut, _size) = read_ext_handshake(&mut stream, timeout).await?;
    let client_ext = u8::try_from(client_ut.unwrap_or(1)).unwrap_or(1);

    // Advertise our metadata; the client will request pieces with our id (2).
    let our_ut_id: i64 = 2;
    write_all_to(
        &mut stream,
        &build_ext_handshake(Some(metadata.len() as i64), our_ut_id),
    )
    .await?;

    loop {
        let (msg_id, payload) = match read_message(&mut stream, timeout).await {
            Ok(x) => x,
            Err(_) => break,
        };
        if msg_id != i32::from(EXT_MSG_ID) || payload.is_empty() || payload[0] != our_ut_id as u8 {
            continue;
        }
        let (header, _) = match decode_prefix(&payload[1..]) {
            Ok(x) => x,
            Err(_) => continue,
        };
        let Ben::Dict(h) = header else { continue };
        if h.get(b"msg_type".as_slice()) != Some(&Ben::Int(UT_REQUEST)) {
            continue;
        }
        let piece = match h.get(b"piece".as_slice()) {
            Some(Ben::Int(p)) if *p >= 0 => *p as usize,
            _ => 0,
        };
        // Saturating so a hostile `piece` index can't overflow into a `start > end`
        // slice panic; an out-of-range piece just yields an empty chunk.
        let start = piece.saturating_mul(PIECE_SIZE).min(metadata.len());
        let end = piece
            .saturating_add(1)
            .saturating_mul(PIECE_SIZE)
            .min(metadata.len());
        let mut chunk = metadata[start..end].to_vec();
        if corrupt {
            for b in chunk.iter_mut() {
                *b ^= 0xFF;
            }
        }
        write_all_to(
            &mut stream,
            &build_ut_metadata_data(piece as i64, metadata.len() as i64, &chunk, client_ext),
        )
        .await?;
    }
    Ok(())
}

/// Start a loopback peer serving `metadata` (the info-dict bytes). Returns the
/// bound address and the server task's handle (abort it to stop). `corrupt`
/// flips the served bytes so the client's SHA-1 verification must fail.
pub async fn serve_metadata(
    metadata: Vec<u8>,
    corrupt: bool,
) -> std::io::Result<(SocketAddr, tokio::task::JoinHandle<()>)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    let handle = tokio::spawn(async move {
        while let Ok((stream, _)) = listener.accept().await {
            let md = metadata.clone();
            tokio::spawn(async move {
                let _ = serve_one(stream, &md, corrupt).await;
            });
        }
    });
    Ok((addr, handle))
}
