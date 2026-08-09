"""Regression: the Atom feed sanitizer must drop the XML-illegal noncharacters
U+FFFE and U+FFFF (not just C0 controls). They are valid UTF-8 and survive git's
lossy decode, so a single one in a commit subject/author/email or a repo
description would make the whole feed non-well-formed and every reader rejects
all entries. XML-*legal* noncharacters (e.g. U+FDD0) must be preserved."""
import unittest
import xml.sax

from gitweb import markup


class TestAtomNoncharacters(unittest.TestCase):
    def test_illegal_chars_yield_wellformed_xml(self):
        for ch in ("￾", "￿", "\x01", "\x00"):
            escaped = markup.xml_escape("fix" + ch + "bug")
            doc = ("<t>" + escaped + "</t>").encode("utf-8")
            # raises SAXParseException if not well-formed
            xml.sax.parseString(doc, xml.sax.ContentHandler())

    def test_legal_noncharacter_preserved(self):
        # U+FDD0 is a noncharacter but is permitted by the XML 1.0 Char
        # production — the sanitizer must NOT strip it.
        self.assertIn("﷐", markup.xml_escape("a﷐b"))


if __name__ == "__main__":
    unittest.main()
