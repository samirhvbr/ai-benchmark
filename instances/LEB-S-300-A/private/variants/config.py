"""What tools/variants.py needs to build LEB-S-300-A's control variants. Every case edits the planted tree (base "legacy"): the instance's clean tree is already
proved by the verifiers themselves (CATALOG.md section 9b), so the cases are about the fixes a candidate could write instead, and the near misses."""

REFERENCE = "systems/300-A/reference"
REFERENCE_ADDS = {}
SRC = "src/main/java/com/condodesk/"
SERIAL = set()
EVALUATE = "harness/java/evaluate.sh"
