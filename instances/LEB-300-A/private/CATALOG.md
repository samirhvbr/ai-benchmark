# LEB-300-A catalog (private, working document)

36 planted flaws + 5 decoys. Counts match `F1-desenho.md §12`: SEC 9, ARCH 8, BUG 8, PERF 7, CLN 4. Occurrence suffix `.a/.b` only when two occurrences
of one taxonomy type have different causes and fixes. No new taxonomy IDs. "Site" is the class in the reference layout; the candidate may move it.
Template C = behavioral (probe decides C3). Template R = structural or behavioral evidence caps R3.

Authoring order: everything that is not SEC first; SEC last (models fall back when work touches security).

## SEC (Template C)

| ID | Sev | Diff | Site | Mechanism | Probe |
|---|---|---|---|---|---|
| SEC-001 | Crítica | Fácil | `ChamadoRepo.listar` | `status` / `prioridade` filter values concatenated into the SQL | a crafted `status` must not change the result set or raise a database error |
| SEC-016 | Crítica | Moderada | `CadastroHandlers` PATCH `/usuarios/{id}` | only authentication is checked, not the ADMIN role | ATENDENTE patching a `papel` gets 403 and the row is unchanged |
| SEC-017.a | Crítica | Difícil | `ChamadoService.eventos` | detail checks the company, the event list does not | other company's chamado `/eventos` is 404 |
| SEC-015 | Alta | Fácil | `App.start` | bootstrap platform account with a literal password in the code | no login with the literal; literal absent from `src/main` |
| SEC-014 | Alta | Moderada | `Auth.hash` | new passwords stored as unsalted SHA-256 | same password for two users gives different `senha_hash`, never `sha256(password)`; login works; the legacy `sha256:` format still logs in |
| SEC-017.b | Alta | Especialista | `RelatorioService.sla` | SLA report cache keyed by month only | company B asking after company A gets B's numbers |
| SEC-008 | Média | Difícil | `CsvExportador` | text cells starting with `= + - @` are not neutralized | a title `=1+1` is exported as `'=1+1`; the rest of the file is byte-identical |
| SEC-020 | Média | Moderada | `CadastroRepo` / users handler | `GET /usuarios` returns `senha_hash` | no `senha_hash` key in any user JSON |
| SEC-013 | Baixa | Difícil | `Auth.logout` | the invalidating UPDATE runs on a connection that is never committed | the token is 401 after logout |

## ARCH (Template R)

| ID | Sev | Diff | Site | Mechanism | Structural property (name-independent) |
|---|---|---|---|---|---|
| ARCH-001 | Crítica | Difícil | `ChamadoService` | one class opens, patches, transitions, assigns, deduplicates, fans out notifications and computes deadlines | no application class has fan-out above 12 application classes or more than 30 methods |
| ARCH-002 | Alta | Moderada | `ChamadoHandlers` | the HTTP handler runs SQL for the transition | no class that references `com.sun.net.httpserver` references `java.sql` or `javax.sql` |
| ARCH-004 | Média | Difícil | `EscalonamentoJob` + `NotificacaoService` | the escalation recipient rule is written twice | no recipient-resolution SQL literal appears in more than one class |
| ARCH-008 | Alta | Difícil | `servico` ↔ `repo` | a package cycle through a shared static helper | the package dependency graph has no cycle, with at least as many packages as the legacy |
| ARCH-013 | Alta | Especialista | `App` / `Contexto` | `DataSource` and `Clock` are kept in a static singleton | two `App` instances in one JVM with different clocks and schemas stay independent |
| ARCH-011 | Média | Moderada | `RelatorioService` | one class queries, computes, formats the CSV and caches | the class holding the CSV header literal references no `java.sql` or `javax.sql` |
| ARCH-014 | Média | Moderada | `App`, `Auth` | `Config.tokenValidade` and `Config.intervaloJobs` are ignored (8 h and 60 s in code) | a 1-minute `tokenValidade` expires the token on a clock moved 2 minutes |
| ARCH-009 | Baixa | Fácil | `SlaService`, `EscalonamentoJob` | the 0.8 escalation threshold written as a bare literal in two places | the constant `0.8` (or 8 and 10) appears in at most one class |

## BUG (Template C)

| ID | Sev | Diff | Site | Mechanism | Probe |
|---|---|---|---|---|---|
| BUG-011.a | Crítica | Especialista | `ChamadoService.atualizar` | PATCH reads the row and writes every column back | two concurrent PATCHes on different fields keep both, at a forced interleaving |
| BUG-011.b | Alta | Especialista | `DespachoJob` | the claim of a PENDENTE notification is read-then-write | two runners at a forced interleaving deliver once |
| BUG-014 | Alta | Difícil | `LancamentoJob` | the resume point is saved before the work, in another transaction | an interruption at every command boundary leaves no item skipped or duplicated |
| BUG-006 | Alta | Difícil | `RelatorioService`, `LancamentoJob` | the month and the `competencia` use the JVM zone, not the company zone | a company in another zone gets its own month boundary |
| BUG-007 | Média | Difícil | `SlaService` | `resolvido_em <= prazo` written `<`, and the window end `[inicio, fim)` closed on the wrong side | a delivery exactly at the deadline is met; a deadline falling exactly at closing time stays that instant |
| BUG-013 | Média | Moderada | `RelatorioService` | `percentual_cumprido` and the hour average truncated instead of `HALF_UP` | 2 of 3 gives 66.7 |
| BUG-009 | Média | Fácil | `Dominio.StatusChamado.pode` | the reopening `RESOLVIDO → EM_ATENDIMENTO` is missing from the switch | the reopening is 200 and clears `resolvido_em` |
| BUG-015 | Baixa | Moderada | `Servidor` | a handler failure is caught as `Exception` and reported as 400 instead of 500, hiding the cause | an internal failure is 500 with the generic body |

## PERF (Template C)

| ID | Sev | Diff | Site | Mechanism | Probe |
|---|---|---|---|---|---|
| PERF-001 | Alta | Moderada | `ChamadoRepo.listar` | one query per row for the client and technician names | commands per request constant between page sizes 10 and 100 |
| PERF-003 | Alta | Fácil | `OrdemRepo.listar` | loads every row, then slices the page in memory | rows consumed for a page of 10 does not grow with the table |
| PERF-005 | Alta | Difícil | `RelatorioService.tecnicos` | one query per technician | commands constant between 5 and 50 technicians |
| PERF-006 | Média | Moderada | schema | no index on the filter columns | after the candidate's migrations an index leads on `chamado(empresa_id, ...)` and `ordem_servico(chamado_id)` |
| PERF-011 | Média | Difícil | `CsvExportador` | every exported CSV kept in an unbounded map | a child JVM with `-Xmx48m` survives many distinct months |
| PERF-012 | Média | Especialista | `EscalonamentoJob` | `List.contains` inside the loop over candidates | the pass over 8,000 candidates is not 12 times slower than over 2,000 |
| PERF-013 | Baixa | Moderada | `Auth.validar` | session, user and company read by three separate queries per request | commands per authenticated request at most 2 |

## CLN (Template R)

| ID | Sev | Diff | Site | Mechanism |
|---|---|---|---|---|
| CLN-007 | Média | Moderada | `ChamadoService.transicionar` | nesting of 5 levels hiding the main flow |
| CLN-001 | Média | Moderada | `LancamentoJob.executar` | one method of about 90 lines |
| CLN-002 | Baixa | Fácil | `ChamadoHandlers`, `CadastroHandlers` | the pagination parser copied |
| CLN-005 | Baixa | Fácil | `Servidor` | a block of commented-out code left behind |

## Decoys (5): `exists: false`

| ID | Kind | Site | Looks like | Why it is not a flaw |
|---|---|---|---|---|
| D1 | diagnosis | `ChamadoRepo.ordenacao` | SQL injection | the concatenated text is the constant of an enum, never input |
| D2 | diagnosis | `Idempotencia.hash` | weak password hashing | SHA-256 of the request body as a content fingerprint, not a password |
| D3 | diagnosis | `SlaService.PADROES` | hard-coded configuration | the default SLA table is the contract (`manifest §3.4`) |
| I1 | intervention | `LancamentoJob` | needless blocking | the advisory lock keeps "exactly one `lancamento` per order" when two runners meet; removing it without another guard duplicates |
| I2 | intervention | `ChamadoService` | coupling of event and notification | the notifications are written in the same transaction as the event so they only become visible when the operation succeeds |

12 % of the matrix lines, 3 of diagnosis and 2 of intervention.
