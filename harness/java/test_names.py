"""The names of the characterization tests of a Java instance, as `Class#method`, for the regression rule of the new series
(docs/new-series-evaluator.md sections 3 and 5): which tests failed in a delivery, and which tests exist at all.

Stdlib only; evaluate.sh imports it. The id is the simple class name and the method name, `AccountTest#theEmailIsNotCaseSensitive`,
the same spelling `private/regression_groups.json` uses.
"""
import glob
import os
import re
import xml.etree.ElementTree as ET

# `@Test` (possibly with arguments and other annotations after it), then the method declaration.
TEST_METHOD = re.compile(r"@Test\b(?:\s*\([^)]*\))?(?:\s*@\w+(?:\([^)]*\))?)*\s*(?:public\s+|protected\s+)?(?:static\s+)?void\s+(\w+)\s*\(")


def test_id(classname, name):
    """`com.condodesk.AccountTest`, `theEmailIsNotCaseSensitive()` -> `AccountTest#theEmailIsNotCaseSensitive`."""
    return classname.rsplit(".", 1)[-1] + "#" + re.sub(r"\(.*\)$", "", name)


def failed_test_names(report_paths):
    """Sorted, unique ids of the test cases that failed or errored in these Surefire XML reports. A suite that failed before any of its
    tests ran (a class that cannot be initialized) has no test case of its own: it is named after the suite, `Class#<suite>`."""
    failed = set()
    for path in report_paths:
        suite = ET.parse(path).getroot()
        cases = suite.findall("testcase")
        for case in cases:
            if case.find("failure") is not None or case.find("error") is not None:
                failed.add(test_id(case.get("classname") or suite.get("name") or "?", case.get("name") or "?"))
        if not cases and (int(suite.get("failures", 0)) or int(suite.get("errors", 0))):
            failed.add(test_id(suite.get("name") or "?", "<suite>"))
    return sorted(failed)


def source_test_names(test_dir):
    """Sorted ids of every `@Test` method under a test source folder: what a delivery that does not build fails, all of it."""
    names = set()
    for path in glob.glob(os.path.join(test_dir, "**", "*.java"), recursive=True):
        cls = os.path.basename(path)[:-len(".java")]
        for method in TEST_METHOD.findall(open(path, encoding="utf-8").read()):
            names.add(cls + "#" + method)
    return sorted(names)
