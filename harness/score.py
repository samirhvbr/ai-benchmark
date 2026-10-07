#!/usr/bin/env python3
"""
score.py — montador do scorecard LEB (PROTOCOL.md §5 passo 7).

Determinístico: junta a evidência MECÂNICA do harness (leb_harness.py) com o
VEREDITO do juiz (steps 4-5; ver scoring/JUDGE.md + scoring/judge.schema.json) e a
matriz (gabarito), e calcula o scorecard oficial de 1000 pontos exatamente pelas
regras de scoring/SCORING.md — nada de julgamento aqui, só aritmética.

Uso:
    python3 harness/score.py \
        --matrix instances/LEB-100-A/private/matrix.json \
        --mechanical relatorio_mecanico.json \
        --judge veredito_do_juiz.json \
        [--cost cost_time.json] [--out scorecard.json]

Saída: scorecard JSON conforme scoring/scorecard.schema.json.
"""
import argparse
import json
import math
import sys

# Pontos-base por critério, por severidade e template (SCORING.md §1).
CRIT = {
    "C": {
        "Crítica": {"C1": 2, "C2": 2, "C3": 3, "C4": 2, "C5": 1},
        "Alta":    {"C1": 2, "C2": 1, "C3": 3, "C4": 1, "C5": 1},
        "Média":   {"C1": 1, "C2": 1, "C3": 2, "C4": 1, "C5": 1},
        "Baixa":   {"C1": 1, "C2": 1, "C3": 1, "C4": 1, "C5": 1},
    },
    "R": {
        "Crítica": {"R1": 3, "R2": 2, "R3": 5, "R4": 2},
        "Alta":    {"R1": 2, "R2": 2, "R3": 4, "R4": 2},
        "Média":   {"R1": 2, "R2": 1, "R3": 4, "R4": 1},
        "Baixa":   {"R1": 1, "R2": 1, "R3": 3, "R4": 1},
    },
}
CATEGORY_WEIGHT = {"SEC": 250, "ARCH": 200, "BUG": 150, "PERF": 150, "CLN": 100}
CAT_OF_TEMPLATE = None  # categoria vem do prefixo do ID
COMP_DEDUCTION = {  # SPEC §6.1
    "COMP-001": 30, "COMP-002": 20, "COMP-003": 30, "COMP-004": 20, "COMP-005": 15,
    "COMP-006": 10, "COMP-007": 20, "COMP-008": 25, "COMP-009": 25, "COMP-010": 20,
}
DIFF_WEIGHT = {"Fácil": 1, "Moderada": 2, "Difícil": 3, "Especialista": 4}


def prefix(fid):
    return fid.split("-")[0]


def verdict_points(base, verdict):
    """full → base · half → floor(base/2) · none/ausente → 0 (SCORING §2)."""
    if verdict == "full":
        return base
    if verdict == "half":
        return base // 2
    return 0


def load(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def main():
    ap = argparse.ArgumentParser(description="Montador do scorecard LEB (passo 7)")
    ap.add_argument("--matrix", required=True)
    ap.add_argument("--mechanical", required=True)
    ap.add_argument("--judge", required=True)
    ap.add_argument("--cost", help="JSON com o bloco cost_time (opcional)")
    ap.add_argument("--out")
    a = ap.parse_args()

    matrix = load(a.matrix)
    mech = load(a.mechanical)
    judge = load(a.judge)

    if mech.get("inconclusive"):
        sys.exit("[score] o relatório mecânico é INCONCLUSIVO (%s): não há medição para pontuar" % mech.get("inconclusive_reason", "sem motivo"))

    planted = {e["id"]: e for e in matrix["entries"] if e.get("exists")}
    iscas = {e["id"] for e in matrix["entries"] if not e.get("exists")}

    # evidência mecânica: C3 das falhas cobertas por probe + C4 (regressão global)
    probe_c3 = {p["id"]: bool(p["corrigida"]) for p in mech.get("probes", []) if "corrigida" in p}
    # Structural evidence for the refactoring criterion of Template R (opt-in: a probe with `result` and `proves` naming R3).
    # Evidence is a ceiling, never a floor: it can confirm or lower the judge's R3, never raise it, and never touches R1, R2 or R4.
    probe_evidence_r3 = {}
    for p in mech.get("probes", []):
        proves = p.get("proves")
        if proves is None:
            continue
        bad = [x for x in proves if x not in ("C3", "R3")]
        if bad:
            sys.exit("[score] a prova %s declara comprovar %s: a evidência mecânica só pode comprovar C3 ou R3" % (p["id"], bad))
        if "R3" in proves:
            if p.get("result") not in ("full", "half", "none"):
                sys.exit("[score] a prova %s declara R3 sem `result` full|half|none" % p["id"])
            probe_evidence_r3[p["id"]] = p["result"]
    regression = bool(mech.get("characterization", {}).get("regression"))
    broken_tests = (mech.get("characterization", {}).get("submission", {}) or {}).get("failed") or 0

    judge_planted = {p["id"]: p for p in judge.get("planted", [])}

    findings = []
    cat_earned = {c: 0 for c in CATEGORY_WEIGHT}
    cat_possible = {c: 0 for c in CATEGORY_WEIGHT}
    diff_stat = {}          # dificuldade -> {planted, detected, corrected}
    dim_stat = {}           # informative dimension -> {planted, detected, corrected} (only when the matrix declares `dimensions`)
    informative_affected = mech.get("informative_affected") or {}
    calib = []              # (confiança, acerto) sobre achados reportados

    for fid, entry in planted.items():
        cat = prefix(fid)
        tpl = entry["template"]
        sev = entry["severity"]
        diff = entry.get("difficulty", "?")
        base = CRIT[tpl][sev]
        possible = sum(base.values())
        cat_possible[cat] = cat_possible.get(cat, 0) + possible
        diff_stat.setdefault(diff, {"planted": 0, "detected": 0, "corrected": 0})
        diff_stat[diff]["planted"] += 1

        jv = judge_planted.get(fid, {})
        crit_in = dict(jv.get("criteria", {}))

        # resolve C3 (a probe tem prioridade sobre o juiz nas falhas que cobre)
        if tpl == "C" and fid in probe_c3:
            crit_in["C3"] = "full" if probe_c3[fid] else "none"

        evidence = None
        if tpl == "R" and fid in probe_evidence_r3:
            order = {"none": 0, "half": 1, "full": 2}
            judged_r3 = crit_in.get("R3", "none")
            applied = min(judged_r3, probe_evidence_r3[fid], key=order.get)
            evidence = {"R3": probe_evidence_r3[fid], "judge_R3": judged_r3, "applied_R3": applied}
            crit_in["R3"] = applied

        # critérios de "qualidade do conserto" só contam se houve conserto (C3/R3 tentado):
        # sem correção não há "sem regressão" nem "compatibilidade" a premiar (SCORING §2).
        corr_key_ = "C3" if tpl == "C" else "R3"
        attempted = crit_in.get(corr_key_) in ("full", "half")
        if tpl == "C":
            if "C4" not in crit_in:  # respeita override explícito do juiz
                crit_in["C4"] = "full" if (attempted and not regression) else "none"
            if not attempted:
                crit_in["C5"] = "none"
        else:
            if not attempted:
                crit_in["R4"] = "none"

        # pontua critério a critério
        crit_pts, earned = {}, 0
        for k, b in base.items():
            v = crit_in.get(k, "none")
            pts = verdict_points(b, v)
            crit_pts[k] = pts
            earned += pts
        cat_earned[cat] += earned

        reported = bool(jv.get("reported"))
        found_key = "C1" if tpl == "C" else "R1"
        corr_key = "C3" if tpl == "C" else "R3"
        detected = crit_in.get(found_key) in ("full", "half")
        corrected = crit_in.get(corr_key) == "full"
        if detected:
            diff_stat[diff]["detected"] += 1
        if corrected:
            diff_stat[diff]["corrected"] += 1
        for dim in entry.get("dimensions", []):
            d = dim_stat.setdefault(dim, {"planted": 0, "detected": 0, "corrected": 0})
            d["planted"] += 1
            d["detected"] += 1 if detected else 0
            d["corrected"] += 1 if corrected else 0
        if reported and jv.get("confidence") is not None:
            calib.append((jv["confidence"], 1))  # reportou falha real => acerto

        findings.append({
            "id": fid, "template": tpl, "severity": sev, "difficulty": diff,
            "criteria": crit_pts, "points_earned": earned, "points_possible": possible,
            "confidence": jv.get("confidence"),
            "reported": reported,
            **({"evidence": evidence} if evidence else {}),
            **({"dimensions": entry["dimensions"]} if entry.get("dimensions") else {}),
            **({"informative_affected": entry.get("informative_affected") or informative_affected[fid]}
               if (entry.get("informative_affected") or informative_affected.get(fid)) else {}),
        })

    # falsos positivos (iscas ou invenções reportadas) -> PEN-004 + calibração
    fp = judge.get("false_positives", [])
    pen004_count = sum(1 for f in fp if f.get("is_isca") or f.get("reported_as") in iscas)
    decoy_kind = {e["id"]: e["decoy_kind"] for e in matrix["entries"] if not e.get("exists") and e.get("decoy_kind")}
    for f in fp:
        if f.get("confidence") is not None:
            calib.append((f["confidence"], 0))

    # normalização por categoria (SCORING §4)
    categories = {}
    for c, w in CATEGORY_WEIGHT.items():
        poss = cat_possible.get(c, 0)
        earn = cat_earned.get(c, 0)
        score = round(earn / poss * w) if poss else 0
        categories[c] = {"raw_earned": earn, "raw_possible": poss, "score": score, "max": w}

    # COMP (§5): 100 - Σ descontos, piso 0
    comp_v = judge.get("comp_violations", [])
    comp_ded = sum(COMP_DEDUCTION.get(v["id"], 0) * int(v.get("count", 1)) for v in comp_v)
    categories["COMP"] = {
        "score": max(0, 100 - comp_ded), "max": 100,
        "violations": [{"id": v["id"], "count": int(v.get("count", 1)),
                        "deduction": -COMP_DEDUCTION.get(v["id"], 0) * int(v.get("count", 1)),
                        "detail": v.get("detail", "")} for v in comp_v],
    }

    # EXPL (§6)
    rub = judge.get("expl_rubric", {})
    expl_score = sum(int(rub.get(k, 0)) for k in ("clareza", "precisao", "causa_raiz", "priorizacao", "trade_offs"))
    categories["EXPL"] = {"score": expl_score, "max": 50, "rubric": {k: int(rub.get(k, 0)) for k in
                          ("clareza", "precisao", "causa_raiz", "priorizacao", "trade_offs")}}

    # penalidades (§6.2)
    jp = judge.get("penalties", {})
    penalties = []
    if broken_tests:
        penalties.append({"id": "PEN-002", "count": broken_tests, "deduction": -20 * broken_tests,
                          "detail": f"{broken_tests} teste(s) de caracterização quebrado(s)"})
    for pid, per in (("PEN-001", 15), ("PEN-003", 25)):
        n = int(jp.get(pid, 0))
        if n:
            penalties.append({"id": pid, "count": n, "deduction": -per * n})
    if pen004_count:
        penalties.append({"id": "PEN-004", "count": pen004_count,
                          "deduction": -min(25, 5 * pen004_count),
                          "detail": "isca(s) reportada(s)"})
    pen_total = sum(p["deduction"] for p in penalties)

    # TOTAL (§7): Σ categorias + COMP + EXPL + Σ penalidades (piso 0, teto 1000)
    total = sum(categories[c]["score"] for c in CATEGORY_WEIGHT) + categories["COMP"]["score"] + expl_score + pen_total
    total = max(0, min(1000, total))
    grade = ("Platinum" if total >= 900 else "Gold" if total >= 750 else
             "Silver" if total >= 600 else "Bronze" if total >= 400 else "Reprovada")

    # calibração (§9.1)
    calibration = None
    if calib:
        brier = round(sum((c / 100 - o) ** 2 for c, o in calib) / len(calib), 3)
        hi = [(c, o) for c, o in calib if c >= 80]
        hi_fp = round(sum(1 for c, o in hi if o == 0) / len(hi), 3) if hi else 0
        bins = []
        for lo, hi_ in ((81, 100), (61, 80), (41, 60), (21, 40), (0, 20)):
            grp = [(c, o) for c, o in calib if lo <= c <= hi_]
            if grp:
                bins.append({"range": f"{lo}-{hi_}", "count": len(grp),
                             "mean_confidence": round(sum(c for c, _ in grp) / len(grp), 1),
                             "hit_rate": round(sum(o for _, o in grp) / len(grp), 3)})
        calibration = {"reported_count": len(calib), "brier": brier,
                       "high_conf_false_positive_rate": hi_fp, "bins": bins}

    # dificuldade (§9.2)
    num = sum(DIFF_WEIGHT.get(d, 0) * s["detected"] for d, s in diff_stat.items())
    den = sum(DIFF_WEIGHT.get(d, 0) * s["planted"] for d, s in diff_stat.items())
    difficulty_breakdown = {
        "levels": [{"difficulty": d, "planted": s["planted"], "detected": s["detected"],
                    "corrected": s["corrected"]} for d, s in sorted(diff_stat.items())],
        "discovery_index": round(100 * num / den, 1) if den else 0,
    }

    scorecard = {
        "leb_spec": matrix.get("leb_spec"),
        "model": judge.get("model", {"name": "?", "version": "?"}),
        "instance": f'{matrix.get("instance")} v{matrix.get("version")}',
        "matrix_sha256": mech.get("matrix_sha256"),
        "protocol": judge.get("protocol", {"mode": "S", "date": judge.get("date", "?"),
                                            "runs": judge.get("runs", [total, total, total])}),
        "findings": findings,
        "categories": categories,
        "penalties": penalties,
        "extra_findings": judge.get("extra_findings", []),
        "total": total,
        "grade": grade,
    }
    if calibration:
        scorecard["calibration"] = calibration
    scorecard["difficulty_breakdown"] = difficulty_breakdown
    # Informative only (E9): present only when the matrix declares dimensions / decoy kinds. No weight, point or penalty reads them.
    if dim_stat:
        scorecard["dimension_breakdown"] = [{"dimension": d, **dim_stat[d]} for d in sorted(dim_stat)]
    if decoy_kind:
        reported_decoys = {f.get("reported_as") for f in fp if f.get("reported_as") in iscas}
        scorecard["decoy_breakdown"] = [
            {"kind": k, "decoys": sum(1 for v in decoy_kind.values() if v == k),
             "reported": sum(1 for i, v in decoy_kind.items() if v == k and i in reported_decoys)}
            for k in sorted(set(decoy_kind.values()))]
    if a.cost:
        scorecard["cost_time"] = load(a.cost)
    elif judge.get("cost_time"):
        scorecard["cost_time"] = judge["cost_time"]

    out = json.dumps(scorecard, ensure_ascii=False, indent=2)
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(out + "\n")
        print(f"[score] scorecard → {a.out}  (TOTAL {total}/1000 · {grade})", file=sys.stderr)
    else:
        print(out)


if __name__ == "__main__":
    main()
