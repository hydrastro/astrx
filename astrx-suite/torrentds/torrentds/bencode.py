"""Hand-rolled bencode (BEP-3) encoder/decoder.

Supports the four bencode types: integers, byte strings, lists and
dictionaries.  Encoding is *canonical*: dictionary keys are emitted in
lexicographic (byte-wise) order and integers carry no redundant leading
zeros or negative-zero.  This module underpins KRPC (DHT), the tracker
protocols and the .torrent info-dict parsing, so it is deliberately strict.

Design notes
------------
* ``encode`` accepts ``int``, ``bytes``/``bytearray``, ``str`` (UTF-8
  encoded), ``list``/``tuple`` and ``dict``.  ``bool`` is intentionally
  rejected -- bencode has no boolean and silently coercing it hides bugs.
* ``decode`` returns ``int``, ``bytes``, ``list`` and ``dict`` (with
  ``bytes`` keys).  Strings are never auto-decoded to ``str`` because
  info-hashes and peer data are binary.
* ``decode`` is strict: trailing garbage, leading zeros, ``-0``, unsorted
  or duplicate dict keys and truncated input all raise ``BencodeError``.
  This strictness is what lets the DHT re-hash an info dict and trust the
  result.
"""

from __future__ import annotations

from typing import Any, Tuple

__all__ = ["encode", "decode", "decode_prefix", "decode_lenient", "BencodeError"]


class BencodeError(ValueError):
    """Raised on any malformed bencode input or unencodable object."""


# Maximum container nesting accepted by ``decode``.  Adversarial input can
# nest lists/dicts arbitrarily; without a bound the recursive decoder hits
# Python's recursion limit and raises ``RecursionError`` (which is *not* a
# ``ValueError``, so it would escape callers).  100 is far deeper than any
# real KRPC message or info-dict.
MAX_DEPTH = 100


# --------------------------------------------------------------------------
# Encoding
# --------------------------------------------------------------------------

def encode(obj: Any) -> bytes:
    """Serialise *obj* to canonical bencode bytes."""
    out: list[bytes] = []
    _encode(obj, out)
    return b"".join(out)


def _encode(obj: Any, out: list[bytes]) -> None:
    # bool must be checked before int (bool is a subclass of int).
    if isinstance(obj, bool):
        raise BencodeError("bencode has no boolean type")
    if isinstance(obj, int):
        out.append(b"i%de" % obj)
    elif isinstance(obj, (bytes, bytearray)):
        out.append(b"%d:" % len(obj))
        out.append(bytes(obj))
    elif isinstance(obj, str):
        data = obj.encode("utf-8")
        out.append(b"%d:" % len(data))
        out.append(data)
    elif isinstance(obj, (list, tuple)):
        out.append(b"l")
        for item in obj:
            _encode(item, out)
        out.append(b"e")
    elif isinstance(obj, dict):
        out.append(b"d")
        items = []
        for key, value in obj.items():
            if isinstance(key, str):
                key = key.encode("utf-8")
            elif isinstance(key, (bytes, bytearray)):
                key = bytes(key)
            else:
                raise BencodeError("dict keys must be bytes or str")
            items.append((key, value))
        # Canonical form: keys sorted by raw byte value; duplicates illegal.
        items.sort(key=lambda kv: kv[0])
        for i in range(1, len(items)):
            if items[i][0] == items[i - 1][0]:
                raise BencodeError("duplicate dict key: %r" % items[i][0])
        for key, value in items:
            out.append(b"%d:" % len(key))
            out.append(key)
            _encode(value, out)
        out.append(b"e")
    else:
        raise BencodeError("cannot bencode object of type %s" % type(obj).__name__)


# --------------------------------------------------------------------------
# Decoding
# --------------------------------------------------------------------------

def decode(data: bytes) -> Any:
    """Decode a complete bencode byte string.

    Raises ``BencodeError`` if *data* is malformed or has trailing bytes.
    """
    if not isinstance(data, (bytes, bytearray)):
        raise BencodeError("decode expects bytes")
    data = bytes(data)
    try:
        value, index = _decode(data, 0)
    except RecursionError as exc:
        raise BencodeError("bencode nested too deeply") from exc
    except (IndexError, ValueError) as exc:
        raise BencodeError("truncated or invalid bencode: %s" % exc) from exc
    if index != len(data):
        raise BencodeError("trailing bytes after bencode value")
    return value


def decode_prefix(data: bytes) -> Tuple[Any, int]:
    """Decode one bencode value from the front of *data*.

    Returns ``(value, bytes_consumed)`` and permits trailing bytes.  Used
    by the ut_metadata (BEP-9) *data* message, which appends raw piece
    bytes immediately after a bencoded header dict.
    """
    if not isinstance(data, (bytes, bytearray)):
        raise BencodeError("decode_prefix expects bytes")
    try:
        value, index = _decode(bytes(data), 0)
    except RecursionError as exc:
        raise BencodeError("bencode nested too deeply") from exc
    except (IndexError, ValueError) as exc:
        raise BencodeError("truncated or invalid bencode: %s" % exc) from exc
    return value, index


def _decode(data: bytes, index: int, depth: int = 0) -> Tuple[Any, int]:
    if depth > MAX_DEPTH:
        raise BencodeError("bencode nested too deeply (>%d)" % MAX_DEPTH)
    if index >= len(data):
        raise BencodeError("unexpected end of data")
    ch = data[index : index + 1]
    if ch == b"i":
        return _decode_int(data, index)
    if ch == b"l":
        return _decode_list(data, index, depth)
    if ch == b"d":
        return _decode_dict(data, index, depth)
    if ch.isdigit():
        return _decode_bytes(data, index)
    raise BencodeError("invalid token %r at position %d" % (ch, index))


def _decode_int(data: bytes, index: int) -> Tuple[int, int]:
    end = data.index(b"e", index)
    body = data[index + 1 : end]
    if body in (b"", b"-"):
        raise BencodeError("empty integer")
    neg = body.startswith(b"-")
    digits = body[1:] if neg else body
    if not digits.isdigit():
        raise BencodeError("non-numeric integer: %r" % body)
    # Reject non-canonical encodings: -0, and leading zeros like 03 / -05.
    if neg and digits == b"0":
        raise BencodeError("negative zero is not canonical")
    if len(digits) > 1 and digits[0:1] == b"0":
        raise BencodeError("leading zero is not canonical: %r" % body)
    return int(body), end + 1


def _decode_bytes(data: bytes, index: int) -> Tuple[bytes, int]:
    colon = data.index(b":", index)
    length_field = data[index:colon]
    if len(length_field) > 1 and length_field[0:1] == b"0":
        raise BencodeError("leading zero in string length: %r" % length_field)
    if not length_field.isdigit():
        raise BencodeError("invalid string length: %r" % length_field)
    length = int(length_field)
    start = colon + 1
    end = start + length
    if end > len(data):
        raise BencodeError("string longer than remaining data")
    return data[start:end], end


def _decode_list(data: bytes, index: int, depth: int = 0) -> Tuple[list, int]:
    result: list = []
    index += 1  # skip 'l'
    while True:
        if index >= len(data):
            raise BencodeError("unterminated list")
        if data[index : index + 1] == b"e":
            return result, index + 1
        value, index = _decode(data, index, depth + 1)
        result.append(value)


def _decode_dict(data: bytes, index: int, depth: int = 0) -> Tuple[dict, int]:
    result: dict = {}
    index += 1  # skip 'd'
    last_key: bytes | None = None
    while True:
        if index >= len(data):
            raise BencodeError("unterminated dict")
        if data[index : index + 1] == b"e":
            return result, index + 1
        if not data[index : index + 1].isdigit():
            raise BencodeError("dict key must be a byte string")
        key, index = _decode_bytes(data, index)
        if last_key is not None and key <= last_key:
            # Enforces both ordering and no-duplicate-keys for canonical form.
            raise BencodeError("dict keys not sorted / duplicated: %r" % key)
        last_key = key
        value, index = _decode(data, index, depth + 1)
        result[key] = value


# --------------------------------------------------------------------------
# Lenient decoding (info-dict only)
# --------------------------------------------------------------------------
#
# Real-world .torrent info-dicts produced by careless clients are sometimes
# mildly non-canonical: dict keys out of order, a duplicated key, an integer
# or string length with a redundant leading zero.  The *strict* decoder above
# rejects all of these -- which is exactly what we want for KRPC and any other
# network-facing decode -- but for the **info-dict** it just costs us fetch
# yield, because the info-dict has already been SHA-1-verified against the
# infohash on its RAW bytes (see ``metadata.assemble_and_verify``).  Relaxing
# canonical-form checks here therefore never weakens the ``sha1(info) ==
# infohash`` guarantee: verification happens on the untouched wire bytes
# *before* we ever call this.
#
# This path is used ONLY for the metadata info-dict.  It is never wired into
# ``parse_message`` / KRPC or any other decode -- those keep the strict
# decoder.  All the memory-safety bounds (MAX_DEPTH, length-vs-remaining
# checks) are preserved; only the canonical-form checks are dropped.

def decode_lenient(data: bytes) -> Any:
    """Tolerantly decode a bencode value (unsorted/dup keys, leading zeros).

    Intended solely for SHA-1-verified info-dict bytes.  Still requires the
    whole buffer to be consumed and still enforces :data:`MAX_DEPTH`.
    """
    if not isinstance(data, (bytes, bytearray)):
        raise BencodeError("decode_lenient expects bytes")
    data = bytes(data)
    try:
        value, index = _decode_lenient(data, 0)
    except RecursionError as exc:
        raise BencodeError("bencode nested too deeply") from exc
    except (IndexError, ValueError) as exc:
        raise BencodeError("truncated or invalid bencode: %s" % exc) from exc
    if index != len(data):
        raise BencodeError("trailing bytes after bencode value")
    return value


def _decode_lenient(data: bytes, index: int, depth: int = 0) -> Tuple[Any, int]:
    if depth > MAX_DEPTH:
        raise BencodeError("bencode nested too deeply (>%d)" % MAX_DEPTH)
    if index >= len(data):
        raise BencodeError("unexpected end of data")
    ch = data[index : index + 1]
    if ch == b"i":
        return _decode_int_lenient(data, index)
    if ch == b"l":
        return _decode_list_lenient(data, index, depth)
    if ch == b"d":
        return _decode_dict_lenient(data, index, depth)
    if ch.isdigit():
        return _decode_bytes_lenient(data, index)
    raise BencodeError("invalid token %r at position %d" % (ch, index))


def _decode_int_lenient(data: bytes, index: int) -> Tuple[int, int]:
    end = data.index(b"e", index)
    body = data[index + 1 : end]
    if body in (b"", b"-"):
        raise BencodeError("empty integer")
    digits = body[1:] if body.startswith(b"-") else body
    if not digits.isdigit():
        raise BencodeError("non-numeric integer: %r" % body)
    # Leading zeros / -0 tolerated here (canonical-form only, not a safety issue).
    return int(body), end + 1


def _decode_bytes_lenient(data: bytes, index: int) -> Tuple[bytes, int]:
    colon = data.index(b":", index)
    length_field = data[index:colon]
    if not length_field.isdigit():
        raise BencodeError("invalid string length: %r" % length_field)
    length = int(length_field)   # leading-zero length tolerated
    start = colon + 1
    end = start + length
    if end > len(data):
        raise BencodeError("string longer than remaining data")
    return data[start:end], end


def _decode_list_lenient(data: bytes, index: int, depth: int = 0) -> Tuple[list, int]:
    result: list = []
    index += 1  # skip 'l'
    while True:
        if index >= len(data):
            raise BencodeError("unterminated list")
        if data[index : index + 1] == b"e":
            return result, index + 1
        value, index = _decode_lenient(data, index, depth + 1)
        result.append(value)


def _decode_dict_lenient(data: bytes, index: int, depth: int = 0) -> Tuple[dict, int]:
    result: dict = {}
    index += 1  # skip 'd'
    while True:
        if index >= len(data):
            raise BencodeError("unterminated dict")
        if data[index : index + 1] == b"e":
            return result, index + 1
        if not data[index : index + 1].isdigit():
            raise BencodeError("dict key must be a byte string")
        key, index = _decode_bytes_lenient(data, index)
        value, index = _decode_lenient(data, index, depth + 1)
        # Out-of-order keys accepted; a duplicated key keeps the last value.
        result[key] = value
