"""UDP tracker (BEP-15) full connect -> announce -> scrape over loopback.

The client side is hand-rolled with struct so the wire format is validated
independently of the server implementation.
"""

import os
import socket
import struct
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.peerstore import PeerStore
from torrentds.tracker_udp import (
    ACTION_ANNOUNCE,
    ACTION_CONNECT,
    ACTION_ERROR,
    ACTION_SCRAPE,
    PROTOCOL_ID,
    UDPTracker,
)


class UDPClient:
    def __init__(self, addr):
        self.addr = addr
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.settimeout(3.0)
        self.txn = 1000

    def _txn(self):
        self.txn += 1
        return self.txn

    def close(self):
        self.sock.close()

    def connect(self):
        txn = self._txn()
        self.sock.sendto(struct.pack(">QII", PROTOCOL_ID, ACTION_CONNECT, txn), self.addr)
        data, _ = self.sock.recvfrom(4096)
        action, rtxn, conn_id = struct.unpack(">IIQ", data[:16])
        assert action == ACTION_CONNECT and rtxn == txn
        return conn_id

    def announce(self, conn_id, info_hash, port, left, event=0, num_want=-1,
                 ip_int=0):
        txn = self._txn()
        req = struct.pack(">QII", conn_id, ACTION_ANNOUNCE, txn)
        req += struct.pack(">20s20sQQQIIiiH", info_hash, b"-CLIENT0-" + b"0" * 11,
                           0, left, 0, event, ip_int, 0, num_want, port)
        self.sock.sendto(req, self.addr)
        data, _ = self.sock.recvfrom(4096)
        action, rtxn, interval, leechers, seeders = struct.unpack(">IIiii", data[:20])
        assert action == ACTION_ANNOUNCE and rtxn == txn, (action, rtxn, txn)
        peers = set()
        for off in range(20, len(data) - 5, 6):
            ip = socket.inet_ntoa(data[off:off + 4])
            p = struct.unpack(">H", data[off + 4:off + 6])[0]
            peers.add((ip, p))
        return interval, leechers, seeders, peers

    def scrape(self, conn_id, *info_hashes):
        txn = self._txn()
        req = struct.pack(">QII", conn_id, ACTION_SCRAPE, txn) + b"".join(info_hashes)
        self.sock.sendto(req, self.addr)
        data, _ = self.sock.recvfrom(4096)
        action, rtxn = struct.unpack(">II", data[:8])
        assert action == ACTION_SCRAPE and rtxn == txn
        out = []
        for off in range(8, len(data) - 11, 12):
            out.append(struct.unpack(">iii", data[off:off + 12]))  # seed,comp,leech
        return out

    def raw_announce_bad_conn(self, info_hash):
        txn = self._txn()
        req = struct.pack(">QII", 12345, ACTION_ANNOUNCE, txn)
        req += struct.pack(">20s20sQQQIIiiH", info_hash, b"x" * 20, 0, 0, 0, 0, 0, 0, -1, 1)
        self.sock.sendto(req, self.addr)
        data, _ = self.sock.recvfrom(4096)
        action, rtxn = struct.unpack(">II", data[:8])
        return action, rtxn, txn, data[8:]


class TestUDPTracker(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.store = PeerStore(interval=1800)
        cls.tracker = UDPTracker(cls.store, "127.0.0.1", 0)
        cls.tracker.start()
        cls.addr = ("127.0.0.1", cls.tracker.port)

    @classmethod
    def tearDownClass(cls):
        cls.tracker.stop()

    def test_connect_announce_scrape(self):
        client = UDPClient(self.addr)
        try:
            conn_id = client.connect()
            self.assertNotEqual(conn_id, 0)

            info_hash = bytes(range(20))
            # Peer A: leecher on port 6881, sees nobody else.
            interval, leechers, seeders, peers = client.announce(
                conn_id, info_hash, port=6881, left=100, event=2)
            self.assertEqual(interval, 1800)
            self.assertEqual((leechers, seeders), (1, 0))
            self.assertEqual(peers, set())

            # Peer B: seeder on port 6882, must see peer A compactly.
            _, leechers, seeders, peers = client.announce(
                conn_id, info_hash, port=6882, left=0, event=1)
            self.assertEqual((leechers, seeders), (1, 1))
            self.assertIn(("127.0.0.1", 6881), peers)
            self.assertNotIn(("127.0.0.1", 6882), peers)

            # Scrape reports (seeders=1, completed=1, leechers=1).
            (row,) = client.scrape(conn_id, info_hash)
            self.assertEqual(row, (1, 1, 1))
        finally:
            client.close()

    def test_bad_connection_id_errors(self):
        client = UDPClient(self.addr)
        try:
            action, rtxn, txn, msg = client.raw_announce_bad_conn(bytes(range(20)))
            self.assertEqual(action, ACTION_ERROR)
            self.assertEqual(rtxn, txn)
            self.assertIn(b"connection", msg.lower())
        finally:
            client.close()

    def test_protocol_id_required(self):
        # A connect with the wrong magic must be dropped (no reply).
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.settimeout(1.0)
        try:
            sock.sendto(struct.pack(">QII", 0xDEAD, ACTION_CONNECT, 7), self.addr)
            with self.assertRaises(socket.timeout):
                sock.recvfrom(4096)
        finally:
            sock.close()

    def test_spoofed_ip_field_uses_source_addr(self):
        # A client-supplied ip field must be IGNORED: the peer is registered
        # under the packet SOURCE address, never the spoofed victim IP.
        info_hash = bytes(range(120, 140))
        spoof = struct.unpack(">I", socket.inet_aton("8.8.8.8"))[0]
        client = UDPClient(self.addr)
        try:
            conn_id = client.connect()
            # Peer A announces claiming to be 8.8.8.8:6881.
            client.announce(conn_id, info_hash, port=6881, left=100, ip_int=spoof)
            # Peer B announces and reads the swarm back.
            *_, peers = client.announce(conn_id, info_hash, port=6882, left=0)
            self.assertIn(("127.0.0.1", 6881), peers)      # real source, not spoof
            self.assertNotIn(("8.8.8.8", 6881), peers)
        finally:
            client.close()

    def test_connection_table_bounded(self):
        # A connect flood must not grow _conns without bound.
        store = PeerStore()
        tracker = UDPTracker(store, "127.0.0.1", 0, max_conns=4)
        for i in range(50):
            tracker._new_connection_id(("10.0.0.%d" % (i % 256), 6881))
        self.assertLessEqual(len(tracker._conns), 4)

    def test_stateless_connection_id_keeps_no_state(self):
        # Stateless keyed-HMAC connection ids: no per-connection table is grown
        # (memory stays flat under a connect flood), yet a valid id is accepted
        # and a forged/other-address id is rejected.
        store = PeerStore()
        tracker = UDPTracker(store, "127.0.0.1", 0)
        tracker.start()
        try:
            client = UDPClient(("127.0.0.1", tracker.port))
            try:
                cid = client.connect()
                self.assertEqual(len(tracker._conns), 0)   # no state kept
                # Valid id issued to this source addr works for an announce.
                _, leech, seed, _ = client.announce(cid, bytes(range(20)),
                                                     port=6881, left=0)
                self.assertEqual((leech, seed), (0, 1))   # left=0 -> a seeder
                self.assertEqual(len(tracker._conns), 0)
                # A forged connection id from the same socket is rejected.
                action, rtxn, txn, msg = client.raw_announce_bad_conn(bytes(range(20)))
                self.assertEqual(action, ACTION_ERROR)
            finally:
                client.close()
        finally:
            tracker.stop()

    def test_connection_id_bound_to_source_addr(self):
        # An id minted for one source address must not validate for another.
        store = PeerStore()
        tracker = UDPTracker(store, "127.0.0.1", 0)
        a = ("10.0.0.1", 5000)
        b = ("10.0.0.2", 5000)
        cid_a = tracker._new_connection_id(a)
        self.assertTrue(tracker._valid_connection(cid_a, a))
        self.assertFalse(tracker._valid_connection(cid_a, b))

    def test_connection_id_constant_time_validation(self):
        # Validation now compares packed MACs with hmac.compare_digest.  A valid
        # id (current or previous window) is accepted; a forged or out-of-range
        # id is rejected without raising (the >Q pack must not overflow).
        import time as _time
        store = PeerStore()
        tracker = UDPTracker(store, "127.0.0.1", 0)
        addr = ("10.0.0.5", 5000)
        cid = tracker._new_connection_id(addr)
        self.assertTrue(tracker._valid_connection(cid, addr))
        self.assertFalse(tracker._valid_connection(cid ^ 1, addr))     # forged
        self.assertFalse(tracker._valid_connection(1 << 64, addr))     # too big
        self.assertFalse(tracker._valid_connection(-1, addr))          # negative
        # An id from the previous time window is still honoured (grace period).
        win = int(_time.time()) // tracker._window
        self.assertTrue(tracker._valid_connection(tracker._cid(addr, win - 1), addr))


if __name__ == "__main__":
    unittest.main()
