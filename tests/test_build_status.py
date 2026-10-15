"""Who failed, the delivery or the evaluation (harness/java/build_status.py, used by harness/java/evaluate.sh)?

A delivery that does not compile is the delivery's verdict; a Maven run that failed any other way is the evaluator's failure, and must end
in exit status 3 (INCONCLUSIVE), never in a score. Real Maven log shapes; no Docker.
"""

import os
import sys
import unittest

from helpers import ROOT, read_text

sys.path.insert(0, os.path.join(ROOT, "harness", "java"))
import build_status  # noqa: E402

COMPILE_ERROR = """[INFO] Compiling 61 source files with javac [debug release 21] to target/classes
[INFO] -------------------------------------------------------------
[ERROR] COMPILATION ERROR :
[INFO] -------------------------------------------------------------
[ERROR] /work/src/main/java/com/condodesk/billing/Charges.java:[88,17] cannot find symbol
[INFO] 1 error
[INFO] BUILD FAILURE
[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.13.0:compile (default-compile) on project condodesk: Compilation failure
"""
TEST_COMPILE_ERROR = """[INFO] --- compiler:3.13.0:testCompile (default-testCompile) @ condodesk ---
[ERROR] COMPILATION ERROR :
[ERROR] /work/src/test/java/com/condodesk/AccountTest.java:[20,9] method create in class Account cannot be applied to given types;
[ERROR] Failed to execute goal org.apache.maven.plugins:maven-compiler-plugin:3.13.0:testCompile (default-testCompile) on project condodesk: Compilation failure
"""
OFFLINE_CACHE_MISS = """[INFO] --- resources:3.3.1:resources (default-resources) @ condodesk ---
[ERROR] Failed to execute goal on project condodesk: Could not resolve dependencies for project com.condodesk:condodesk:jar:1.0: Cannot access central (https://repo.maven.apache.org/maven2) in offline mode and the artifact org.postgresql:postgresql:jar:42.7.3 has not been downloaded from it before. -> [Help 1]
"""
DOCKER_DOWN = "docker: Error response from daemon: network leb-series not found.\n"
FORK_CRASH = """[ERROR] Failed to execute goal org.apache.maven.plugins:maven-surefire-plugin:3.2.5:test (default-test) on project condodesk: There was a timeout or other error in the fork -> [Help 1]
"""
TESTS_FAILED_BUT_IGNORED = """[ERROR] Tests run: 70, Failures: 3, Errors: 0, Skipped: 0
[INFO] BUILD SUCCESS
"""


class ClassifyTest(unittest.TestCase):
    def test_a_compiler_diagnostic_is_the_deliverys_build_failure(self):
        self.assertEqual(build_status.classify(COMPILE_ERROR, 1), build_status.DELIVERY_DOES_NOT_COMPILE)
        self.assertEqual(build_status.classify(TEST_COMPILE_ERROR, 1), build_status.DELIVERY_DOES_NOT_COMPILE)

    def test_a_dependency_that_cannot_be_resolved_offline_is_the_evaluators_failure(self):
        # target/classes does not exist yet: the old rule (no classes folder = the delivery does not build) scored this as the delivery's
        self.assertEqual(build_status.classify(OFFLINE_CACHE_MISS, 1), build_status.EVALUATOR_FAILED)

    def test_docker_that_did_not_start_is_the_evaluators_failure(self):
        self.assertEqual(build_status.classify(DOCKER_DOWN, 125), build_status.EVALUATOR_FAILED)
        self.assertEqual(build_status.classify("", 1), build_status.EVALUATOR_FAILED)

    def test_a_fork_that_died_is_not_read_as_a_result_even_with_some_reports(self):
        self.assertEqual(build_status.classify(FORK_CRASH, 1), build_status.EVALUATOR_FAILED)

    def test_failing_tests_do_not_change_the_status_and_the_build_is_read(self):
        self.assertEqual(build_status.classify(TESTS_FAILED_BUT_IGNORED, 0), build_status.BUILT)

    def test_a_compile_error_wins_even_when_the_status_is_ambiguous(self):
        self.assertEqual(build_status.classify(COMPILE_ERROR, 0), build_status.DELIVERY_DOES_NOT_COMPILE)


class EvaluateScriptTest(unittest.TestCase):
    def test_evaluate_sh_decides_through_build_status_and_no_longer_looks_for_a_classes_folder(self):
        text = read_text(ROOT, "harness", "java", "evaluate.sh")
        self.assertIn("build_status.classify(log, status)", text)
        self.assertNotIn('"target", "classes"', text, "a missing classes folder must not make the delivery's build fail")
        self.assertIn("sys.exit(3)", text)


if __name__ == "__main__":
    unittest.main()
