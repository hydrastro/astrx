#!/usr/bin/env python3
"""Regenerate the byte-for-byte cross-check goldens for `websearch::pdftext`.

Run from the `astrx-suite` workspace root:

    PYTHONPATH=legacy-python/websearch python3 \\
        crates/websearch/tests/regen_pdftext_goldens.py \\
        > crates/websearch/tests/xcheck_pdftext.rs

It builds a set of minimal PDF byte blobs (including a FlateDecode-compressed
content stream), drives the *real* Python `websearch.pdftext` on those exact
bytes to produce the expected `extract_text` / `extract_title` outputs, and
prints a self-contained Rust integration test. Compressed fixtures are built
with `zlib.compress(...)` and the resulting bytes embedded (as hex) so BOTH the
Python golden and the Rust test see byte-identical compressed input.

Everything crossing the Rust boundary is hex-encoded: the PDF input bytes, and
the UTF-8 encoding of each expected (latin-1) output string. That keeps control
characters, quotes and non-ASCII bytes unambiguous on both sides.
"""

import sys
import zlib

from websearch import pdftext

DEFAULT_MAX_CHARS = 2_000_000


def obj_stream(body: bytes, flate: bool) -> bytes:
    """A single indirect object carrying `body` as a (maybe /FlateDecode) stream.

    Uses `stream\\n<body>\\nendstream`; the parser trims the one separator
    newline, so `<body>` is recovered exactly.
    """
    filt = b"/FlateDecode " if flate else b""
    return (
        b"4 0 obj\n<< " + filt + b"/Length " + str(len(body)).encode() + b" >>\n"
        b"stream\n" + body + b"\nendstream\nendobj\n"
    )


def flate_body(content: bytes) -> bytes:
    """zlib-compress `content`, guaranteeing the result neither contains
    `endstream` (which would truncate the `find`) nor ends in `\\r` (which the
    trailing-`\\r` trim would otherwise strip from the stream body)."""
    pad = b""
    for _ in range(64):
        body = zlib.compress(content + pad)
        if b"endstream" not in body and body[-1:] != b"\r":
            return body
        pad += b" "
    raise SystemExit("could not find a safe zlib.compress padding")


def pdf(objects: bytes, header: bytes = b"%PDF-1.4\n") -> bytes:
    return header + objects + b"%%EOF\n"


# --- Fixtures ---------------------------------------------------------------
# Each entry: (name, pdf_bytes, max_chars).  The content stream text mixes the
# three operand forms the extractor understands: `(...)` literals, a `TJ` array
# of literals, and a `<...>` hex string.

FIXTURES = []

# 1) FlateDecode-compressed content stream: literal + TJ array + hex string.
_c1 = b"BT /F1 12 Tf (Hello) Tj [(Wor) -250 (ld)] TJ <48656c6c6f> Tj ET"
FIXTURES.append((
    "flate_literal_tj_hex",
    pdf(obj_stream(flate_body(_c1), flate=True)),
    DEFAULT_MAX_CHARS,
))

# 2) Same content, but an *uncompressed* stream (body used verbatim).
FIXTURES.append((
    "plain_literal_tj_hex",
    pdf(obj_stream(_c1, flate=False)),
    DEFAULT_MAX_CHARS,
))

# 3) A /Title (literal, in an Info dict) alongside a compressed content stream.
_info3 = b"1 0 obj\n<< /Title (My PDF Title) /Author (nobody) >>\nendobj\n"
FIXTURES.append((
    "title_plus_flate",
    pdf(_info3 + obj_stream(flate_body(b"BT (Body text here) Tj ET"), flate=True)),
    DEFAULT_MAX_CHARS,
))

# 4) Escapes / octal inside a literal string in the content stream:
#    \t \101(=A) \n and escaped parens; the \t and \n collapse to spaces.
_c4 = b"BT (A\\tB\\101\\n\\(x\\)) Tj ET"
FIXTURES.append((
    "literal_escapes_octal",
    pdf(obj_stream(_c4, flate=False)),
    DEFAULT_MAX_CHARS,
))

# 5) A /Title with an octal escape (\351 = latin-1 'e-acute'), an escaped
#    backslash and escaped parens — re-decoded by _read_literal.
_info5 = b"1 0 obj\n<< /Title (Caf\\351\\\\ \\(x\\)) >>\nendobj\n"
FIXTURES.append((
    "title_escapes_octal",
    pdf(_info5 + obj_stream(b"BT (x) Tj ET", flate=False)),
    DEFAULT_MAX_CHARS,
))

# 6) A /Title given as a hex string — NOT matched by the literal-only regex, so
#    the title is "" (confirms parity with Python, which also skips it).
_info6 = b"1 0 obj\n<< /Title <4D7920546974> >>\nendobj\n"
FIXTURES.append((
    "title_hex_unsupported",
    pdf(_info6 + obj_stream(b"BT (has body) Tj ET", flate=False)),
    DEFAULT_MAX_CHARS,
))

# 7) Hex string with odd length + interior whitespace (padded, whitespace
#    stripped) plus a nested-parens literal.
_c7 = b"BT <48 65 6c 6c 6> Tj (out(in)out) Tj ET"
FIXTURES.append((
    "hex_odd_and_nested_parens",
    pdf(obj_stream(_c7, flate=False)),
    DEFAULT_MAX_CHARS,
))

# 8) %PDF present but no content stream at all -> "".
FIXTURES.append((
    "pdf_no_streams",
    b"%PDF-1.4\nnothing to see here\n%%EOF\n",
    DEFAULT_MAX_CHARS,
))

# 9) A stream lacking any text operator (no BT/Tj/TJ) is not yielded -> "".
FIXTURES.append((
    "stream_without_operators",
    pdf(obj_stream(b"raw data, nada, here", flate=False)),
    DEFAULT_MAX_CHARS,
))

# 10) max_chars truncation: a long literal capped mid-string (by code points).
FIXTURES.append((
    "max_chars_truncation",
    pdf(obj_stream(b"BT (abcdefghij) Tj ET", flate=False)),
    4,
))

# 11) Empty input -> "".
FIXTURES.append(("empty_input", b"", DEFAULT_MAX_CHARS))

# 12) Garbage input, no %PDF marker -> "".
FIXTURES.append(("garbage_no_pdf_marker", b"not a pdf, just bytes", DEFAULT_MAX_CHARS))


def rs(name: str, blob: bytes, max_chars: int) -> str:
    text = pdftext.extract_text(blob, max_chars)
    title = pdftext.extract_title(blob)
    return (
        "    Fx {\n"
        '        name: "%s",\n'
        "        max_chars: %d,\n"
        '        input: "%s",\n'
        '        text: "%s",\n'
        '        title: "%s",\n'
        "    },\n"
        % (
            name,
            max_chars,
            blob.hex(),
            text.encode("utf-8").hex(),
            title.encode("utf-8").hex(),
        )
    )


def main() -> None:
    rows = "".join(rs(*fx) for fx in FIXTURES)
    sys.stdout.write(
        "//! Cross-check: `websearch::pdftext` reproduces the Python\n"
        "//! `websearch.pdftext` byte-for-byte on minimal PDF blobs — a\n"
        "//! FlateDecode-compressed content stream (literal + `TJ` array + hex\n"
        "//! string), an uncompressed stream, `/Title` (literal, octal-escaped,\n"
        "//! and unsupported hex forms), literal escapes/octal, `max_chars`\n"
        "//! truncation, and empty/garbage input.\n"
        "//!\n"
        "//! @generated by tests/regen_pdftext_goldens.py — DO NOT EDIT BY HAND.\n"
        "//! Regenerate:\n"
        "//!   PYTHONPATH=legacy-python/websearch python3 \\\n"
        "//!     crates/websearch/tests/regen_pdftext_goldens.py \\\n"
        "//!     > crates/websearch/tests/xcheck_pdftext.rs\n"
        "//!\n"
        "//! Inputs and the UTF-8 of each expected (latin-1) output are hex so\n"
        "//! both sides see identical bytes; compressed streams are embedded from\n"
        "//! `zlib.compress`, so Python and Rust inflate the very same input.\n"
        "\n"
        "struct Fx {\n"
        "    name: &'static str,\n"
        "    max_chars: usize,\n"
        "    input: &'static str,\n"
        "    text: &'static str,\n"
        "    title: &'static str,\n"
        "}\n"
        "\n"
        "fn unhex(s: &str) -> Vec<u8> {\n"
        "    let b = s.as_bytes();\n"
        "    let mut out = Vec::with_capacity(b.len() / 2);\n"
        "    let mut i = 0;\n"
        "    while i + 1 < b.len() {\n"
        "        let hi = (b[i] as char).to_digit(16).unwrap();\n"
        "        let lo = (b[i + 1] as char).to_digit(16).unwrap();\n"
        "        out.push((hi * 16 + lo) as u8);\n"
        "        i += 2;\n"
        "    }\n"
        "    out\n"
        "}\n"
        "\n"
        "const FIXTURES: &[Fx] = &[\n" + rows + "];\n"
        "\n"
        "#[test]\n"
        "fn pdftext_matches_python() {\n"
        "    for fx in FIXTURES {\n"
        "        let input = unhex(fx.input);\n"
        "        let want_text = String::from_utf8(unhex(fx.text)).unwrap();\n"
        "        let got_text = websearch::pdftext::extract_text(&input, fx.max_chars);\n"
        '        assert_eq!(got_text, want_text, "extract_text mismatch: {}", fx.name);\n'
        "        let want_title = String::from_utf8(unhex(fx.title)).unwrap();\n"
        "        let got_title = websearch::pdftext::extract_title(&input);\n"
        '        assert_eq!(got_title, want_title, "extract_title mismatch: {}", fx.name);\n'
        "    }\n"
        "}\n"
    )


if __name__ == "__main__":
    main()
