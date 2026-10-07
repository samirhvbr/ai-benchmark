"""E9: informative fields (`dimensions`, `decoy_kind`, `informative_affected`).

They are copied to the scorecard and the exports only when the matrix declares them, and no weight, point or penalty reads them.
"""

import os
import tempfile
import unittest

from helpers import (entry, finding, judged, load_exporter, make_published_tree, read_text, score_files, synthetic_matrix,
                     synthetic_mech, synthetic_verdict, validate, read_json, ROOT)


def build(informative):
    extra = (lambda **kw: kw) if informative else (lambda **kw: {})
    entries = [
        entry("BUG-001", "Alta", "Difícil", **extra(dimensions=["concorrencia", "consistencia"], informative_affected=["OrderTest"])),
        entry("SEC-001", "Alta", "Fácil", **extra(dimensions=["consistencia"])),
        entry("ARCH-001", "Média", "Moderada", template="R"),
        entry("PERF-001", exists=False, **extra(decoy_kind="diagnostico")),
        entry("CLN-001", exists=False, **extra(decoy_kind="intervencao")),
    ]
    return synthetic_matrix(entries)


def verdict(report_decoy=True):
    v = synthetic_verdict([judged("BUG-001", C1="full", C2="full", C3="none", C5="none"),
                           judged("SEC-001", C1="half", C2="full", C3="full", C5="full"),
                           judged("ARCH-001", R1="full", R2="full", R3="half", R4="full")],
                          false_positives=[{"reported_as": "PERF-001", "is_isca": True, "confidence": 70}] if report_decoy else [])
    return v


class Scorecard(unittest.TestCase):
    def card(self, informative):
        card, rc, err = score_files(build(informative), synthetic_mech(), verdict())
        self.assertEqual(rc, 0, err)
        return card

    def test_nothing_changes_for_a_matrix_without_the_fields(self):
        card = self.card(False)
        for key in ("dimension_breakdown", "decoy_breakdown"):
            self.assertNotIn(key, card)
        for f in card["findings"]:
            self.assertNotIn("dimensions", f)
            self.assertNotIn("informative_affected", f)

    def test_the_fields_are_copied_and_summarized(self):
        card = self.card(True)
        self.assertEqual(finding(card, "BUG-001")["dimensions"], ["concorrencia", "consistencia"])
        self.assertEqual(finding(card, "BUG-001")["informative_affected"], ["OrderTest"])
        self.assertNotIn("dimensions", finding(card, "ARCH-001"))
        self.assertEqual(card["dimension_breakdown"], [
            {"dimension": "concorrencia", "planted": 1, "detected": 1, "corrected": 0},
            {"dimension": "consistencia", "planted": 2, "detected": 2, "corrected": 1}])
        self.assertEqual(card["decoy_breakdown"], [{"kind": "diagnostico", "decoys": 1, "reported": 1},
                                                   {"kind": "intervencao", "decoys": 1, "reported": 0}])

    def test_no_weight_point_or_penalty_depends_on_them(self):
        plain, rich = self.card(False), self.card(True)
        for key in ("total", "grade", "categories", "penalties"):
            self.assertEqual(plain[key], rich[key], key)
        for a, b in zip(plain["findings"], rich["findings"]):
            self.assertEqual((a["id"], a["criteria"], a["points_earned"]), (b["id"], b["criteria"], b["points_earned"]))

    def test_affected_from_the_mechanical_report_reaches_the_finding(self):
        mech = synthetic_mech()
        mech["informative_affected"] = {"SEC-001": ["Contract.refund"]}
        card, rc, err = score_files(build(False), mech, verdict())
        self.assertEqual(rc, 0, err)
        self.assertEqual(finding(card, "SEC-001")["informative_affected"], ["Contract.refund"])

    def test_the_scorecard_still_validates_against_the_schema(self):
        schema = read_json(os.path.join(ROOT, "scoring", "scorecard.schema.json"))
        card = self.card(True)
        self.assertEqual([e for e in validate(card, schema) if "dimension" in e or "decoy" in e or "informative" in e], [])


class Exports(unittest.TestCase):
    def export(self, informative):
        t = tempfile.TemporaryDirectory()
        self.addCleanup(t.cleanup)
        make_published_tree(t.name, build(informative), [("agent-one", 1, verdict())])
        mod = load_exporter(t.name)
        runs = mod.discover_runs()
        data = mod.aggregate(runs)
        runs_csv, flaws_csv = mod.render_csvs(runs, data)
        return mod, data, flaws_csv

    def test_without_the_fields_the_exports_have_the_same_shape_as_before(self):
        mod, data, flaws_csv = self.export(False)
        self.assertEqual(flaws_csv.splitlines()[0], ",".join(mod.FLAW_COLUMNS))
        inst = data["instances"][0]
        self.assertNotIn("decoy_kinds", inst)
        for f in inst["flaws"]:
            self.assertNotIn("dimensions", f)

    def test_with_the_fields_columns_and_keys_are_appended_only(self):
        mod, data, flaws_csv = self.export(True)
        header = flaws_csv.splitlines()[0].split(",")
        self.assertEqual(header[:len(mod.FLAW_COLUMNS)], mod.FLAW_COLUMNS)
        self.assertEqual(header[len(mod.FLAW_COLUMNS):], ["dimensions"])
        rows = {line.split(",")[5]: line.split(",") for line in flaws_csv.splitlines()[1:]}
        self.assertEqual(rows["BUG-001"][-1], "concorrencia|consistencia")
        self.assertEqual(rows["ARCH-001"][-1], "")
        inst = data["instances"][0]
        self.assertEqual(inst["decoy_kinds"], {"PERF-001": "diagnostico", "CLN-001": "intervencao"})
        self.assertEqual(next(f for f in inst["flaws"] if f["id"] == "SEC-001")["dimensions"], ["consistencia"])


if __name__ == "__main__":
    unittest.main()
