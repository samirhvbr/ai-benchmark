"""The regression groups of the new series (docs/new-series-evaluator.md sections 3 and 5): the names of the tests, the groups of an
instance, and the harness carrying the names of the failed tests into the mechanical report. Synthetic fixtures, plus the
instance's own files for the coverage check."""

import importlib.util
import json
import os
import sys
import tempfile
import unittest

from helpers import ROOT, read_json

sys.path.insert(0, os.path.join(ROOT, "harness", "java"))
import test_names  # noqa: E402

SUITE = """<?xml version="1.0" encoding="UTF-8"?>
<testsuite name="com.condodesk.%(cls)s" tests="%(n)d" failures="%(f)d" errors="%(e)d" skipped="0">
%(cases)s
</testsuite>
"""


def surefire(directory, cls, cases, suite_failures=0):
    """A Surefire report: cases is a list of (method, kind) with kind in ok|failure|error."""
    body = []
    for method, kind in cases:
        inner = {"ok": "", "failure": '<failure message="x"/>', "error": '<error message="x"/>'}[kind]
        body.append('<testcase name="%s()" classname="com.condodesk.%s">%s</testcase>' % (method, cls, inner))
    path = os.path.join(directory, "TEST-com.condodesk.%s.xml" % cls)
    with open(path, "w", encoding="utf-8") as f:
        f.write(SUITE % {"cls": cls, "n": len(cases), "f": sum(k == "failure" for _, k in cases) + suite_failures,
                         "e": sum(k == "error" for _, k in cases), "cases": "\n".join(body)})
    return path


class TestNames(unittest.TestCase):
    def test_the_failed_tests_of_surefire_reports_are_named_class_hash_method(self):
        with tempfile.TemporaryDirectory() as t:
            reports = [surefire(t, "BillingTest", [("lateFeeRoundsHalfUp", "failure"), ("budgetAddsUp", "ok"), ("webhookRepeats", "error")]),
                       surefire(t, "AccountTest", [("loginWorks", "ok")])]
            self.assertEqual(test_names.failed_test_names(reports), ["BillingTest#lateFeeRoundsHalfUp", "BillingTest#webhookRepeats"])

    def test_a_suite_that_failed_before_any_test_ran_is_named_after_the_suite(self):
        with tempfile.TemporaryDirectory() as t:
            reports = [surefire(t, "BookingTest", [], suite_failures=1)]
            self.assertEqual(test_names.failed_test_names(reports), ["BookingTest#<suite>"])

    def test_the_tests_of_a_source_tree_are_found_with_their_other_annotations(self):
        with tempfile.TemporaryDirectory() as t:
            os.makedirs(os.path.join(t, "com", "x"))
            with open(os.path.join(t, "com", "x", "SomeTest.java"), "w", encoding="utf-8") as f:
                f.write("class SomeTest {\n  @Test\n  void plain() {}\n  @Test @DisplayName(\"x\")\n  public void annotated() {}\n"
                        "  @Test(timeout = 5)\n  void withArgs() {}\n  void notATest() {}\n}\n")
            self.assertEqual(test_names.source_test_names(t), ["SomeTest#annotated", "SomeTest#plain", "SomeTest#withArgs"])


class HarnessCarriesTheNames(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location("leb_harness", os.path.join(ROOT, "harness", "leb_harness.py"))
        cls.harness = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.harness)

    def result(self, obj):
        return self.harness.characterization_result({"timed_out": False, "out": json.dumps(obj), "err": "", "elapsed_s": 1.5})

    def test_the_failed_test_names_travel_into_the_characterization_result(self):
        r, why = self.result({"passed": 68, "failed": 2, "failed_tests": ["A#x", "A#y"]})
        self.assertIsNone(why)
        self.assertEqual((r["failed"], r["failed_tests"], r["ok"]), (2, ["A#x", "A#y"], False))
        self.assertNotIn("build_failed", r)

    def test_a_runner_that_gives_no_names_still_works_and_a_build_failure_is_flagged(self):
        r, _ = self.result({"passed": 70, "failed": 0})
        self.assertNotIn("failed_tests", r)
        r, _ = self.result({"passed": 0, "failed": 70, "failed_tests": ["A#x"], "build_failed": True})
        self.assertTrue(r["build_failed"])


class InstanceGroups(unittest.TestCase):
    """LEB-D-300-A's own groups: every public characterization test is in exactly one group."""

    def test_the_groups_cover_exactly_the_public_tests_once_each(self):
        groups = read_json(os.path.join(ROOT, "instances", "LEB-D-300-A", "private", "regression_groups.json"))["groups"]
        mapped = [t for g in groups for t in g["tests"]]
        public = test_names.source_test_names(os.path.join(ROOT, "instances", "LEB-D-300-A", "public", "code", "src", "test"))
        self.assertEqual(sorted(mapped), sorted(public))
        self.assertEqual(len(mapped), len(set(mapped)), "a test is in two groups")
        self.assertEqual(len({g["id"] for g in groups}), len(groups), "a group id is repeated")
        for g in groups:
            self.assertTrue(g["contract"].strip(), g["id"])
            self.assertTrue(g["tests"], g["id"])


class SecurityInstanceGroups(unittest.TestCase):
    """LEB-S-300-A's own groups: every test of its public suite is in exactly one group."""

    def test_the_groups_cover_exactly_the_public_tests_once_each(self):
        groups = read_json(os.path.join(ROOT, "instances", "LEB-S-300-A", "private", "regression_groups.json"))["groups"]
        mapped = [t for g in groups for t in g["tests"]]
        public = test_names.source_test_names(os.path.join(ROOT, "instances", "LEB-S-300-A", "public", "code", "src", "test"))
        self.assertEqual(sorted(mapped), sorted(public))
        self.assertEqual(len(mapped), len(set(mapped)), "a test is in two groups")
        self.assertEqual(len({g["id"] for g in groups}), len(groups), "a group id is repeated")
        for g in groups:
            self.assertTrue(g["contract"].strip(), g["id"])
            self.assertTrue(g["tests"], g["id"])


if __name__ == "__main__":
    unittest.main()
