#!/usr/bin/env python3
"""broken_groups.py — the regression groups a delivery broke, for the judges (docs/new-series-evaluator.md sections 4 and 5).

The mechanical report names the characterization tests that failed in the delivery and not in the pristine instance; the judges
attribute each broken *group* to the planted row whose fix broke it, or to none. This lists the groups they must attribute, with the
contract each one guards and the failed tests, using the same rule as the scorer (harness/score_v2.py), so the judges and the scorer
see the same groups.

    python3 tools/broken_groups.py --mechanical mecanico.json --groups instances/LEB-D-300-A/private/regression_groups.json [--out regressions.json]

Writes {"broken": [{"group": "RG-ACC-01", "contract": "...", "failed_tests": ["Class#method", ...]}]}; an empty list when nothing broke. A
delivery that does not build is the single group `BUILD`. Standard library only."""

import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "harness"))
import score_v2  # noqa: E402


def broken(mech, groups_doc):
    contract = {g["id"]: g.get("contract", "") for g in groups_doc["groups"]}
    contract["BUILD"] = "the delivery does not build: no test could run (unattributed by rule)"
    return [{"group": gid, "contract": contract[gid], "failed_tests": names} for gid, names in sorted(score_v2.broken_groups(mech, groups_doc).items())]


def main():
    ap = argparse.ArgumentParser(description="The regression groups a delivery broke")
    ap.add_argument("--mechanical", required=True)
    ap.add_argument("--groups", required=True)
    ap.add_argument("--out")
    a = ap.parse_args()
    with open(a.mechanical, encoding="utf-8") as f:
        mech = json.load(f)
    with open(a.groups, encoding="utf-8") as f:
        groups = json.load(f)
    text = json.dumps({"broken": broken(mech, groups)}, ensure_ascii=False, indent=2)
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(text + "\n")
    else:
        print(text)


if __name__ == "__main__":
    main()
