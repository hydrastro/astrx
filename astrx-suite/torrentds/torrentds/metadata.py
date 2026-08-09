"""BitTorrent peer wire + ut_metadata (BEP-3 / BEP-10 / BEP-9).

This module fetches torrent *metadata* (the info-dict) from a peer without
ever downloading content:

* **BEP-3** peer handshake and length-prefixed message framing.
* **BEP-10** extended-message handshake (advertises ``ut_metadata`` and
  reports ``metadata_size``).
* **BEP-9** ut_metadata: request each 16 KiB piece, reassemble, verify
  ``sha1(metadata) == info_hash``, then parse the info-dict.

The pure builders/parsers and ``assemble_and_verify`` are unit-testable
with crafted bytes; ``fetch_metadata`` + ``serve_metadata`` provide a full
loopback round-trip (a local peer serves an info-dict to the client).
"""

from __future__ import annotations

import asyncio
import hashlib
import hmac
import os
from dataclasses import dataclass
from typing import List, Optional, Tuple

from .bencode import BencodeError, decode, decode_lenient, decode_prefix, encode

BT_PROTOCOL = b"BitTorrent protocol"
HANDSHAKE_LEN = 68
PIECE_SIZE = 16384  # 16 KiB metadata piece (BEP-9)
KEEPALIVE = -1
EXT_MSG_ID = 20  # BEP-10 extended message
# A real info-dict is a few MB at most.  A hostile peer can advertise a huge
# ``metadata_size`` (piece-count / list blow-up) or frame a huge peer-wire
# message; cap both before allocating anything.
MAX_METADATA_SIZE = 10 * 1024 * 1024   # 10 MiB
MAX_MESSAGE_LEN = 1024 * 1024          # 1 MiB per peer-wire message

# ut_metadata msg_type values (BEP-9)
UT_REQUEST = 0
UT_DATA = 1
UT_REJECT = 2

# BEP-52 (BitTorrent v2) bounds.  The ``file tree`` is attacker-controlled
# recursive bencode, so the walk is bounded independently of the generic
# bencode MAX_DEPTH: cap both the nesting and the total node count before we
# materialise a flat file list.
MAX_TREE_DEPTH = 60
MAX_TREE_NODES = 100_000
# multihash prefix for SHA2-256 with a 32-byte digest: 0x12 (sha2-256) 0x20 (32).
MULTIHASH_SHA256 = b"\x12\x20"


class MetadataError(Exception):
    pass


# --------------------------------------------------------------------------
# BEP-3 handshake
# --------------------------------------------------------------------------

def build_handshake(info_hash: bytes, peer_id: bytes, extensions: bool = True) -> bytes:
    if len(info_hash) != 20 or len(peer_id) != 20:
        raise ValueError("info_hash and peer_id must be 20 bytes")
    reserved = bytearray(8)
    if extensions:
        reserved[5] |= 0x10  # BEP-10: extension protocol bit
    return bytes([len(BT_PROTOCOL)]) + BT_PROTOCOL + bytes(reserved) + info_hash + peer_id


def parse_handshake(data: bytes) -> Tuple[bytes, bytes, bytes]:
    """Return (reserved, info_hash, peer_id); raise on a bad handshake."""
    if len(data) != HANDSHAKE_LEN:
        raise MetadataError("handshake must be 68 bytes")
    if data[0] != len(BT_PROTOCOL) or data[1:20] != BT_PROTOCOL:
        raise MetadataError("not a BitTorrent handshake")
    return data[20:28], data[28:48], data[48:68]


def supports_extensions(reserved: bytes) -> bool:
    return bool(reserved[5] & 0x10)


# --------------------------------------------------------------------------
# Peer wire message framing (BEP-3) + extended messages (BEP-10)
# --------------------------------------------------------------------------

def build_message(msg_id: int, payload: bytes = b"") -> bytes:
    body = bytes([msg_id]) + payload
    return len(body).to_bytes(4, "big") + body


def build_ext_message(ext_id: int, payload: bytes) -> bytes:
    return build_message(EXT_MSG_ID, bytes([ext_id]) + payload)


def build_ext_handshake(metadata_size: Optional[int] = None,
                        ut_metadata_id: int = 1) -> bytes:
    """Extended handshake (ext id 0).

    ``ut_metadata_id`` is the id *we* ask the peer to use when sending us
    ut_metadata messages; ``metadata_size`` is advertised by the side that
    already holds the metadata.
    """
    d: dict = {b"m": {b"ut_metadata": ut_metadata_id}}
    if metadata_size is not None:
        d[b"metadata_size"] = metadata_size
    return build_ext_message(0, encode(d))


def build_ut_metadata_request(piece: int, ext_id: int) -> bytes:
    return build_ext_message(ext_id, encode({b"msg_type": UT_REQUEST, b"piece": piece}))


def build_ut_metadata_data(piece: int, total_size: int, data: bytes, ext_id: int) -> bytes:
    header = encode({b"msg_type": UT_DATA, b"piece": piece, b"total_size": total_size})
    return build_ext_message(ext_id, header + data)


def build_ut_metadata_reject(piece: int, ext_id: int) -> bytes:
    return build_ext_message(ext_id, encode({b"msg_type": UT_REJECT, b"piece": piece}))


async def _readexactly(reader: asyncio.StreamReader, n: int, timeout: float) -> bytes:
    return await asyncio.wait_for(reader.readexactly(n), timeout)


async def read_message(reader: asyncio.StreamReader, timeout: float = 15.0) -> Tuple[int, bytes]:
    """Read one length-prefixed peer message.

    Returns ``(msg_id, payload)``.  A keep-alive (length 0) is reported as
    ``(KEEPALIVE, b"")``.  For extended messages ``msg_id == 20`` and
    ``payload[0]`` is the extended message id.
    """
    header = await _readexactly(reader, 4, timeout)
    length = int.from_bytes(header, "big")
    if length == 0:
        return KEEPALIVE, b""
    if length > MAX_MESSAGE_LEN:
        raise MetadataError("peer message too large: %d bytes" % length)
    body = await _readexactly(reader, length, timeout)
    return body[0], body[1:]


# --------------------------------------------------------------------------
# Assembly + verification + info-dict parsing
# --------------------------------------------------------------------------

def num_pieces(metadata_size: int) -> int:
    return (metadata_size + PIECE_SIZE - 1) // PIECE_SIZE


def expected_piece_len(idx: int, metadata_size: int, total_pieces: int) -> int:
    """Exact byte length ut_metadata piece *idx* must carry (BEP-9).

    Every piece is ``PIECE_SIZE`` (16 KiB) except the last, which is the
    remainder ``metadata_size - (total_pieces-1)*PIECE_SIZE``.  Enforcing this on
    receipt bounds retained memory to the advertised (``<= MAX_METADATA_SIZE``)
    total instead of ``total_pieces * MAX_MESSAGE_LEN`` (~640 MiB), which a
    hostile peer could otherwise force by padding every piece up to the 1 MiB
    per-message cap before the final SHA-1 check discards it all.
    """
    if idx < total_pieces - 1:
        return PIECE_SIZE
    return metadata_size - (total_pieces - 1) * PIECE_SIZE


def assemble_and_verify(pieces: List[bytes], info_hash: bytes) -> Optional[bytes]:
    """Concatenate ordered pieces and check the SHA-1 against *info_hash*.

    Returns the metadata bytes on success, ``None`` on hash mismatch.
    """
    if any(p is None for p in pieces):
        return None
    metadata = b"".join(pieces)
    if hashlib.sha1(metadata).digest() != info_hash:
        return None
    return metadata


@dataclass
class TorrentMeta:
    info_hash: bytes
    name: str
    total_size: int
    piece_length: int
    piece_count: int
    files: List[Tuple[str, int]]  # (path, length)
    # The RAW, SHA-1-verified info-dict bytes.  Kept so the store can persist
    # the blob and later rebuild a byte-exact .torrent (its info section must
    # hash back to ``info_hash``).  ``None`` for metas built synthetically.
    info_bytes: Optional[bytes] = None
    # BEP-52: 32-byte SHA-256 infohash of the info-dict.  Set for v2-only and
    # hybrid torrents; ``None`` for pure v1.  For a v2-only torrent ``info_hash``
    # above carries the *truncated* (first-20-byte) form used on the DHT.
    info_hash_v2: Optional[bytes] = None
    version: str = "v1"           # "v1" | "v2" | "hybrid"
    # A CONTENT fingerprint independent of the display name: the v1 ``pieces``
    # blob digest, or the v2 ``file tree`` digest (paths+lengths+pieces roots).
    # Folded into the store's cross-infohash dedup signature so a torrent that
    # merely copies another's path+length layout -- but has different actual
    # content -- cannot poison the collapse.  ``None`` when no piece data exists.
    content_id: Optional[bytes] = None

    @property
    def file_count(self) -> int:
        return len(self.files)

    @property
    def infohash_v2_hex(self) -> Optional[str]:
        return self.info_hash_v2.hex() if self.info_hash_v2 else None

    @property
    def dht_infohash_v2(self) -> Optional[bytes]:
        """The 20-byte truncated v2 infohash used on the DHT / peer wire."""
        return self.info_hash_v2[:20] if self.info_hash_v2 else None


def decode_info_dict(metadata: bytes) -> dict:
    """Decode a SHA-1-verified info-dict, tolerating non-canonical real data.

    The strict decoder is tried first; if it rejects the bytes only for
    canonical-form reasons (unsorted/dup keys, leading zeros) we fall back to
    :func:`bencode.decode_lenient`.  This never weakens security: the caller
    has already checked ``sha1(metadata) == info_hash`` on these exact bytes.
    """
    try:
        info = decode(metadata)
    except BencodeError:
        try:
            info = decode_lenient(metadata)
        except BencodeError as exc:
            raise MetadataError("undecodable info-dict: %s" % exc) from exc
    if not isinstance(info, dict):
        raise MetadataError("info-dict is not a dict")
    return info


def build_torrent_file(info_bytes: bytes, announce: Optional[str] = None,
                       announce_list: Optional[List[str]] = None,
                       creation_date: Optional[int] = None) -> bytes:
    """Rebuild a valid ``.torrent`` around pre-verified *info_bytes*.

    The ``info`` value is spliced in verbatim (never re-encoded), so its SHA-1
    still equals the original infohash even when the info-dict was itself
    non-canonical.  Other top-level keys are emitted in canonical byte order.
    """
    if not isinstance(info_bytes, (bytes, bytearray)):
        raise ValueError("info_bytes must be bytes")
    entries: List[Tuple[bytes, bytes]] = []
    if announce:
        entries.append((b"announce", encode(announce)))
    if announce_list:
        entries.append((b"announce-list", encode([[a] for a in announce_list])))
    if creation_date is not None:
        entries.append((b"creation date", encode(int(creation_date))))
    entries.append((b"info", bytes(info_bytes)))
    entries.sort(key=lambda kv: kv[0])   # canonical top-level key order
    out = [b"d"]
    for key, value in entries:
        out.append(b"%d:" % len(key))
        out.append(key)
        out.append(value)
    out.append(b"e")
    return b"".join(out)


def parse_info(info: dict, info_hash: Optional[bytes] = None,
               info_bytes: Optional[bytes] = None) -> TorrentMeta:
    """Parse a torrent info-dict into a :class:`TorrentMeta`.

    Detects BEP-52 v2 / hybrid info-dicts (``meta version == 2`` with a
    ``file tree``) and routes them through :func:`parse_v2_info`; otherwise
    parses the classic v1 layout.
    """
    if not isinstance(info, dict):
        raise MetadataError("info must be a dict")
    if is_v2_info(info):
        return parse_v2_info(info, info_bytes=info_bytes, dht_info_hash=info_hash)
    name = info.get(b"name", b"").decode("utf-8", "replace")
    piece_length = int(info.get(b"piece length", 0) or 0)
    pieces_blob = info.get(b"pieces", b"")
    piece_count = len(pieces_blob) // 20 if isinstance(pieces_blob, bytes) else 0
    files: List[Tuple[str, int]] = []
    if isinstance(info.get(b"files"), list):
        for entry in info[b"files"]:
            if not isinstance(entry, dict):
                continue
            length = max(0, int(entry.get(b"length", 0) or 0))
            parts = entry.get(b"path", [])
            path = "/".join(
                p.decode("utf-8", "replace") for p in parts if isinstance(p, bytes)
            )
            files.append((path or name, length))
        total = sum(l for _, l in files)
    else:
        length = max(0, int(info.get(b"length", 0) or 0))
        files.append((name, length))
        total = length
    if info_hash is None:
        info_hash = hashlib.sha1(encode(info)).digest()
    # Content fingerprint (name-independent): the v1 piece-hash blob, if any.
    content_id = (hashlib.sha256(pieces_blob).digest()
                  if isinstance(pieces_blob, bytes) and pieces_blob else None)
    return TorrentMeta(info_hash, name, total, piece_length, piece_count, files,
                       content_id=content_id)


def infohash_of(info: dict) -> bytes:
    return hashlib.sha1(encode(info)).digest()


# --------------------------------------------------------------------------
# BEP-52 v2 / hybrid torrents
# --------------------------------------------------------------------------

def infohash_v2_of(info: dict) -> bytes:
    """32-byte SHA-256 infohash of a (canonically re-encoded) v2 info-dict."""
    return hashlib.sha256(encode(info)).digest()


def truncate_v2(info_hash_v2: bytes) -> bytes:
    """The 20-byte truncated v2 infohash used where the DHT needs 20 bytes."""
    return info_hash_v2[:20]


def is_v2_info(info: dict) -> bool:
    """True if *info* is a BEP-52 v2 (or hybrid) info-dict."""
    return (isinstance(info, dict)
            and info.get(b"meta version") == 2
            and isinstance(info.get(b"file tree"), dict))


def is_hybrid_info(info: dict) -> bool:
    """True if *info* carries BOTH v2 and v1 (``pieces``) structures."""
    return is_v2_info(info) and isinstance(info.get(b"pieces"), bytes)


def walk_file_tree(file_tree: dict,
                   max_depth: int = MAX_TREE_DEPTH,
                   max_nodes: int = MAX_TREE_NODES) -> List[Tuple[str, int]]:
    """Flatten a BEP-52 ``file tree`` into ``[(path, length), ...]``.

    Each leaf is a ``{"": {"length": N, "pieces root": <32 bytes>}}`` node whose
    accumulated key path is the file path.  The recursion is bounded on both
    depth and total node count because the tree is hostile network data: a
    maliciously deep or wide tree raises :class:`MetadataError` instead of
    exhausting the stack or memory.
    """
    if not isinstance(file_tree, dict):
        raise MetadataError("file tree must be a dict")
    out: List[Tuple[str, int]] = []
    nodes = [0]

    def _walk(node: dict, prefix: List[str], depth: int) -> None:
        if depth > max_depth:
            raise MetadataError("file tree nested too deeply (>%d)" % max_depth)
        # A file leaf: the empty-string key holds the length/pieces-root.
        leaf = node.get(b"")
        if isinstance(leaf, dict) and b"length" in leaf:
            try:
                length = int(leaf.get(b"length", 0) or 0)
            except (TypeError, ValueError):
                length = 0
            path = "/".join(prefix) if prefix else ""
            out.append((path, max(0, length)))
            return
        for name, child in node.items():
            nodes[0] += 1
            if nodes[0] > max_nodes:
                raise MetadataError("file tree too large (>%d nodes)" % max_nodes)
            if not isinstance(name, bytes) or not isinstance(child, dict):
                continue
            if name == b"":
                continue  # already handled as a leaf above
            prefix.append(name.decode("utf-8", "replace"))
            _walk(child, prefix, depth + 1)
            prefix.pop()

    _walk(file_tree, [], 0)
    return out


def parse_v2_info(info: dict, info_bytes: Optional[bytes] = None,
                  dht_info_hash: Optional[bytes] = None) -> TorrentMeta:
    """Parse a BEP-52 v2 (or hybrid) info-dict into a :class:`TorrentMeta`.

    The v2 infohash is SHA-256 over the info-dict.  When ``info_bytes`` (the
    raw, verified wire bytes) is supplied the hash is taken over *those* bytes
    so a non-canonical dict still hashes correctly; otherwise the dict is
    re-encoded.  A hybrid dict additionally yields the v1 SHA-1 infohash.
    """
    if not isinstance(info, dict):
        raise MetadataError("info must be a dict")
    name = info.get(b"name", b"")
    name = name.decode("utf-8", "replace") if isinstance(name, bytes) else ""
    piece_length = int(info.get(b"piece length", 0) or 0)
    files = walk_file_tree(info[b"file tree"])
    total = sum(l for _, l in files)

    raw = info_bytes if isinstance(info_bytes, (bytes, bytearray)) else encode(info)
    v2_full = hashlib.sha256(bytes(raw)).digest()

    hybrid = isinstance(info.get(b"pieces"), bytes)
    if hybrid:
        pieces_blob = info.get(b"pieces", b"")
        piece_count = len(pieces_blob) // 20
        v1 = hashlib.sha1(bytes(raw)).digest()
        version = "hybrid"
        primary = v1
    else:
        # v2-only: the DHT key is the truncated SHA-256; no v1 SHA-1 exists.
        piece_count = (total + piece_length - 1) // piece_length if piece_length else 0
        version = "v2"
        primary = truncate_v2(v2_full)

    # When a requested DHT infohash is supplied, constant-time-verify that the
    # recomputed hash actually matches it: accept the 20-byte primary (v1 SHA-1
    # for hybrid, truncated SHA-256 for v2-only), the truncated SHA-256, or the
    # full 32-byte SHA-256.  A mismatch means these bytes are not the requested
    # info-dict and must be rejected (never silently accept a substitute).
    if dht_info_hash is not None:
        if len(dht_info_hash) == 32:
            ok = hmac.compare_digest(v2_full, bytes(dht_info_hash))
        elif len(dht_info_hash) == 20:
            ok = (hmac.compare_digest(primary, bytes(dht_info_hash))
                  or hmac.compare_digest(truncate_v2(v2_full), bytes(dht_info_hash)))
        else:
            ok = False
        if not ok:
            raise MetadataError("v2 info-dict does not match requested infohash")

    # Content fingerprint (name-independent): the ``file tree`` digest, which
    # covers every file's path, length and pieces root -- identical only for
    # byte-identical content, so a copied path+length layout with different
    # pieces roots yields a different id and cannot poison dedup.
    content_id = hashlib.sha256(encode(info[b"file tree"])).digest()

    meta = TorrentMeta(primary, name, total, piece_length, piece_count, files,
                       info_bytes=raw if info_bytes is not None else None,
                       info_hash_v2=v2_full, version=version, content_id=content_id)
    return meta


def verify_v2(info_bytes: bytes, expected: bytes) -> bool:
    """Byte-exact v2 verification: recompute SHA-256 over *info_bytes*.

    ``expected`` may be the full 32-byte SHA-256 infohash (compared in full) or
    the 20-byte truncated DHT form (compared against the first 20 bytes of the
    recomputed digest).  Any other length is rejected.  Uses a constant-time
    compare, mirroring the v1 KRPC-hardening style.
    """
    if not isinstance(info_bytes, (bytes, bytearray)):
        return False
    digest = hashlib.sha256(bytes(info_bytes)).digest()
    if len(expected) == 32:
        return hmac.compare_digest(digest, bytes(expected))
    if len(expected) == 20:
        return hmac.compare_digest(digest[:20], bytes(expected))
    return False


def assemble_and_verify_v2(pieces: List[bytes], info_hash_v2: bytes) -> Optional[bytes]:
    """v2 analogue of :func:`assemble_and_verify` (SHA-256 instead of SHA-1)."""
    if any(p is None for p in pieces):
        return None
    metadata = b"".join(pieces)
    if not verify_v2(metadata, info_hash_v2):
        return None
    return metadata


@dataclass
class Magnet:
    """A parsed magnet URI: a v1 and/or v2 infohash plus an optional name."""
    v1_infohash: Optional[bytes] = None   # 20 bytes
    v2_infohash: Optional[bytes] = None   # 32 bytes
    name: Optional[str] = None

    @property
    def dht_infohash(self) -> Optional[bytes]:
        if self.v1_infohash is not None:
            return self.v1_infohash
        return self.v2_infohash[:20] if self.v2_infohash else None


def _decode_btih(value: str) -> bytes:
    """Decode a BEP-9 ``btih`` info-hash: 40-hex or 32-char base32 -> 20 bytes."""
    value = value.strip()
    if len(value) == 40:
        try:
            raw = bytes.fromhex(value)
        except ValueError as exc:
            raise MetadataError("btih is not valid hex") from exc
        # ``bytes.fromhex`` silently ignores embedded ASCII whitespace, so a
        # 40-char field with internal spaces decodes to fewer than 20 bytes;
        # enforce the exact length (mirrors the base32 branch below).
        if len(raw) != 20:
            raise MetadataError("btih hex did not decode to 20 bytes")
        return raw
    if len(value) == 32:
        import base64
        try:
            raw = base64.b32decode(value.upper())
        except (ValueError, Exception) as exc:  # binascii.Error subclasses ValueError
            raise MetadataError("btih is not valid base32") from exc
        if len(raw) != 20:
            raise MetadataError("btih base32 did not decode to 20 bytes")
        return raw
    raise MetadataError("btih must be 40 hex or 32 base32 chars")


def _decode_btmh(value: str) -> bytes:
    """Decode a BEP-52 ``btmh`` multihash: ``1220`` + 64 hex -> 32-byte SHA-256."""
    value = value.strip().lower()
    try:
        raw = bytes.fromhex(value)
    except ValueError as exc:
        raise MetadataError("btmh is not valid hex") from exc
    # multihash: 0x12 0x20 (sha2-256, 32 bytes) then exactly 32 digest bytes.
    if len(raw) != 34 or raw[:2] != MULTIHASH_SHA256:
        raise MetadataError("btmh is not a 32-byte sha2-256 multihash")
    return raw[2:]


def parse_magnet(uri: str) -> Magnet:
    """Parse ``magnet:?xt=urn:btih:...`` and/or ``xt=urn:btmh:1220<64hex>``.

    Supports v1 (btih), v2 (btmh) and hybrid magnets (both ``xt`` present).
    Raises :class:`MetadataError` on a malformed multihash / unusable URI.
    """
    if not isinstance(uri, str) or not uri.startswith("magnet:"):
        raise MetadataError("not a magnet URI")
    from urllib.parse import parse_qs, unquote
    query = uri.partition("?")[2]
    params = parse_qs(query, keep_blank_values=False)
    v1 = v2 = None
    for xt in params.get("xt", []):
        if xt.startswith("urn:btih:"):
            v1 = _decode_btih(xt[len("urn:btih:"):])
        elif xt.startswith("urn:btmh:"):
            v2 = _decode_btmh(xt[len("urn:btmh:"):])
    if v1 is None and v2 is None:
        raise MetadataError("magnet has no usable xt (btih/btmh)")
    name = params.get("dn", [None])[0]
    if name is not None:
        name = unquote(name)
    return Magnet(v1_infohash=v1, v2_infohash=v2, name=name)


# --------------------------------------------------------------------------
# Async client: fetch metadata from a peer over loopback / network
# --------------------------------------------------------------------------

def _random_peer_id() -> bytes:
    return b"-TD0001-" + os.urandom(12)


async def fetch_metadata(info_hash: bytes, host: str, port: int,
                         timeout: float = 15.0,
                         peer_id: Optional[bytes] = None,
                         *, info_hash_v2: Optional[bytes] = None) -> TorrentMeta:
    """Connect to a peer and fetch+verify the info-dict for *info_hash*.

    The 20-byte ``info_hash`` is always what goes on the BEP-3 wire handshake.
    When ``info_hash_v2`` (20-byte truncated or 32-byte full SHA-256) is given,
    the assembled metadata is verified with SHA-256 (BEP-52) instead of SHA-1;
    the v1 SHA-1 path is used unchanged otherwise.
    """
    if len(info_hash) != 20:
        raise ValueError("info_hash must be 20 bytes")
    peer_id = peer_id or _random_peer_id()
    reader, writer = await asyncio.wait_for(
        asyncio.open_connection(host, port), timeout)
    try:
        writer.write(build_handshake(info_hash, peer_id))
        await writer.drain()
        reserved, their_ih, _their_pid = parse_handshake(
            await _readexactly(reader, HANDSHAKE_LEN, timeout))
        if their_ih != info_hash:
            raise MetadataError("peer served a different info_hash")
        if not supports_extensions(reserved):
            raise MetadataError("peer does not support BEP-10 extensions")

        my_ut_id = 1
        writer.write(build_ext_handshake(ut_metadata_id=my_ut_id))
        await writer.drain()

        peer_ut_id: Optional[int] = None
        metadata_size: Optional[int] = None
        # Loop until we see the peer's extended handshake (ext id 0).
        while peer_ut_id is None:
            msg_id, payload = await read_message(reader, timeout)
            if msg_id == EXT_MSG_ID and payload and payload[0] == 0:
                d = decode(payload[1:])
                m = d.get(b"m", {})
                peer_ut_id = m.get(b"ut_metadata") if isinstance(m, dict) else None
                metadata_size = d.get(b"metadata_size")
        if not peer_ut_id or not isinstance(metadata_size, int) or metadata_size <= 0:
            raise MetadataError("peer does not offer ut_metadata")
        if metadata_size > MAX_METADATA_SIZE:
            raise MetadataError(
                "advertised metadata_size too large: %d" % metadata_size)

        total_pieces = num_pieces(metadata_size)
        pieces: List[Optional[bytes]] = [None] * total_pieces
        for i in range(total_pieces):
            writer.write(build_ut_metadata_request(i, peer_ut_id))
        await writer.drain()

        received = 0
        while received < total_pieces:
            msg_id, payload = await read_message(reader, timeout)
            if msg_id != EXT_MSG_ID or not payload:
                continue
            body = payload[1:]  # strip extended id
            header, consumed = decode_prefix(body)
            if not isinstance(header, dict):
                continue
            mtype = header.get(b"msg_type")
            if mtype == UT_REJECT:
                raise MetadataError("peer rejected metadata request")
            if mtype != UT_DATA:
                continue
            idx = header.get(b"piece")
            if not isinstance(idx, int) or not (0 <= idx < total_pieces):
                continue
            if pieces[idx] is None:
                piece = body[consumed:]
                # Reject a wrong-sized piece: BEP-9 fixes every piece at
                # PIECE_SIZE (last = remainder), so a padded/oversized piece is
                # provably bogus. Aborting here caps retained memory at the
                # advertised metadata_size rather than total_pieces * 1 MiB.
                if len(piece) != expected_piece_len(idx, metadata_size, total_pieces):
                    raise MetadataError(
                        "peer sent wrong-sized metadata piece %d (%d bytes)"
                        % (idx, len(piece)))
                pieces[idx] = piece
                received += 1

        if info_hash_v2 is not None:
            metadata = assemble_and_verify_v2(pieces, info_hash_v2)
            if metadata is None:
                raise MetadataError("assembled metadata failed SHA-256 verification")
        else:
            metadata = assemble_and_verify(pieces, info_hash)
            if metadata is None:
                raise MetadataError("assembled metadata failed SHA-1 verification")
        # The SHA-1/SHA-256 check already ran on the raw bytes above, so a
        # lenient decode of a mildly non-canonical info-dict is safe (see
        # decode_info_dict).
        info = decode_info_dict(metadata)
        meta = parse_info(info, info_hash, info_bytes=metadata)
        meta.info_bytes = metadata   # keep raw bytes for .torrent rebuild
        return meta
    except MetadataError:
        raise
    except (asyncio.IncompleteReadError, asyncio.TimeoutError,
            ConnectionError, OSError) as exc:
        # Real peers drop connections constantly; surface as MetadataError.
        raise MetadataError("peer connection failed: %s" % exc) from exc
    except (BencodeError, ValueError, RecursionError) as exc:
        # Metadata that hashes to the requested infohash can still be junk
        # bencode or carry non-numeric fields (an attacker generates their
        # own torrent).  Never let decode()/parse_info() escape as an
        # unexpected exception -- that would kill the fetch worker.
        raise MetadataError("invalid metadata from peer: %s" % exc) from exc
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass


# --------------------------------------------------------------------------
# Loopback peer server that serves an info-dict (for tests / demo)
# --------------------------------------------------------------------------

async def _serve_one(reader: asyncio.StreamReader, writer: asyncio.StreamWriter,
                     metadata: bytes, info_hash: bytes,
                     corrupt: bool = False) -> None:
    try:
        reserved, ih, _pid = parse_handshake(
            await reader.readexactly(HANDSHAKE_LEN))
        writer.write(build_handshake(ih, _random_peer_id()))
        await writer.drain()

        client_ut_id: Optional[int] = None
        while client_ut_id is None:
            msg_id, payload = await read_message(reader)
            if msg_id == EXT_MSG_ID and payload and payload[0] == 0:
                d = decode(payload[1:])
                m = d.get(b"m", {})
                client_ut_id = m.get(b"ut_metadata") if isinstance(m, dict) else None

        our_ut_id = 2
        writer.write(build_ext_handshake(metadata_size=len(metadata),
                                         ut_metadata_id=our_ut_id))
        await writer.drain()

        while True:
            try:
                msg_id, payload = await read_message(reader)
            except (asyncio.IncompleteReadError, ConnectionError, asyncio.TimeoutError):
                break
            if msg_id != EXT_MSG_ID or not payload or payload[0] != our_ut_id:
                continue
            header, _ = decode_prefix(payload[1:])
            if not isinstance(header, dict) or header.get(b"msg_type") != UT_REQUEST:
                continue
            piece = header.get(b"piece", 0)
            chunk = metadata[piece * PIECE_SIZE:(piece + 1) * PIECE_SIZE]
            if corrupt:
                chunk = bytes(b ^ 0xFF for b in chunk)
            writer.write(build_ut_metadata_data(piece, len(metadata), chunk, client_ut_id))
            await writer.drain()
    except (asyncio.IncompleteReadError, ConnectionError, MetadataError):
        pass
    finally:
        writer.close()


async def serve_metadata(metadata: bytes, host: str = "127.0.0.1", port: int = 0,
                         corrupt: bool = False):
    """Start a loopback peer that serves *metadata* (the info-dict bytes).

    Returns ``(server, host, port)``.  ``corrupt=True`` flips the served
    bytes so the client's SHA-1 verification must fail.
    """
    info_hash = hashlib.sha1(metadata).digest()

    async def handler(reader, writer):
        await _serve_one(reader, writer, metadata, info_hash, corrupt=corrupt)

    server = await asyncio.start_server(handler, host, port)
    bound = server.sockets[0].getsockname()
    return server, bound[0], bound[1]
