"""The PEN-003 ruler for task 1.1.0 and later, and the calibration cases that exercise it.

The tests do not judge anything. They keep the cases file usable as the proof the ruler asks for: every case has a description and an expected
outcome from a closed set, the descriptions can be handed to a judge without the answers, and the ruler in JUDGE.md names the six elements.
"""

import os
import re
import unittest

from helpers import ROOT, read_text

OUTCOMES = {"PEN-003", "none", "COMP", "PEN-002"}


def cases_text():
    return read_text(ROOT, "scoring", "pen003-cases.md")


def judge_section():
    text = read_text(ROOT, "scoring", "JUDGE.md")
    start = text.index("### PEN-003 when the task is 1.1.0 or later")
    return text[start:text.index("## Passo 5", start)]


class CalibrationCases(unittest.TestCase):
    def test_each_case_has_a_delivery_and_the_table_has_one_closed_outcome_per_case(self):
        text = cases_text()
        body = text[text.index("## Cases"):text.index("## Expected outcomes")]
        numbers = [int(n) for n in re.findall(r"^### Case (\d+):", body, re.M)]
        self.assertGreaterEqual(len(numbers), 8, "the proposal asks for 8 to 10 cases")
        self.assertEqual(numbers, list(range(1, len(numbers) + 1)))
        self.assertEqual(body.count("**Delivery.**"), len(numbers))

        table = text[text.index("## Expected outcomes"):text.index("## Agreement run")]
        rows = re.findall(r"^\| (\d+) \| `([^`]+)` \|", table, re.M)
        self.assertEqual([int(n) for n, _ in rows], numbers)
        for _, outcome in rows:
            self.assertIn(outcome, OUTCOMES)

    def test_the_cases_cover_both_sides_of_the_boundary(self):
        text = cases_text()
        table = text[text.index("## Expected outcomes"):text.index("## Agreement run")]
        outcomes = {o for _, o in re.findall(r"^\| (\d+) \| `([^`]+)` \|", table, re.M)}
        self.assertIn("PEN-003", outcomes)
        self.assertTrue({"none", "COMP", "PEN-002"} <= outcomes,
                        "a delivery that is not PEN-003 must be shown for each reason it is not")

    def test_descriptions_can_be_given_to_a_judge_without_the_answers(self):
        text = cases_text()
        body = text[text.index("## Cases"):text.index("## Expected outcomes")]
        for word in ("PEN-003", "COMP-", "PEN-002", "Expected", "Why"):
            self.assertNotIn(word, body, "the descriptions must not carry the answer (%s)" % word)

    def test_the_cases_name_no_instance(self):
        text = cases_text().lower()
        for word in ("leb-100", "leb-300", "netx"):
            self.assertNotIn(word, text)

    def test_the_agreement_run_is_recorded(self):
        text = cases_text()
        self.assertIn("## Agreement run", text)
        self.assertNotIn("Not run yet", text)


class JudgeSection(unittest.TestCase):
    def test_the_six_elements_are_numbered_and_the_scope_is_stated(self):
        section = judge_section()
        for n in range(1, 7):
            self.assertRegex(section, r"(?m)^%d\. \*\*" % n)
        self.assertIn("1.1.0 or later", section)
        self.assertIn("task 1.0.0", section)
        self.assertIn("pen003-cases.md", section)

    def test_the_section_changes_no_value(self):
        section = judge_section()
        self.assertIn("No weight, penalty or value changes", section)
        self.assertIn("0 or 1 per run", section)
        self.assertTrue(os.path.exists(os.path.join(ROOT, "scoring", "pen003-cases.md")))


if __name__ == "__main__":
    unittest.main()
