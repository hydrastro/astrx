"""Regression: DHT bootstrap must resolve a hostname router to a numeric IP
BEFORE querying it. The KRPC layer drops any response whose source address does
not equal the query's stored destination (anti off-path injection). A hostname
destination never equals the reply's numeric source, so an unresolved-hostname
bootstrap would get every reply discarded as spoofed and the node would never
learn a peer (indexer dead on cold start). This is offline: `localhost` resolves
via the local resolver, no network."""
import asyncio
import unittest

from torrentds.dht import DHTNode


class TestBootstrapResolve(unittest.TestCase):
    def test_bootstrap_resolves_hostname_before_query(self):
        async def go():
            node = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
            node.bootstrap_nodes = [("localhost", 6881)]
            seen = []

            async def rec(node_id, addr):
                seen.append(addr)

            node.find_node = rec  # type: ignore[assignment]
            await node.bootstrap_once()
            return seen

        seen = asyncio.run(go())
        self.assertTrue(seen, "bootstrap_once did not issue a find_node")
        self.assertEqual(
            seen[0][0], "127.0.0.1",
            "bootstrap must resolve the hostname router to a numeric IP before "
            "querying; otherwise krpc._match drops the reply as spoofed",
        )


if __name__ == "__main__":
    unittest.main()
