# LEB-S-300-A — a trilha de segurança, começando pelo MiniMax M3.1 e pelo MiniMax M3

> **Registrado em:** 2026-10-25 · **Origem:** ordem do Samir no chat: "vamos começar o LEB-S, vamos começar com o minimax,
> faz com o M3.1 e com o M3 o LEB-S por enquanto" e "coloca na fila esse do LEB-S, terminando o que eu passei, vamos fazer o LEB-S".
> **Ordem combinada:** primeiro fechar o que ele já passou (filas do D e do LEB-300-A em andamento); o LEB-S entra depois, nesta ordem.

Este item sai da fila quando a instância existir **completa e congelada** e os primeiros casos (MiniMax M3.1 e MiniMax M3) tiverem
rodado, sido julgados e publicados. Reescrever ou mover este documento não o tira da fila.

## O que já existe (não refazer)

- O sistema A (CondoDesk, Java 21 + Maven + PostgreSQL): referência limpa, 70 testes de caracterização, `manifest.md`,
  `SecurityBehaviorTest` (as defesas da referência, lugar por lugar da §5 de `systems/300-A/README.md`).
- O avaliador da série nova (`harness/score_v2.py`, dois juízes cegos por entrega, revisão das divergências, regressão por nome de teste),
  o scorer da trilha Default e o esquema do scorecard v2. Falta só o arquivo de pesos da trilha de segurança.
- A taxonomia `taxonomy/SEC.md` (20 classes, com a correção canônica de cada uma) e o desenho da trilha (`docs/new-series-design.md` §4.2).
- O ambiente das VMs: o snapshot `leb-d-300-a` (Claude Code, banco de teste, cache Maven offline de 213 arquivos) serve ao mesmo sistema, sem pacote.
- O laboratório de runs sem operador (`operador/laboratorio.py`, `laboratorio-opencode`): os casos do MiniMax entram por ele.

## Já existe desde 2026-10-25

- `instances/LEB-S-300-A/private/CATALOG.md`: o rascunho do perfil (24 vulnerabilidades em 19 classes, 6 iscas), esperando a revisão do Samir. Achado do rascunho: o servidor
  HTTP do JDK 21 recusa CR/LF em cabeçalho, então a classe SEC-007 não é plantável nesta pilha e fica só como isca.

## O que falta (a lista do §9 de `systems/300-A/README.md`, na ordem do que depende do quê)

1. **Catálogo do perfil** (`instances/LEB-S-300-A/private/CATALOG.md`): que classes de `SEC` entram, em que lugar da referência, em que
   dificuldade, e quais iscas (código correto que parece arriscado). Rascunho meu, revisão do Samir **antes** de plantar.
2. **Plantio**: módulos em `private/plant/` e a árvore plantada em `public/code/`, com a suíte pública passando; o teste que apontar uma
   falha plantada sai da suíte pública (`removed-from-public-tests.txt`), como no LEB-D.
3. **Verificadores de exploração** (`private/verify/`): o ataque de referência mais variações de cada falha. Uma correção só conta se
   o ataque e as variações param, o usuário legítimo mantém a função e os contratos do manifest valem. Cada verificador é provado nos
   dois sentidos (a árvore plantada falha; a referência passa).
4. **Matriz** (`private/matrix_rows.py`, escrita por `tools/make_matrix.py`) e `scoring/tracks/security.json` (rascunho: SEC 850 · COMP 100 · EXPL 50).
5. **Casos variantes** (`private/variants/`, `tools/variants.py`): correção certa aceita; correção parcial, errada ou "desligar a função" rejeitada.
6. **Enunciado da trilha** (`protocol/tasks/TASK-S-1.0.0.md`, em inglês, sem dica do gabarito) e o contrato do runner (`private/runner.json`).
7. **Pacote** (`./leb pacote LEB-S-300-A`), prova numa VM (`docs/candidate-vm.md` §5), revisão externa da chave antes de congelar (como a 1.1 do D) e **congelamento**.
8. **Juízes da trilha** (`scoring/JUDGE.md`: classes, iscas, falso positivo) e o lote de runs.

## O primeiro caso

MiniMax M3.1 e MiniMax M3, pelo OpenCode, cada um com cinco runs de 90 minutos e dois juízes cegos por entrega, nas mesmas regras do LEB-D
(a mensagem fixa, nenhuma resposta do operador, auditoria da sessão). Depois dos dois, o Samir decide os próximos modelos.

## Decidido pelo Samir em 2026-10-25 (AskUserQuestion)

- Perfil de 24 plantadas + 6 iscas.
- SEC-004 por checagem estrutural do `app.js`.
- PBKDF2 em 600 mil iterações, como correção só do S (primeiro módulo do perfil; a referência compartilhada não muda, porque o D está congelado).
- Cinco runs por modelo no primeiro caso (MiniMax M3.1 e M3).

## Ainda proposta minha, sem resposta dele

- Pesos SEC 850 · COMP 100 · EXPL 50; as rotas do D (M3.1 pela variante gratuita do OpenRouter, M3 pela chave do plano); SEC-007 só como isca;
  revisão externa da chave antes de congelar.
