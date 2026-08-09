"""Bencode round-trip and malformed-input tests."""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import BencodeError, decode, decode_lenient, encode


class TestBencodeEncode(unittest.TestCase):
    def test_integers(self):
        self.assertEqual(encode(0), b"i0e")
        self.assertEqual(encode(42), b"i42e")
        self.assertEqual(encode(-42), b"i-42e")
        self.assertEqual(encode(10 ** 30), b"i" + str(10 ** 30).encode() + b"e")

    def test_byte_and_text_strings(self):
        self.assertEqual(encode(b"spam"), b"4:spam")
        self.assertEqual(encode(b""), b"0:")
        self.assertEqual(encode("spam"), b"4:spam")
        # UTF-8 text encodes by byte length, not character count.
        self.assertEqual(encode("é"), b"2:\xc3\xa9")

    def test_list(self):
        self.assertEqual(encode([b"spam", 42]), b"l4:spami42ee")
        self.assertEqual(encode([]), b"le")

    def test_dict_is_sorted(self):
        # Keys given out of order must be emitted sorted (canonical form).
        self.assertEqual(encode({b"b": 1, b"a": 2}), b"d1:ai2e1:bi1ee")
        self.assertEqual(encode({}), b"de")

    def test_str_keys_coerced(self):
        self.assertEqual(encode({"cow": b"moo", "spam": b"eggs"}),
                         b"d3:cow3:moo4:spam4:eggse")

    def test_bool_rejected(self):
        with self.assertRaises(BencodeError):
            encode(True)

    def test_unencodable_rejected(self):
        with self.assertRaises(BencodeError):
            encode(1.5)
        with self.assertRaises(BencodeError):
            encode(None)

    def test_duplicate_key_rejected(self):
        # str "a" and bytes b"a" collide after coercion.
        with self.assertRaises(BencodeError):
            encode({"a": 1, b"a": 2})


class TestBencodeDecode(unittest.TestCase):
    def test_integers(self):
        self.assertEqual(decode(b"i42e"), 42)
        self.assertEqual(decode(b"i-42e"), -42)
        self.assertEqual(decode(b"i0e"), 0)

    def test_strings(self):
        self.assertEqual(decode(b"4:spam"), b"spam")
        self.assertEqual(decode(b"0:"), b"")

    def test_list(self):
        self.assertEqual(decode(b"l4:spami42ee"), [b"spam", 42])
        self.assertEqual(decode(b"le"), [])

    def test_dict(self):
        self.assertEqual(decode(b"d3:cow3:moo4:spam4:eggse"),
                         {b"cow": b"moo", b"spam": b"eggs"})
        self.assertEqual(decode(b"de"), {})

    def test_nested(self):
        blob = b"d1:ad1:bli1ei2eee1:cle e"  # note: has a space -> trailing check
        # Build a clean nested structure instead.
        obj = {b"a": {b"b": [1, 2]}, b"c": []}
        self.assertEqual(decode(encode(obj)), obj)


class TestBencodeRoundTrip(unittest.TestCase):
    def test_round_trip_samples(self):
        samples = [
            0,
            -1,
            123456789,
            b"",
            b"\x00\x01\x02\xff",
            [b"a", b"b", [1, [2, [3]]]],
            {b"name": b"ubuntu.iso", b"length": 12345, b"files": [
                {b"path": [b"a", b"b"], b"length": 10}]},
            {},
            [],
        ]
        for obj in samples:
            self.assertEqual(decode(encode(obj)), obj, obj)

    def test_encode_is_deterministic(self):
        # Same logical dict, different insertion order -> identical bytes.
        a = encode({b"z": 1, b"a": 2, b"m": 3})
        b = encode({b"a": 2, b"m": 3, b"z": 1})
        self.assertEqual(a, b)


class TestBencodeMalformed(unittest.TestCase):
    def _bad(self, data):
        with self.assertRaises(BencodeError):
            decode(data)

    def test_trailing_bytes(self):
        self._bad(b"i42eX")
        self._bad(b"4:spamX")

    def test_bad_integers(self):
        self._bad(b"ie")
        self._bad(b"i-e")
        self._bad(b"i-0e")       # negative zero
        self._bad(b"i03e")       # leading zero
        self._bad(b"i4 2e")
        self._bad(b"iabce")
        self._bad(b"i42")        # unterminated

    def test_bad_strings(self):
        self._bad(b"5:spam")     # length exceeds data
        self._bad(b"01:a")       # leading zero length
        self._bad(b"-1:a")       # negative length is not a digit token
        self._bad(b"4")          # no colon

    def test_bad_containers(self):
        self._bad(b"l")          # unterminated list
        self._bad(b"d")          # unterminated dict
        self._bad(b"li1e")       # list missing end
        self._bad(b"d1:a")       # dict key without value

    def test_dict_key_ordering_enforced(self):
        self._bad(b"d1:bi1e1:ai2ee")   # keys out of order
        self._bad(b"d1:ai1e1:ai2ee")   # duplicate keys
        self._bad(b"di1ei2ee")         # non-string key

    def test_wrong_type(self):
        with self.assertRaises(BencodeError):
            decode("not bytes")

    def test_deeply_nested_rejected(self):
        # Adversarial container nesting must raise BencodeError, never
        # RecursionError (which is not a ValueError and would escape callers).
        self._bad(b"l" * 5000)                    # unterminated nested lists
        self._bad(b"l" * 5000 + b"e" * 5000)      # well-formed but too deep
        self._bad(b"d1:a" * 5000)                 # deeply nested dicts
        # Sanity: a modest, legal nesting still decodes.
        self.assertEqual(decode(b"l" * 10 + b"e" * 10), [[[[[[[[[[]]]]]]]]]])


class TestBencodeLenient(unittest.TestCase):
    """Lenient info-dict decode: accepts mildly non-canonical real-world data.

    The strict decoder MUST keep rejecting these (it guards KRPC/network);
    only the separate lenient path tolerates them.
    """

    def test_lenient_accepts_unsorted_keys(self):
        # 'name' before 'length' -> keys out of canonical order.
        raw = b"d4:name3:abc6:lengthi100ee"
        with self.assertRaises(BencodeError):
            decode(raw)                          # strict still rejects
        d = decode_lenient(raw)
        self.assertEqual(d[b"name"], b"abc")
        self.assertEqual(d[b"length"], 100)

    def test_lenient_accepts_duplicate_key_last_wins(self):
        raw = b"d1:ai1e1:ai2ee"
        with self.assertRaises(BencodeError):
            decode(raw)
        self.assertEqual(decode_lenient(raw), {b"a": 2})

    def test_lenient_accepts_leading_zeros(self):
        with self.assertRaises(BencodeError):
            decode(b"i03e")
        self.assertEqual(decode_lenient(b"i03e"), 3)

    def test_lenient_still_bounds_depth(self):
        # Memory-safety bounds are preserved even in the lenient path.
        with self.assertRaises(BencodeError):
            decode_lenient(b"l" * 5000 + b"e" * 5000)

    def test_lenient_rejects_true_garbage_and_trailing(self):
        with self.assertRaises(BencodeError):
            decode_lenient(b"not-bencode")
        with self.assertRaises(BencodeError):
            decode_lenient(b"i42eX")            # trailing bytes still rejected

    def test_lenient_matches_strict_on_canonical(self):
        obj = {b"a": {b"b": [1, 2]}, b"c": [], b"name": b"x"}
        blob = encode(obj)
        self.assertEqual(decode_lenient(blob), obj)


if __name__ == "__main__":
    unittest.main()
