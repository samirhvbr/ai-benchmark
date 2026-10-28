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

Feito em 2026-10-25 (detalhe no §9b do `CATALOG.md` e no README da instância): 1 catálogo, 2 plantio das 24 + a isca que precisa de código, 3 verificadores de exploração
provados nos dois sentidos, 4 matriz (200 pontos-base) e pesos (rascunho), 5 os 69 casos variantes, 6 enunciado (`TASK-S-1.0.0.md`), mensagem e contrato do runner, e o pacote
(`./leb pacote LEB-S-300-A`: 56 arquivos). **A instância não está congelada.** Falta, nesta ordem (a ordem existe porque uma revisão pode mudar a chave, e os hashes
se registram depois da última mudança; o D congelou antes e precisou da versão 1.1):

1. **A revisão do Samir sobre o catálogo** (28/10: ele pediu para rever de novo, em artefato com as perguntas; cinco pontos ainda eram proposta minha).
2. **A revisão externa da chave** (como a do D; ele disse "vamos fazer" em 28/10). Quem revisa e como: a definir com ele.
3. **A prova numa VM de bancada** (`docs/candidate-vm.md` §5, passo 4), em cópia descartável do pacote. Ele pediu uma VM para isso e passa os acessos.
4. **O congelamento**: hashes registrados no README da instância e no §9, commit feito e enviado antes de qualquer run.
5. **Juízes da trilha** (`scoring/JUDGE.md`: classes, iscas, falso positivo) e o lote de runs, pelo laboratório (`operador/laboratorio.py`).

O status fica no README da instância ("Where it stands") e no §9; atualizar nos dois a cada passo.

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
