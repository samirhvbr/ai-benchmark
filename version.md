# Versão — AI-BENCHMARK

**Versão atual:** `0.1.6`

Padrão de avaliação de engenharia de software para LLMs (spec RFC, instâncias LEB, harness e scorecard).

> ⚠️ A **spec RFC tem versão própria** (hoje `1.2.0`, declarada no `README.md`), e as
> instâncias também (LEB-100-A `v1.1`). Este arquivo versiona o **repositório**, não a
> spec — os números são independentes de propósito.

> Este arquivo é a **fonte da verdade** da versão do projeto: quem precisar exibir ou
> reportar a versão extrai o **primeiro número semver (`X.Y.Z`)** encontrado aqui.
> Mantenha a linha **"Versão atual"** como a primeira ocorrência de um número.
>
> `0.1.0` marca o início do **versionamento**, não o início do projeto — o que veio
> antes continua no `git log`.

---

## 1. Convenção de Versionamento (`X.Y.Z`)

| Componente | Significado | Como sobe |
|---|---|---|
| **X** | Release estável | Manual |
| **Y** | Mudança estrutural — Nova seção do conjunto, mudança de escopo, reorganização estrutural. | Manual |
| **Z** | Incremento a cada entrega (ver gatilhos) | A cada entrega |

### Gatilhos de bump do `Z`

- Criar ou remover um **documento** do conjunto.
- Mudar uma **regra, decisão ou procedimento** já publicado.
- Alterar **estrutura ou formato** que outra ferramenta consome.
- Adicionar ou alterar **dado/exemplo** que serve de referência.

> Correção de texto, comentário e formatação **não** exigem bump.

---

## 2. Formato de Commit Obrigatório

```
X.Y.Z - Descrição curta em português
```

**Regras inegociáveis:**

1. A versão **sempre** vem deste `version.md` — bumpe **no mesmo commit** da mudança.
2. Mensagem em **português**, específica o suficiente para `git log --grep`.
3. **Proibido** Conventional Commits (`feat:`, `fix:`, `chore:`…) e mensagens vagas
   ("ajuste", "update", "wip").
4. Um objetivo por commit.

> **A skill COMMITTER commita por você neste repo** (existe `.committer.yml` na raiz).
> Escreva a entrada de changelog abaixo ao concluir a entrega: é **dali** que a
> mensagem do commit sai, sem custo de modelo. Sem a entrada, a skill cai num
> fallback que gasta tokens e descreve pior do que você. Detalhe no bloco PS do
> `CLAUDE.md`.

---

## 3. Changelog

> Ordem decrescente (mais recente no topo).

### `0.1.3` — 2026-09-02 — Agent doc: Releases rule and the English-only language rule

Marked echo of the single source at samirhvbr/repodocs. Two rules land here:

1. The `version.md` of the default branch ON GITHUB is what the GitHub Releases
   show, and a commit that bumps it is not finished until that version has a
   tag, a Release and the `Latest` badge — same push, not "later".
2. Everything in this repository is English (US): documents, commit messages,
   pull requests, issues, code comments. The only carve-out is end-user-facing
   product strings. History is not rewritten.

Delimited by a marker, so re-running replaces instead of duplicating.

### `0.1.2` — 2026-09-02 — Regra de Releases no doc de agente: bump e Release sao um ato so

Eco marcado da norma unica em samirhvbr/repodocs (docs/versioning.md). O
`version.md` da branch padrao NO GITHUB e o que as Releases no GitHub mostram, e
um commit que bumpa o `version.md` nao esta terminado ate aquela versao ter tag,
Release e o badge `Latest`.

Bloco delimitado por marcador: rodar de novo substitui, nao duplica.

### `0.1.1` — 2026-09-02 — Releases automaticas: o version.md da master vira tag e Release

O GitHub nao deduz versao de mensagem de commit: sem tag, o numero e string no
`git log` e `git diff` entre versoes nao existe. Entram o
`.github/workflows/release.yml` e o `tools/release.sh`.

**A regra:** o `version.md` da branch padrao **no GitHub** e o que as Releases
**no GitHub** refletem. Checkout local nao entra na conta. Um PR nao publica
nada; no merge, o push do `version.md` dispara o workflow e a Release vira
aquela versao.

Tag e titulo = a versao pura, sem prefixo `v`. Norma:
[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md).

### `0.1.0` — 2026-07-30 — Adota o versionamento da casa

Passa a seguir o padrão dos demais repositórios: `version.md` como fonte da verdade,
commits no formato `X.Y.Z - Descrição em português` e changelog como registro de
entrega.

O gatilho foi prático: o repo já participava da skill **COMMITTER**, mas sem
`version.md` não existia o formato da casa — o ciclo reportava e **não commitava**.
Com este arquivo, a skill passa a operar aqui pelo caminho determinístico (sem custo
de modelo), lendo a mensagem da entrada de changelog.

_Gatilhos:_ adoção de infraestrutura de versionamento.
