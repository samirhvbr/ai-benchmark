"""Who failed, the delivery or the evaluation? The one decision `evaluate.sh` takes about a Maven run before it reads any result.

Stdlib only; evaluate.sh imports it. A delivery that does not compile is scored (every public test fails, every row is unfixed, one
regression). A Maven run that failed for any other reason is NOT the delivery's verdict: Docker, the disposable database, the offline
dependency cache and Maven itself are the evaluator's, and a delivery that scores badly because the evaluator could not run is a wrong
score nobody would notice. `evaluate.sh` ends such a run with exit status 3 ("the evaluator failed"), which the harness reports as
INCONCLUSIVE and never reads as a verdict.

The tests run with `-Dmaven.test.failure.ignore=true`, so a failing or erroring test does not change Maven's exit status: a non-zero
status without a compiler diagnostic is a build that did not finish (a dependency that could not be resolved offline, a forked JVM that
died, a container that did not start). A partial set of Surefire reports from such a run is not read as a result either.
"""

BUILT = "built"
DELIVERY_DOES_NOT_COMPILE = "delivery_does_not_compile"
EVALUATOR_FAILED = "evaluator_failed"

# What maven-compiler-plugin prints when javac rejects the sources of the main or the test tree.
COMPILER_MARKERS = ("COMPILATION ERROR", "Compilation failure")


def classify(log, status):
    """BUILT, DELIVERY_DOES_NOT_COMPILE or EVALUATOR_FAILED, from Maven's log and exit status."""
    if any(marker in log for marker in COMPILER_MARKERS):
        return DELIVERY_DOES_NOT_COMPILE
    if status != 0:
        return EVALUATOR_FAILED
    return BUILT
