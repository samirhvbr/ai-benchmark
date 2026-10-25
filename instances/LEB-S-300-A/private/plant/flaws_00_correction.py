"""LEB-S-300-A: the S-only correction of the reference (CATALOG.md section 8), made before any row is planted.

It is not a flaw. The id CORR-S1 lets `tools/plant.py --only CORR-S1` build the instance's clean tree (the reference plus this
correction), the tree the verifiers must accept in their passing direction. The shared reference is not edited: LEB-D-300-A is
frozen and its build_public.py --check rebuilds its public tree from the reference byte for byte."""

from paths import SRC, TESTS

FLAWS = [
    # PBKDF2 at 600,000 iterations (owner, 2026-10-25), the count current guidance asks of HMAC-SHA256.
    ("CORR-S1", [
        (SRC + "auth/Passwords.java",
         "    static final int ITERATIONS = 100_000;\n",
         "    static final int ITERATIONS = 600_000;\n"),
        (TESTS + "SecurityBehaviorTest.java",
         'assertTrue(upgraded.startsWith("pbkdf2-sha256$100000$"), upgraded);',
         'assertTrue(upgraded.startsWith("pbkdf2-sha256$600000$"), upgraded);'),
    ]),
]
