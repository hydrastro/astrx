"""Node-ID / XOR-distance / k-bucket / compact-codec tests."""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.routing import (
    ID_BYTES,
    KBucket,
    Node,
    RoutingTable,
    bucket_index,
    decode_endpoint,
    decode_nodes,
    decode_peers,
    distance,
    encode_endpoint,
    encode_nodes,
    encode_peers,
    random_node_id,
)


def nid(*prefix: int) -> bytes:
    b = bytes(prefix)
    return b + b"\x00" * (ID_BYTES - len(b))


class TestDistance(unittest.TestCase):
    def test_random_id_length(self):
        self.assertEqual(len(random_node_id()), 20)
        self.assertNotEqual(random_node_id(), random_node_id())

    def test_distance_properties(self):
        a, b, c = random_node_id(), random_node_id(), random_node_id()
        self.assertEqual(distance(a, a), 0)          # identity
        self.assertEqual(distance(a, b), distance(b, a))  # symmetry
        # Triangle inequality for XOR metric: d(a,c) <= d(a,b) ^ d(b,c) ... use <=+
        self.assertTrue(distance(a, c) <= distance(a, b) + distance(b, c))

    def test_known_distance(self):
        self.assertEqual(distance(nid(0x00), nid(0xFF)), 0xFF << (19 * 8))
        self.assertEqual(distance(nid(0x01), nid(0x03)), 0x02 << (19 * 8))

    def test_bucket_index(self):
        me = nid(0x00)
        self.assertEqual(bucket_index(me, me), -1)             # same id
        self.assertEqual(bucket_index(me, nid(0x00, 0x01)),
                         (0x01 << (18 * 8)).bit_length() - 1)
        # A node differing in the top bit lands in the highest bucket.
        self.assertEqual(bucket_index(me, nid(0x80)), 159)
        self.assertEqual(bucket_index(me, nid(0x01)), 152)


class TestKBucket(unittest.TestCase):
    def test_add_and_refresh(self):
        b = KBucket(k=2)
        n1 = Node(nid(1), "1.1.1.1", 1)
        n2 = Node(nid(2), "2.2.2.2", 2)
        self.assertTrue(b.add(n1))
        self.assertTrue(b.add(n2))
        self.assertEqual(len(b), 2)
        # Full bucket rejects a new node.
        self.assertFalse(b.add(Node(nid(3), "3.3.3.3", 3)))
        # Re-adding an existing node refreshes and moves it to the tail.
        self.assertTrue(b.add(Node(nid(1), "1.1.1.9", 9)))
        self.assertEqual(b.nodes[-1].id, nid(1))
        self.assertEqual(b.nodes[-1].port, 9)  # endpoint updated

    def test_remove(self):
        b = KBucket(k=4)
        b.add(Node(nid(1), "1.1.1.1", 1))
        b.remove(nid(1))
        self.assertEqual(len(b), 0)


class TestRoutingTable(unittest.TestCase):
    def test_add_and_find_closest(self):
        me = nid(0x00)
        rt = RoutingTable(me, k=8)
        for i in range(1, 40):
            rt.add_node(Node(nid(i), "10.0.0.%d" % i, 1000 + i))
        target = nid(0x05)
        closest = rt.find_closest(target, 4)
        self.assertEqual(len(closest), 4)
        # Results must be sorted by XOR distance to the target.
        dists = [distance(n.id, target) for n in closest]
        self.assertEqual(dists, sorted(dists))
        self.assertEqual(closest[0].id, nid(0x05))  # exact match is closest

    def test_never_stores_self(self):
        me = nid(0x00)
        rt = RoutingTable(me)
        self.assertFalse(rt.add_node(Node(me, "1.2.3.4", 5)))
        self.assertEqual(len(rt), 0)


class TestCompactCodecs(unittest.TestCase):
    def test_endpoint_round_trip(self):
        blob = encode_endpoint("87.98.162.88", 6881)
        self.assertEqual(len(blob), 6)
        self.assertEqual(decode_endpoint(blob), ("87.98.162.88", 6881))

    def test_nodes_round_trip(self):
        nodes = [Node(random_node_id(), "1.2.3.4", 6881),
                 Node(random_node_id(), "5.6.7.8", 51413)]
        blob = encode_nodes(nodes)
        self.assertEqual(len(blob), 52)
        got = decode_nodes(blob)
        self.assertEqual([(n.id, n.host, n.port) for n in got],
                         [(n.id, n.host, n.port) for n in nodes])

    def test_nodes_ragged_tail_dropped(self):
        blob = encode_nodes([Node(random_node_id(), "1.2.3.4", 1)]) + b"\x00\x00"
        self.assertEqual(len(decode_nodes(blob)), 1)

    def test_peers_round_trip(self):
        peers = [("10.0.0.1", 6881), ("10.0.0.2", 51413)]
        values = encode_peers(peers)
        self.assertEqual([len(v) for v in values], [6, 6])
        self.assertEqual(decode_peers(values), peers)


if __name__ == "__main__":
    unittest.main()
