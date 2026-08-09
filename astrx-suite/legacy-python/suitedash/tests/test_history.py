"""History ring buffers + inline-SVG sparklines: bounded, and well-formed XML for
empty / one-point / flat / NaN / Inf / huge inputs. Pure/offline."""

import os
import sys
import unittest
import xml.sax
from collections import OrderedDict
from xml.sax.handler import ContentHandler

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.history import History, Ring, sparkline_svg
from suitedash.probe import ServiceResult


class _SvgHandler(ContentHandler):
    """Collect element names and the polyline's points for structural asserts."""

    def __init__(self):
        super().__init__()
        self.elements = []
        self.points = None

    def startElement(self, name, attrs):
        self.elements.append(name)
        if name == "polyline":
            self.points = attrs.get("points")


def _parse_svg(svg):
    """Parse via xml.sax (raises SAXParseException if not well-formed)."""
    handler = _SvgHandler()
    xml.sax.parseString(svg.encode("utf-8"), handler)
    return handler


class TestRing(unittest.TestCase):
    def test_bounded_and_evicts_oldest(self):
        ring = Ring(3)
        for i in range(5):
            ring.push(i)
        self.assertEqual(ring.values(), [2.0, 3.0, 4.0])  # 0,1 evicted
        self.assertEqual(len(ring), 3)


class TestHistory(unittest.TestCase):
    def _results(self, **specs):
        out = OrderedDict()
        for name, spec in specs.items():
            out[name] = ServiceResult(
                name=name, base_url="x", up=spec.get("up", True),
                metrics=spec.get("metrics", {}),
            )
        return out

    def test_records_finite_samples_for_up_services_only(self):
        h = History(capacity=10, max_series=100)
        h.record(self._results(a={"metrics": {"m": 1}}, down={"up": False, "metrics": {"m": 9}}))
        h.record(self._results(a={"metrics": {"m": 2}}))
        self.assertEqual(h.series("a", "m"), [1.0, 2.0])
        self.assertEqual(h.series("down", "m"), [])  # down service not recorded

    def test_skips_none_and_non_finite(self):
        h = History(capacity=10)
        h.record(self._results(a={"metrics": {"m": None, "n": float("inf")}}))
        self.assertEqual(h.series("a", "m"), [])
        self.assertEqual(h.series("a", "n"), [])

    def test_series_count_is_bounded_evicting_oldest(self):
        h = History(capacity=5, max_series=2)
        h.record(self._results(a={"metrics": {"x": 1}}))
        h.record(self._results(b={"metrics": {"y": 1}}))
        h.record(self._results(c={"metrics": {"z": 1}}))  # evicts (a, x)
        alls = h.all_series()
        keys = {(s, m) for s, mm in alls.items() for m in mm}
        self.assertEqual(len(keys), 2)
        self.assertNotIn(("a", "x"), keys)

    def test_capacity_is_clamped_to_a_sane_minimum(self):
        self.assertGreaterEqual(History(capacity=0).capacity, 2)


class TestSparkline(unittest.TestCase):
    def test_normal_series_is_well_formed_with_polyline(self):
        h = _parse_svg(sparkline_svg([1, 2, 3, 4, 5], width=100, height=20))
        self.assertIn("svg", h.elements)
        self.assertIn("polyline", h.elements)
        coords = [c.split(",") for c in h.points.split()]
        self.assertEqual(len(coords), 5)
        for xs, ys in coords:  # every coordinate finite and inside the viewport
            x, y = float(xs), float(ys)
            self.assertTrue(0.0 <= x <= 100.0)
            self.assertTrue(0.0 <= y <= 20.0)

    def test_empty_series_is_valid_svg_without_polyline(self):
        h = _parse_svg(sparkline_svg([]))
        self.assertIn("svg", h.elements)
        self.assertNotIn("polyline", h.elements)

    def test_single_point_is_valid(self):
        h = _parse_svg(sparkline_svg([42]))
        self.assertIsNotNone(h.points)  # a flat two-point line

    def test_flat_series_is_valid(self):
        _parse_svg(sparkline_svg([7, 7, 7, 7]))  # span == 0 must not divide-by-zero

    def test_nan_and_inf_are_dropped_not_emitted(self):
        # Only the finite 5 survives -> a valid single-point (flat) line.
        h = _parse_svg(sparkline_svg([float("nan"), float("inf"), float("-inf"), 5]))
        for tok in ("nan", "inf", "NaN", "Inf"):
            self.assertNotIn(tok, (h.points or ""))

    def test_all_non_finite_yields_valid_empty_svg(self):
        h = _parse_svg(sparkline_svg([float("nan"), float("inf")]))
        self.assertNotIn("polyline", h.elements)

    def test_huge_values_do_not_overflow_or_break_xml(self):
        h = _parse_svg(sparkline_svg([1e308, -1e308, 1e308, 0.0]))
        for xs, ys in (c.split(",") for c in h.points.split()):
            x, y = float(xs), float(ys)
            self.assertTrue(0.0 <= x <= 100.0 and 0.0 <= y <= 20.0)

    def test_non_numeric_points_are_ignored(self):
        _parse_svg(sparkline_svg([1, "oops", None, 3]))  # must not raise

    def test_non_numeric_or_non_finite_dimensions_do_not_raise(self):
        # width/height are normally the defaults, but a bad value must degrade to
        # a valid SVG rather than raising out of float().
        for kw in (
            {"width": "oops"},
            {"height": None},
            {"width": float("nan")},
            {"height": float("inf")},
        ):
            h = _parse_svg(sparkline_svg([1, 2, 3], **kw))
            self.assertIn("svg", h.elements)


if __name__ == "__main__":
    unittest.main()
