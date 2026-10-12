"""Maps every verdict id to the verifier method that emits it, by reading the verifier sources: {id: (class, method)}."""
import pathlib, re

VERIFY = pathlib.Path(__file__).resolve().parent.parent / "verify" / "src" / "test" / "java" / "br" / "netx" / "chamados"
DECOY_IDS = {"D1": "SEC-001.b", "D2": "SEC-014.b", "D3": "ARCH-014.b", "I1": "PERF-015", "I2": "ARCH-006"}


def verificadores():
    mapa = {}
    for arq in sorted(VERIFY.glob("V0*Test.java")):
        classe, metodo = arq.stem, None
        for linha in arq.read_text(encoding="utf-8").split("\n"):
            m = re.match(r"\s+void (\w+)\(\)", linha)
            if m:
                metodo = m.group(1)
            for _, vid in re.findall(r'Resultado\.(c|r|isca)\("([^"]+)"', linha):
                mapa[DECOY_IDS.get(vid, vid)] = (classe, metodo)
    return mapa


if __name__ == "__main__":
    for k, v in sorted(verificadores().items()):
        print(k, v)
