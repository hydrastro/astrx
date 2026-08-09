"""ut_metadata (BEP-9) unit tests + loopback fetch round-trip."""

import asyncio
import hashlib
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import decode, encode
from torrentds.metadata import (
    EXT_MSG_ID,
    HANDSHAKE_LEN,
    MetadataError,
    TorrentMeta,
    assemble_and_verify,
    build_ext_handshake,
    build_handshake,
    build_torrent_file,
    build_ut_metadata_data,
    build_ut_metadata_reject,
    build_ut_metadata_request,
    decode_info_dict,
    expected_piece_len,
    fetch_metadata,
    infohash_of,
    num_pieces,
    parse_handshake,
    parse_info,
    read_message,
    serve_metadata,
    supports_extensions,
)


def make_info(name="ubuntu-24.04.iso", files=None, single_length=None,
              piece_length=262144, n_hash_pieces=2000):
    """Build an info-dict big enough to span several 16 KiB metadata pieces."""
    info = {
        b"name": name.encode(),
        b"piece length": piece_length,
        b"pieces": os.urandom(20 * n_hash_pieces),
    }
    if files is not None:
        info[b"files"] = [
            {b"length": length, b"path": [p.encode() for p in path.split("/")]}
            for path, length in files
        ]
    else:
        info[b"length"] = single_length if single_length is not None else 123456789
    return info


def unwrap(msg):
    """Unwrap a single length-prefixed peer message -> (msg_id, payload)."""
    length = int.from_bytes(msg[:4], "big")
    body = msg[4:4 + length]
    return body[0], body[1:]


class TestPeerWireCodec(unittest.TestCase):
    def test_handshake_round_trip(self):
        ih = os.urandom(20)
        pid = os.urandom(20)
        hs = build_handshake(ih, pid)
        self.assertEqual(len(hs), 68)
        reserved, got_ih, got_pid = parse_handshake(hs)
        self.assertEqual(got_ih, ih)
        self.assertEqual(got_pid, pid)
        self.assertTrue(supports_extensions(reserved))

    def test_handshake_without_extensions(self):
        reserved, _, _ = parse_handshake(
            build_handshake(os.urandom(20), os.urandom(20), extensions=False))
        self.assertFalse(supports_extensions(reserved))

    def test_bad_handshake_rejected(self):
        with self.assertRaises(MetadataError):
            parse_handshake(b"\x00" * 68)

    def test_ext_handshake_encoding(self):
        msg = build_ext_handshake(metadata_size=40100, ut_metadata_id=3)
        msg_id, payload = unwrap(msg)
        self.assertEqual(msg_id, EXT_MSG_ID)
        self.assertEqual(payload[0], 0)  # extended handshake id
        d = decode(payload[1:])
        self.assertEqual(d[b"m"][b"ut_metadata"], 3)
        self.assertEqual(d[b"metadata_size"], 40100)

    def test_ut_metadata_request_encoding(self):
        _, payload = unwrap(build_ut_metadata_request(5, ext_id=3))
        self.assertEqual(payload[0], 3)  # peer's ut_metadata id
        self.assertEqual(decode(payload[1:]), {b"msg_type": 0, b"piece": 5})

    def test_ut_metadata_data_encoding(self):
        chunk = b"\xab" * 16384
        _, payload = unwrap(build_ut_metadata_data(1, 40100, chunk, ext_id=1))
        header_len = len(encode({b"msg_type": 1, b"piece": 1, b"total_size": 40100}))
        self.assertEqual(payload[1 + header_len:], chunk)  # raw data trails the dict

    def test_ut_metadata_reject_encoding(self):
        _, payload = unwrap(build_ut_metadata_reject(2, ext_id=1))
        self.assertEqual(decode(payload[1:]), {b"msg_type": 2, b"piece": 2})


class TestAssembleVerify(unittest.TestCase):
    def test_num_pieces(self):
        self.assertEqual(num_pieces(1), 1)
        self.assertEqual(num_pieces(16384), 1)
        self.assertEqual(num_pieces(16385), 2)
        self.assertEqual(num_pieces(40100), 3)

    def test_assemble_success(self):
        info = make_info()
        metadata = encode(info)
        ih = hashlib.sha1(metadata).digest()
        pieces = [metadata[i:i + 16384] for i in range(0, len(metadata), 16384)]
        self.assertEqual(assemble_and_verify(pieces, ih), metadata)

    def test_assemble_hash_mismatch(self):
        info = make_info()
        metadata = encode(info)
        wrong = hashlib.sha1(b"nope").digest()
        pieces = [metadata[i:i + 16384] for i in range(0, len(metadata), 16384)]
        self.assertIsNone(assemble_and_verify(pieces, wrong))

    def test_assemble_missing_piece(self):
        self.assertIsNone(assemble_and_verify([b"a", None], os.urandom(20)))

    def test_expected_piece_len(self):
        # 40100 bytes -> 3 pieces: 16384, 16384, 7332.
        self.assertEqual(expected_piece_len(0, 40100, 3), 16384)
        self.assertEqual(expected_piece_len(1, 40100, 3), 16384)
        self.assertEqual(expected_piece_len(2, 40100, 3), 40100 - 2 * 16384)
        # single-piece torrent: the one piece is the whole (sub-16 KiB) blob.
        self.assertEqual(expected_piece_len(0, 5000, 1), 5000)
        # exact multiple of PIECE_SIZE: last piece is a full PIECE_SIZE.
        self.assertEqual(expected_piece_len(1, 2 * 16384, 2), 16384)


class TestParseInfo(unittest.TestCase):
    def test_multi_file(self):
        info = make_info(name="pack", files=[("a/x.txt", 100), ("a/y.txt", 200)])
        meta = parse_info(info, infohash_of(info))
        self.assertIsInstance(meta, TorrentMeta)
        self.assertEqual(meta.name, "pack")
        self.assertEqual(meta.file_count, 2)
        self.assertEqual(meta.total_size, 300)
        self.assertEqual([f[0] for f in meta.files], ["a/x.txt", "a/y.txt"])
        self.assertEqual(meta.piece_count, 2000)

    def test_single_file(self):
        info = make_info(name="one.bin", single_length=555)
        meta = parse_info(info)
        self.assertEqual(meta.file_count, 1)
        self.assertEqual(meta.total_size, 555)
        self.assertEqual(meta.info_hash, infohash_of(info))

    def test_negative_single_length_clamped(self):
        # L8: a hostile bencode negative length must clamp to 0 (v2 already did).
        info = make_info(name="bad.bin", single_length=-100)
        meta = parse_info(info)
        self.assertEqual(meta.files, [("bad.bin", 0)])
        self.assertEqual(meta.total_size, 0)

    def test_negative_multi_file_length_clamped(self):
        info = make_info(name="pack", files=[("a.txt", -5), ("b.txt", 200)])
        meta = parse_info(info)
        self.assertEqual(dict(meta.files), {"a.txt": 0, "b.txt": 200})
        self.assertEqual(meta.total_size, 200)


class TestTorrentRebuild(unittest.TestCase):
    def test_build_torrent_info_hashes_back(self):
        info = make_info(name="rebuild.iso")
        raw = encode(info)
        ih = hashlib.sha1(raw).digest()
        torrent = build_torrent_file(raw, announce="http://tr.example/announce")
        d = decode(torrent)
        # The spliced-in info section must still hash to the original infohash.
        self.assertEqual(hashlib.sha1(encode(d[b"info"])).digest(), ih)
        self.assertEqual(d[b"announce"], b"http://tr.example/announce")

    def test_build_torrent_preserves_noncanonical_info(self):
        # A non-canonical info-dict (unsorted keys) is spliced verbatim, so its
        # SHA-1 is preserved even though re-encoding it would change the bytes.
        raw = b"d4:name8:weird.io6:lengthi55ee"   # 'name' before 'length'
        ih = hashlib.sha1(raw).digest()
        torrent = build_torrent_file(raw)
        # Locate the info section: it is the raw bytes spliced after '4:info'.
        marker = b"4:info"
        idx = torrent.index(marker) + len(marker)
        self.assertEqual(hashlib.sha1(torrent[idx:idx + len(raw)]).digest(), ih)


class TestDecodeInfoDict(unittest.TestCase):
    def test_lenient_fallback_used_for_info(self):
        raw = b"d4:name3:abc6:lengthi100ee"      # unsorted -> strict would fail
        info = decode_info_dict(raw)
        self.assertEqual(info[b"name"], b"abc")
        self.assertEqual(info[b"length"], 100)

    def test_true_junk_still_raises(self):
        with self.assertRaises(MetadataError):
            decode_info_dict(b"not-bencode")


class TestMetadataLoopback(unittest.IsolatedAsyncioTestCase):
    async def test_full_fetch_multi_piece(self):
        info = make_info(name="loopback.torrent",
                         files=[("dir/a.dat", 1000), ("dir/b.dat", 2000)])
        metadata = encode(info)
        info_hash = hashlib.sha1(metadata).digest()
        self.assertGreater(num_pieces(len(metadata)), 1)  # ensure multi-piece

        server, host, port = await serve_metadata(metadata)
        try:
            meta = await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()

        self.assertEqual(meta.info_hash, info_hash)
        self.assertEqual(meta.name, "loopback.torrent")
        self.assertEqual(meta.file_count, 2)
        self.assertEqual(meta.total_size, 3000)
        self.assertEqual(meta.piece_count, 2000)

    async def test_fetch_preserves_raw_info_bytes(self):
        info = make_info(name="withblob.iso",
                         files=[("d/a.dat", 10), ("d/b.dat", 20)])
        metadata = encode(info)
        info_hash = hashlib.sha1(metadata).digest()
        server, host, port = await serve_metadata(metadata)
        try:
            meta = await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()
        # The raw, verified info-dict bytes are kept for .torrent rebuilds.
        self.assertEqual(meta.info_bytes, metadata)
        self.assertEqual(hashlib.sha1(meta.info_bytes).digest(), info_hash)

    async def test_fetch_lenient_noncanonical_info_dict(self):
        # A real-world peer serves an info-dict with UNSORTED keys.  It still
        # hashes to the requested infohash, so we must accept it via the
        # lenient path (raising fetch yield) WITHOUT weakening SHA-1 checking.
        raw = b"d4:name9:weird.iso6:lengthi5000ee"   # 'name' before 'length'
        info_hash = hashlib.sha1(raw).digest()
        server, host, port = await serve_metadata(raw)
        try:
            meta = await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()
        self.assertEqual(meta.name, "weird.iso")
        self.assertEqual(meta.total_size, 5000)
        self.assertEqual(meta.info_hash, info_hash)
        self.assertEqual(meta.info_bytes, raw)

    async def test_corrupt_metadata_fails_verification(self):
        info = make_info(name="bad")
        metadata = encode(info)
        info_hash = hashlib.sha1(metadata).digest()
        server, host, port = await serve_metadata(metadata, corrupt=True)
        try:
            with self.assertRaises(MetadataError):
                await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()

    async def test_wrong_infohash_fails_verification(self):
        # Even if a peer echoes our requested infohash in its handshake, the
        # metadata it serves hashes to something else -> we must reject it.
        info = make_info(name="mismatch")
        metadata = encode(info)
        server, host, port = await serve_metadata(metadata)
        try:
            with self.assertRaises(MetadataError):
                await fetch_metadata(os.urandom(20), host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()

    async def test_oversized_metadata_size_rejected(self):
        # A hostile peer advertising a huge metadata_size must be rejected
        # BEFORE the client allocates a piece list / floods piece requests.
        info_hash = os.urandom(20)
        peer_id = b"-TSRV00-" + os.urandom(12)

        async def handler(reader, writer):
            try:
                await reader.readexactly(HANDSHAKE_LEN)
                writer.write(build_handshake(info_hash, peer_id))
                await writer.drain()
                await read_message(reader)  # consume client's ext handshake
                writer.write(build_ext_handshake(metadata_size=10 ** 12,
                                                 ut_metadata_id=2))
                await writer.drain()
                await reader.read(1)        # wait for the client to hang up
            except Exception:
                pass
            finally:
                writer.close()

        server = await asyncio.start_server(handler, "127.0.0.1", 0)
        host, port = server.sockets[0].getsockname()[:2]
        try:
            with self.assertRaises(MetadataError):
                await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()

    async def test_oversized_piece_rejected(self):
        # A hostile peer advertises a legit multi-piece metadata_size but pads a
        # piece far past its BEP-9 size (up to the 1 MiB message cap). The client
        # must reject on receipt so retained memory is bounded to metadata_size,
        # not total_pieces * 1 MiB.
        info_hash = os.urandom(20)
        peer_id = b"-TSRV00-" + os.urandom(12)
        metadata_size = 40100                 # -> 3 pieces (piece 0 must be 16384)
        oversized = b"\x00" * (512 * 1024)    # 512 KiB where 16 KiB is expected

        async def handler(reader, writer):
            try:
                await reader.readexactly(HANDSHAKE_LEN)
                writer.write(build_handshake(info_hash, peer_id))
                await writer.drain()
                # consume the client's extended handshake (ext id 0)
                await read_message(reader)
                writer.write(build_ext_handshake(metadata_size=metadata_size,
                                                 ut_metadata_id=2))
                await writer.drain()
                # client asked us to use ut_metadata id 1; send an oversized
                # piece 0 with a well-formed header.
                writer.write(build_ut_metadata_data(0, metadata_size, oversized,
                                                    ext_id=1))
                await writer.drain()
                await reader.read(1)
            except Exception:
                pass
            finally:
                writer.close()

        server = await asyncio.start_server(handler, "127.0.0.1", 0)
        host, port = server.sockets[0].getsockname()[:2]
        try:
            with self.assertRaises(MetadataError):
                await fetch_metadata(info_hash, host, port, timeout=5.0)
        finally:
            server.close()
            await server.wait_closed()


if __name__ == "__main__":
    unittest.main()
