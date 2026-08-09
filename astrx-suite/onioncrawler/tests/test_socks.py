"""(b) SOCKS5 request encoding is correct vs the RFC1928/RFC1929 byte layout."""

import struct
import unittest

from onioncrawler import socks


class TestSocksEncoding(unittest.TestCase):
    def test_greeting_noauth(self):
        # VER=5, NMETHODS=1, METHOD=0x00
        self.assertEqual(socks.build_greeting(False), b"\x05\x01\x00")

    def test_greeting_userpass(self):
        # VER=5, NMETHODS=2, METHODS=[0x02, 0x00]
        self.assertEqual(socks.build_greeting(True), b"\x05\x02\x02\x00")

    def test_userpass_auth_layout(self):
        # RFC 1929: VER=1, ULEN, UNAME, PLEN, PASSWD
        out = socks.build_userpass_auth("user", "pass")
        self.assertEqual(out, b"\x01\x04user\x04pass")
        self.assertEqual(out[0], 0x01)
        self.assertEqual(out[1], 4)
        self.assertEqual(out[2:6], b"user")
        self.assertEqual(out[6], 4)
        self.assertEqual(out[7:], b"pass")

    def test_connect_request_domain_layout(self):
        # RFC 1928: VER, CMD=CONNECT, RSV, ATYP=DOMAIN, LEN, HOST, PORT(2 BE)
        host = "duckduckgogg42xjoc72x3sjasowoarfbgcmvfimaftt6twagswzczad.onion"
        req = socks.build_connect_request(host, 80)
        self.assertEqual(req[0], 0x05)          # VER
        self.assertEqual(req[1], 0x01)          # CMD = CONNECT
        self.assertEqual(req[2], 0x00)          # RSV
        self.assertEqual(req[3], 0x03)          # ATYP = DOMAINNAME
        self.assertEqual(req[4], len(host))     # domain length
        self.assertEqual(req[5:5 + len(host)], host.encode("ascii"))
        self.assertEqual(req[5 + len(host):], struct.pack("!H", 80))

    def test_connect_port_big_endian(self):
        req = socks.build_connect_request("x.onion", 8443)
        self.assertEqual(req[-2:], b"\x20\xfb")  # 8443 = 0x20FB
        self.assertEqual(struct.unpack("!H", req[-2:])[0], 8443)

    def test_connect_rejects_bad_port(self):
        with self.assertRaises(socks.SocksError):
            socks.build_connect_request("x.onion", 0)
        with self.assertRaises(socks.SocksError):
            socks.build_connect_request("x.onion", 70000)

    def test_hostname_never_resolved_locally(self):
        # A .onion has no A record; we must send it as a DOMAINNAME (ATYP 3),
        # never an IPv4/IPv6 literal. Confirm ATYP is always 0x03.
        req = socks.build_connect_request("z" * 56 + ".onion", 80)
        self.assertEqual(req[3], 0x03)


if __name__ == "__main__":
    unittest.main()
