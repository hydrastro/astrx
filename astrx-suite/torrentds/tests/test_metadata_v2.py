"""BEP-52 v2 / hybrid torrents: infohash, file tree, magnets, verify."""

import hashlib
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import encode
from torrentds.metadata import (
    MetadataError,
    assemble_and_verify_v2,
    fetch_metadata,
    infohash_of,
    infohash_v2_of,
    is_hybrid_info,
    is_v2_info,
    parse_info,
    parse_magnet,
    serve_metadata,
    truncate_v2,
    verify_v2,
    walk_file_tree,
)


def leaf(length, root=None):
    return {b"": {b"length": length, b"pieces root": root or (b"\x00" * 32)}}


def v2_info(name="test", piece_length=65536, tree=None):
    return {
        b"meta version": 2,
        b"name": name.encode(),
        b"piece length": piece_length,
        b"file tree": tree if tree is not None else {b"a.txt": leaf(12)},
    }


class TestV2Infohash(unittest.TestCase):
    def test_v2_infohash_is_sha256_of_info(self):
        info = v2_info()
        raw = encode(info)
        self.assertEqual(infohash_v2_of(info), hashlib.sha256(raw).digest())
        # Pin the exact bytes so the encoding + hashing can never silently drift.
        self.assertEqual(
            infohash_v2_of(info).hex(),
            "b1d110e714989ea05ce37f9bc17210f353ba48609a63b3675d74272d96640b72")

    def test_truncated_form_is_first_20_bytes(self):
        full = infohash_v2_of(v2_info())
        self.assertEqual(len(full), 32)
        self.assertEqual(truncate_v2(full), full[:20])
        self.assertEqual(len(truncate_v2(full)), 20)

    def test_parse_v2_only(self):
        info = v2_info(tree={b"dir": {b"b.bin": leaf(2000)}, b"a.txt": leaf(12)})
        meta = parse_info(info)
        self.assertEqual(meta.version, "v2")
        self.assertEqual(meta.info_hash_v2, infohash_v2_of(info))
        # v2-only: primary (DHT) infohash is the truncated SHA-256.
        self.assertEqual(meta.info_hash, truncate_v2(infohash_v2_of(info)))
        self.assertEqual(sorted(meta.files), [("a.txt", 12), ("dir/b.bin", 2000)])
        self.assertEqual(meta.total_size, 2012)


class TestHybrid(unittest.TestCase):
    def test_hybrid_has_both_infohashes(self):
        info = v2_info()
        # A hybrid dict additionally carries the v1 pieces (+ length/files).
        info[b"pieces"] = b"\x00" * 20
        info[b"length"] = 12
        self.assertTrue(is_hybrid_info(info))
        meta = parse_info(info)
        self.assertEqual(meta.version, "hybrid")
        # v1 = SHA-1, v2 = SHA-256, both over the SAME info-dict bytes.
        self.assertEqual(meta.info_hash, infohash_of(info))
        self.assertEqual(meta.info_hash_v2, infohash_v2_of(info))
        self.assertEqual(len(meta.info_hash), 20)
        self.assertEqual(len(meta.info_hash_v2), 32)

    def test_v1_only_is_not_v2(self):
        info = {b"name": b"x", b"piece length": 16384,
                b"pieces": b"\x00" * 20, b"length": 5}
        self.assertFalse(is_v2_info(info))
        self.assertEqual(parse_info(info).version, "v1")


class TestFileTree(unittest.TestCase):
    def test_walk_nested_paths(self):
        tree = {b"season1": {b"ep1.mkv": leaf(100), b"ep2.mkv": leaf(200)},
                b"readme.txt": leaf(5)}
        files = walk_file_tree(tree)
        self.assertEqual(sorted(files),
                         [("readme.txt", 5), ("season1/ep1.mkv", 100),
                          ("season1/ep2.mkv", 200)])

    def test_depth_bomb_rejected(self):
        node = leaf(1)
        for i in range(200):
            node = {b"d%d" % i: node}
        with self.assertRaises(MetadataError):
            walk_file_tree(node)

    def test_node_count_bomb_rejected(self):
        tree = {b"f%d" % i: leaf(1) for i in range(50)}
        with self.assertRaises(MetadataError):
            walk_file_tree(tree, max_nodes=5)


class TestMagnet(unittest.TestCase):
    def test_btmh_v2_magnet(self):
        full = infohash_v2_of(v2_info())
        mag = "magnet:?xt=urn:btmh:1220%s&dn=test" % full.hex()
        m = parse_magnet(mag)
        self.assertIsNone(m.v1_infohash)
        self.assertEqual(m.v2_infohash, full)
        self.assertEqual(m.name, "test")
        self.assertEqual(m.dht_infohash, full[:20])

    def test_btih_v1_magnet(self):
        ih = os.urandom(20)
        m = parse_magnet("magnet:?xt=urn:btih:%s" % ih.hex())
        self.assertEqual(m.v1_infohash, ih)
        self.assertIsNone(m.v2_infohash)
        self.assertEqual(m.dht_infohash, ih)

    def test_hybrid_magnet_has_both(self):
        v1 = os.urandom(20)
        v2 = infohash_v2_of(v2_info())
        mag = ("magnet:?xt=urn:btih:%s&xt=urn:btmh:1220%s"
               % (v1.hex(), v2.hex()))
        m = parse_magnet(mag)
        self.assertEqual(m.v1_infohash, v1)
        self.assertEqual(m.v2_infohash, v2)

    def test_reject_wrong_multihash_prefix(self):
        # 0x12 0x30 (length 48) is not a 32-byte sha2-256 multihash.
        with self.assertRaises(MetadataError):
            parse_magnet("magnet:?xt=urn:btmh:1230%s" % ("00" * 32))

    def test_reject_short_multihash(self):
        with self.assertRaises(MetadataError):
            parse_magnet("magnet:?xt=urn:btmh:1220%s" % ("00" * 20))

    def test_reject_non_hex_multihash(self):
        with self.assertRaises(MetadataError):
            parse_magnet("magnet:?xt=urn:btmh:12" + "zz" * 33)

    def test_reject_no_xt(self):
        with self.assertRaises(MetadataError):
            parse_magnet("magnet:?dn=nothing")

    def test_reject_btih_hex_with_internal_whitespace(self):
        # L5: 13 hex pairs + internal spaces = 40 chars but only 13 bytes.
        # bytes.fromhex silently drops the whitespace; the length check rejects.
        xt = "ab" + " ab" * 12          # 38 chars, 13 pairs
        xt = xt[:5] + "  " + xt[5:]      # inject 2 internal spaces -> 40 chars
        self.assertEqual(len(xt), 40)
        self.assertFalse(xt[0].isspace() or xt[-1].isspace())
        with self.assertRaises(MetadataError):
            parse_magnet("magnet:?xt=urn:btih:" + xt)

    def test_accept_clean_40hex_btih(self):
        ih = os.urandom(20)
        m = parse_magnet("magnet:?xt=urn:btih:%s" % ih.hex())
        self.assertEqual(m.v1_infohash, ih)


class TestV2InfohashVerification(unittest.TestCase):
    """L7: parse_v2_info verifies the recomputed hash against a requested one."""

    def test_matching_truncated_dht_infohash_accepted(self):
        info = v2_info()
        raw = encode(info)
        full = hashlib.sha256(raw).digest()
        meta = parse_info(info, info_hash=full[:20], info_bytes=raw)
        self.assertEqual(meta.info_hash, full[:20])

    def test_matching_full_dht_infohash_accepted(self):
        info = v2_info()
        raw = encode(info)
        full = hashlib.sha256(raw).digest()
        meta = parse_info(info, info_hash=full, info_bytes=raw)
        self.assertEqual(meta.info_hash_v2, full)

    def test_mismatching_dht_infohash_rejected(self):
        info = v2_info()
        raw = encode(info)
        with self.assertRaises(MetadataError):
            parse_info(info, info_hash=b"\x00" * 20, info_bytes=raw)

    def test_hybrid_v1_dht_infohash_accepted(self):
        info = v2_info()
        info[b"pieces"] = b"\x00" * 20
        info[b"length"] = 12
        raw = encode(info)
        v1 = hashlib.sha1(raw).digest()
        meta = parse_info(info, info_hash=v1, info_bytes=raw)
        self.assertEqual(meta.version, "hybrid")
        self.assertEqual(meta.info_hash, v1)

    def test_hybrid_wrong_dht_infohash_rejected(self):
        info = v2_info()
        info[b"pieces"] = b"\x00" * 20
        info[b"length"] = 12
        raw = encode(info)
        with self.assertRaises(MetadataError):
            parse_info(info, info_hash=b"\xff" * 20, info_bytes=raw)

    def test_no_requested_hash_still_parses(self):
        # Back-compat: without a requested infohash nothing is compared.
        info = v2_info()
        meta = parse_info(info)
        self.assertEqual(meta.version, "v2")


class TestVerifyV2(unittest.TestCase):
    def test_verify_full_and_truncated(self):
        raw = encode(v2_info())
        full = hashlib.sha256(raw).digest()
        self.assertTrue(verify_v2(raw, full))          # full 32-byte
        self.assertTrue(verify_v2(raw, full[:20]))     # truncated DHT form

    def test_verify_rejects_tampered_dict(self):
        info = v2_info()
        raw = encode(info)
        full = hashlib.sha256(raw).digest()
        tampered = raw.replace(b"a.txt", b"b.txt")
        self.assertNotEqual(tampered, raw)
        self.assertFalse(verify_v2(tampered, full))
        self.assertFalse(verify_v2(tampered, full[:20]))

    def test_verify_rejects_bad_length(self):
        raw = encode(v2_info())
        self.assertFalse(verify_v2(raw, b"\x00" * 16))

    def test_assemble_and_verify_v2(self):
        raw = encode(v2_info())
        full = hashlib.sha256(raw).digest()
        pieces = [raw[i:i + 16384] for i in range(0, len(raw), 16384)] or [raw]
        self.assertEqual(assemble_and_verify_v2(pieces, full), raw)
        self.assertIsNone(assemble_and_verify_v2(pieces, os.urandom(32)))


class TestV2Loopback(unittest.IsolatedAsyncioTestCase):
    async def test_fetch_v2_metadata_sha256_verified(self):
        info = v2_info(name="v2loop",
                       tree={b"a.dat": leaf(10), b"b.dat": leaf(20)})
        metadata = encode(info)
        full = hashlib.sha256(metadata).digest()
        dht20 = full[:20]
        server, host, port = await serve_metadata(metadata)
        try:
            meta = await fetch_metadata(dht20, host, port, timeout=5.0,
                                        info_hash_v2=full)
        finally:
            server.close()
            await server.wait_closed()
        self.assertEqual(meta.version, "v2")
        self.assertEqual(meta.info_hash_v2, full)
        self.assertEqual(meta.info_hash, dht20)
        self.assertEqual(meta.total_size, 30)
        self.assertEqual(meta.info_bytes, metadata)

    async def test_fetch_v2_rejects_tampered(self):
        info = v2_info(name="bad")
        metadata = encode(info)
        full = hashlib.sha256(metadata).digest()
        # Serve corrupted bytes -> SHA-256 verification must fail.
        server, host, port = await serve_metadata(metadata, corrupt=True)
        try:
            with self.assertRaises(MetadataError):
                await fetch_metadata(full[:20], host, port, timeout=5.0,
                                     info_hash_v2=full)
        finally:
            server.close()
            await server.wait_closed()


if __name__ == "__main__":
    unittest.main()
