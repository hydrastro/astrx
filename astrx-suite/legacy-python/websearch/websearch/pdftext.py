"""Optional, best-effort, stdlib-only PDF text extraction.

Deliberately minimal and honest about it.  It inflates ``FlateDecode`` content
streams (``zlib`` is stdlib) and pulls text out of the ``(...)`` / ``<...>``
string operands of the text-showing operators inside ``BT``/``ET`` blocks --
which covers the common "text-first" PDF produced by ordinary tooling.

It does NOT implement font encodings / CID fonts, embedded-image OCR, or
encrypted PDFs; for those it returns whatever plain text it can recover, or
``""``.  Because coverage is partial, the crawler keeps PDF indexing OFF by
default -- opt in with ``CrawlConfig(index_pdf=True)``.  Nothing here fakes a
result: if extraction finds no text, the page is simply skipped.
"""

import re
import zlib

# Hard caps so a crafted PDF cannot burn unbounded CPU.  There is a *per-stream*
# inflated-byte ceiling AND an *aggregate* budget across ALL streams (both total
# inflated/scanned bytes and stream count), enforced regardless of whether any
# text is recovered -- a bomb of many near-cap FlateDecode streams that yields no
# extractable text must still terminate quickly (see extract_text).
_MAX_STREAM = 8_000_000          # per-stream inflated-byte ceiling
_STREAM_COUNT_CAP = 4096         # max content streams inspected per document

_STREAM = re.compile(rb"stream\r?\n(.*?)\r?\nendstream", re.DOTALL)
_OCTAL = {
    ord("n"): b"\n", ord("r"): b"\r", ord("t"): b"\t", ord("b"): b"\b",
    ord("f"): b"\f", ord("("): b"(", ord(")"): b")", ord("\\"): b"\\",
}


def _inflate(data, cap):
    try:
        return zlib.decompressobj().decompress(data, cap)
    except zlib.error:
        return b""


def _content_streams(pdf, max_total=_MAX_STREAM * 2):
    """Yield decoded stream bodies that look like page content streams.

    Stops once *max_total* cumulative inflated/scanned bytes (or the stream-count
    cap) is reached, and shrinks each per-stream inflate cap to the remaining
    budget.  So a PDF packed with many near-cap compression-bomb streams cannot
    force unbounded CPU even when none of them yield any extractable text.
    """
    # Linear O(n) scan via bytes.find (memchr-fast). The old re.finditer with a
    # lazy `.*?` was O(n^2) when `endstream` is absent — it re-scanned to EOF at
    # every `stream` offset while yielding zero matches, so the budget checks
    # below (inside the loop) never ran. A `find`-based walk terminates in one
    # pass and never rescans, regardless of whether any `endstream` exists.
    produced = 0
    seen = 0
    pos = 0
    n = len(pdf)
    while produced < max_total and seen < _STREAM_COUNT_CAP:
        s = pdf.find(b"stream", pos)
        if s < 0:
            break
        j = s + 6                       # past the 'stream' keyword
        if pdf[j:j + 1] == b"\r":
            j += 1
        if pdf[j:j + 1] == b"\n":       # a real stream keyword is followed by EOL
            j += 1
        else:                           # e.g. the 'stream' inside 'endstream'
            pos = s + 6
            continue
        e = pdf.find(b"endstream", j)   # first (shortest match = old non-greedy)
        if e < 0:
            break                       # unterminated stream — stop, don't rescan
        body_end = e
        if pdf[body_end - 1:body_end] == b"\n":
            body_end -= 1
        if pdf[body_end - 1:body_end] == b"\r":
            body_end -= 1
        raw = pdf[j:body_end]
        pos = e + 9                     # past 'endstream'
        seen += 1
        head = pdf[max(0, s - 256):s]
        if b"/FlateDecode" in head:
            body = _inflate(raw, min(_MAX_STREAM, max_total - produced + 1))
        else:
            body = raw
        produced += len(body)
        if body and (b"BT" in body or b"Tj" in body or b"TJ" in body):
            yield body


def _read_literal(buf, i):
    """Read a balanced ``(...)`` string literal starting at ``buf[i] == '('``.

    Returns ``(decoded_bytes, next_index)``.  Honours ``\\`` escapes, octal
    escapes and nested parentheses (both legal in PDF strings).
    """
    depth = 0
    out = bytearray()
    n = len(buf)
    while i < n:
        c = buf[i]
        if c == 0x5C:  # backslash
            i += 1
            if i >= n:
                break
            e = buf[i]
            if e in _OCTAL:
                out += _OCTAL[e]
                i += 1
            elif 0x30 <= e <= 0x37:  # up to 3 octal digits
                j = i
                digits = b""
                while j < n and len(digits) < 3 and 0x30 <= buf[j] <= 0x37:
                    digits += bytes((buf[j],))
                    j += 1
                out += bytes((int(digits, 8) & 0xFF,))
                i = j
            else:
                out += bytes((e,))
                i += 1
            continue
        if c == 0x28:  # (
            depth += 1
            out += b"("
            i += 1
            continue
        if c == 0x29:  # )
            if depth == 0:
                return bytes(out), i + 1
            depth -= 1
            out += b")"
            i += 1
            continue
        out += bytes((c,))
        i += 1
    return bytes(out), i


def _extract_from_stream(body):
    parts = []
    i = 0
    n = len(body)
    while i < n:
        c = body[i]
        if c == 0x28:  # (  -> literal string
            s, i = _read_literal(body, i + 1)
            if s:
                parts.append(s.decode("latin-1", "replace"))
            continue
        if c == 0x3C and i + 1 < n and body[i + 1] != 0x3C:  # <hex> (not <<)
            j = body.find(b">", i + 1)
            if j == -1:
                break
            hexs = bytes(ch for ch in body[i + 1:j]
                         if ch not in b" \r\n\t")
            try:
                if len(hexs) % 2:
                    hexs += b"0"
                parts.append(bytes.fromhex(hexs.decode("ascii", "ignore"))
                             .decode("latin-1", "replace"))
            except ValueError:
                pass
            i = j + 1
            continue
        i += 1
    return parts


def extract_text(data, max_chars=2_000_000):
    """Return best-effort extracted text from PDF *data* (bytes)."""
    if not data or b"%PDF" not in data[:1024]:
        return ""
    # Aggregate inflate/scan budget across ALL streams (not merely per stream),
    # so a bomb that yields no text still terminates in bounded time.  Generous
    # enough for real text-first PDFs, which hit the max_chars break first.
    inflate_budget = max(8 * max_chars, _MAX_STREAM)
    pieces = []
    total = 0
    for body in _content_streams(data, inflate_budget):
        for frag in _extract_from_stream(body):
            frag = frag.strip()
            if not frag:
                continue
            pieces.append(frag)
            total += len(frag) + 1
            if total >= max_chars:
                break
        if total >= max_chars:
            break
    text = " ".join(pieces)
    return re.sub(r"\s+", " ", text).strip()[:max_chars]


def extract_title(data):
    """Best-effort document ``/Title`` (empty string if absent)."""
    m = re.search(rb"/Title\s*\(((?:\\.|[^\\()])*)\)", data or b"")
    if not m:
        return ""
    raw, _ = _read_literal(b"(" + m.group(1) + b")", 1)
    return re.sub(r"\s+", " ", raw.decode("latin-1", "replace")).strip()
