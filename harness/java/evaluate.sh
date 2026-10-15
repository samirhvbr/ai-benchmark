#!/usr/bin/env bash
# Evaluates a delivery of a Java instance of the next series the way an evaluation does: the delivery's src/main and migrations/,
# with the instance's own pom.xml, schema.sql and public tests (and, to verify, its private verifiers), built and run offline in
# the pinned container against the disposable database (mvn.sh, dev-db.sh). Changes the delivery made to pom.xml, schema.sql or
# src/test do not count.
#   usage: evaluate.sh characterize|verify [INSTANCE_DIR [CODE_DIR [TEST_SELECTOR]]]
# Without the directories it reads the runner contract's LEB_INSTANCIA_DIR and LEB_ENTREGA_DIR (harness/leb_harness.py).
# TEST_SELECTOR narrows the verification to some verifiers (Surefire's -Dtest, e.g. 'V02_ConcurrencyTest#bug011*').
# stdout carries one JSON object, everything else goes to stderr:
#   characterize  {"passed": N, "failed": M, "failed_tests": ["Class#method", ...]}, plus "build_failed": true when the delivery does not
#                 compile (every public test fails, and all of them are named); the names feed the regression rule of the new series
#   verify        {"probes": [...], "decoys": [...], "inconclusive": [...]}; probes follow scoring/probe-result.schema.json
# LEB_KEEP_WORK=1 keeps the assembled project for a look afterwards.
# Exit status 3 means the evaluator itself failed (no Docker, no database, a Maven run that did not finish without a compiler diagnostic of
# the delivery, an unreadable result): never read it as a verdict (harness/java/build_status.py).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
MODE="${1:?usage: evaluate.sh characterize|verify [INSTANCE_DIR [CODE_DIR [TEST_SELECTOR]]]}"
INST="$(cd "${2:-${LEB_INSTANCIA_DIR:?no instance: pass INSTANCE_DIR or set LEB_INSTANCIA_DIR}}" && pwd)"
CODE="$(cd "${3:-${LEB_ENTREGA_DIR:?no delivery: pass CODE_DIR or set LEB_ENTREGA_DIR}}" && pwd)"
SELECTOR="${4:-}"
case "$MODE" in characterize|verify) ;; *) echo "unknown mode: $MODE" >&2; exit 2;; esac
PUB="$INST/public/code"

WORK="$(mktemp -d "${LEB_RUN_DIR:-${TMPDIR:-/tmp}}/leb-java.XXXXXX")"
if [ -z "${LEB_KEEP_WORK:-}" ]; then trap 'rm -rf "$WORK"' EXIT; else echo "[evaluate] work folder kept: $WORK" >&2; fi
cp "$PUB/pom.xml" "$PUB/schema.sql" "$WORK/"
mkdir -p "$WORK/src"
if [ -d "$CODE/src/main" ]; then cp -r "$CODE/src/main" "$WORK/src/main"; fi
if [ -d "$CODE/migrations" ]; then cp -r "$CODE/migrations" "$WORK/migrations"; fi
cp -r "$PUB/src/test" "$WORK/src/test"
ARGS=(-o test -Dsurefire.failIfNoSpecifiedTests=false -Dmaven.test.failure.ignore=true -DtrimStackTrace=false)
if [ "$MODE" = verify ]; then
  cp -r "$INST/private/verify/src/test/." "$WORK/src/test/"
  ARGS+=("-Dtest=${SELECTOR:-V0*Test}")
fi

"$HERE/dev-db.sh" ensure >&2 || { echo "the disposable database did not start" >&2; exit 3; }
set +e
LEB_DB=1 LEB_M2_MODE=ro "$HERE/mvn.sh" "$WORK" "${ARGS[@]}" > "$WORK/maven.log" 2>&1
MAVEN_STATUS=$?
set -e
tail -n 40 "$WORK/maven.log" >&2

python3 -I - "$MODE" "$WORK" "$PUB" "$INST/private/matrix.json" "$MAVEN_STATUS" "$HERE" <<'PY'
import glob, json, os, re, sys
import xml.etree.ElementTree as ET

mode, work, pub, matrix_path, status, here = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5]), sys.argv[6]
sys.path.insert(0, here)
import build_status
import test_names
log = open(os.path.join(work, "maven.log"), encoding="utf-8", errors="replace").read()
reports = glob.glob(os.path.join(work, "target", "surefire-reports", "TEST-*.xml"))

# Only a compiler diagnostic makes the delivery's build its own failure; any other failed run is the evaluator's (build_status.py).
outcome = build_status.classify(log, status)
if outcome == build_status.EVALUATOR_FAILED:
    print("[evaluate] Maven did not finish (exit %d) and not on a compiler diagnostic of the delivery's code: the evaluation failed, "
          "it is not a verdict; see the log above" % status, file=sys.stderr)
    sys.exit(3)
build_failed = outcome == build_status.DELIVERY_DOES_NOT_COMPILE

if mode == "characterize":
    if build_failed:
        names = test_names.source_test_names(os.path.join(pub, "src", "test"))
        print(json.dumps({"passed": 0, "failed": len(names), "failed_tests": names, "build_failed": True}))
        sys.exit(0)
    passed = failed = 0
    for path in reports:
        suite = ET.parse(path).getroot()
        n, f, e, s = (int(suite.get(k, 0)) for k in ("tests", "failures", "errors", "skipped"))
        failed += f + e
        passed += n - f - e - s
    print(json.dumps({"passed": passed, "failed": failed, "failed_tests": test_names.failed_test_names(reports)}))
    sys.exit(0)

probes, decoys, inconclusive = [], [], []
if build_failed:
    matrix = json.load(open(matrix_path, encoding="utf-8")) if os.path.exists(matrix_path) else {"entries": []}
    for e in matrix["entries"]:
        msg = "the delivery does not compile with the instance's pom.xml and public tests"
        if e.get("exists"):
            probes.append({"id": e["id"], "result": "none", "proves": ["C3" if e.get("template") == "C" else "R3"], "msg": msg})
        else:
            decoys.append({"id": e["id"], "holds": False, "msg": msg})
    print(json.dumps({"probes": probes, "decoys": decoys, "inconclusive": [], "build_failed": True}, ensure_ascii=False))
    sys.exit(0)
for path in sorted(glob.glob(os.path.join(work, "target", "verdicts", "*.json"))):
    v = json.load(open(path, encoding="utf-8"))
    if v.get("inconclusive"):
        inconclusive.append({"id": v["id"], "msg": v.get("msg", "")})
    elif v.get("decoy"):
        decoys.append({"id": v["id"], "holds": v["holds"], "msg": v.get("msg", "")})
    else:
        probes.append({k: v[k] for k in ("id", "result", "proves", "unobserved", "msg") if k in v})
print(json.dumps({"probes": probes, "decoys": decoys, "inconclusive": inconclusive}, ensure_ascii=False))
PY
