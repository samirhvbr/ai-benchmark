"""BUG and CLN-free flaws of business logic: state machine, SLA boundaries, rounding, time zone, lost update, resume point, lost cause."""
from plantar import SRC

FLAWS = [

    ("BUG-009", [
        (SRC + "dominio/Dominio.java",
         "                case RESOLVIDO -> para == FECHADO || para == EM_ATENDIMENTO;",
         "                case RESOLVIDO -> para == FECHADO;"),
    ]),

    ("BUG-007", [
        (SRC + "servico/SlaService.java",
         "        return resolvidoEm != null && !resolvidoEm.isAfter(prazoResolucao);",
         "        return resolvidoEm != null && resolvidoEm.isBefore(prazoResolucao);"),
        (SRC + "servico/SlaService.java",
         "            if (restante <= disponivel) return t.plusSeconds(restante).toInstant();",
         "            if (restante < disponivel) return t.plusSeconds(restante).toInstant();"),
    ]),

    ("BUG-013", [
        (SRC + "relatorio/RelatorioService.java",
         "                : BigDecimal.valueOf(cumpridos * 100).divide(BigDecimal.valueOf(resolvidos), 1, RoundingMode.HALF_UP));",
         "                : BigDecimal.valueOf(cumpridos * 100).divide(BigDecimal.valueOf(resolvidos), 1, RoundingMode.DOWN));"),
        (SRC + "relatorio/RelatorioService.java",
         "t.mediaHoras().setScale(1, RoundingMode.HALF_UP)",
         "t.mediaHoras().setScale(1, RoundingMode.DOWN)"),
    ]),

    ("BUG-006", [
        (SRC + "relatorio/RelatorioService.java",
         "import java.time.YearMonth;",
         "import java.time.YearMonth;\nimport java.time.ZoneId;"),
        (SRC + "relatorio/RelatorioService.java",
         "            return new Instant[] {ym.atDay(1).atStartOfDay(e.zona()).toInstant(), ym.plusMonths(1).atDay(1).atStartOfDay(e.zona()).toInstant()};",
         "            return new Instant[] {ym.atDay(1).atStartOfDay(ZoneId.systemDefault()).toInstant(), ym.plusMonths(1).atDay(1).atStartOfDay(ZoneId.systemDefault()).toInstant()};"),
        (SRC + "job/LancamentoJob.java",
         "import java.time.YearMonth;",
         "import java.time.YearMonth;\nimport java.time.ZoneId;"),
        (SRC + "job/LancamentoJob.java",
         "            String competencia = YearMonth.from(o.concluidaEm().atZone(e.zona())).toString();",
         "            String competencia = YearMonth.from(o.concluidaEm().atZone(ZoneId.systemDefault())).toString();"),
    ]),

    ("BUG-011.a", [
        (SRC + "servico/ChamadoService.java",
         "        chamados.atualizar(tx, id, campos);\n        if (mudouPrioridade)",
         "        campos.putIfAbsent(\"titulo\", atual.titulo());\n"
         "        campos.putIfAbsent(\"descricao\", atual.descricao());\n"
         "        campos.putIfAbsent(\"prioridade\", atual.prioridade());\n"
         "        campos.putIfAbsent(\"tecnico_id\", atual.tecnicoId());\n"
         "        campos.putIfAbsent(\"prazo_resposta\", atual.prazoResposta());\n"
         "        campos.putIfAbsent(\"prazo_resolucao\", atual.prazoResolucao());\n"
         "        chamados.atualizar(tx, id, campos);\n        if (mudouPrioridade)"),
    ]),

    ("BUG-011.b", [
        (SRC + "repo/NotificacaoRepo.java",
         "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ? for update skip locked\",",
         "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ?\","),
    ]),

    ("BUG-014", [
        (SRC + "repo/OrdemRepo.java",
         "                + \"and not exists (select 1 from lancamento l where l.ordem_servico_id = o.id) order by o.id limit ?\", OrdemRepo::ordem, limite);",
         "                + \"and o.id > coalesce((select ultimo_id from job_checkpoint where nome = 'lancamento'), 0) order by o.id limit ?\", OrdemRepo::ordem, limite);"),
        (SRC + "job/LancamentoJob.java",
         "            doLote = db.tx(tx -> lancarLote(tx, agora));",
         "            doLote = lancarLote(agora);"),
        (SRC + "job/LancamentoJob.java",
         "    private int lancarLote(Tx tx, Instant agora) {\n"
         "        tx.list(\"select pg_advisory_xact_lock(?)\", rs -> 0, TRAVA);\n"
         "        List<Ordem> lote = ordens.semLancamento(tx, LOTE);\n"
         "        Map<Long, Empresa> empresas = new HashMap<>();\n"
         "        int lancados = 0;\n"
         "        for (Ordem o : lote) {\n"
         "            Empresa e = empresas.computeIfAbsent(o.empresaId(), id -> cadastros.empresa(tx, id).orElseThrow());\n",
         "    private int lancarLote(Instant agora) {\n"
         "        List<Ordem> lote = db.tx(tx -> {\n"
         "            tx.list(\"select pg_advisory_xact_lock(?)\", rs -> 0, TRAVA);\n"
         "            List<Ordem> proximas = ordens.semLancamento(tx, LOTE);\n"
         "            if (!proximas.isEmpty()) ordens.gravarPontoDeRetomada(tx, proximas.get(proximas.size() - 1).id());\n"
         "            return proximas;\n"
         "        });\n"
         "        return db.tx(tx -> lancar(tx, lote, agora));\n"
         "    }\n\n"
         "    private int lancar(Tx tx, List<Ordem> lote, Instant agora) {\n"
         "        Map<Long, Empresa> empresas = new HashMap<>();\n"
         "        int lancados = 0;\n"
         "        for (Ordem o : lote) {\n"
         "            Empresa e = empresas.computeIfAbsent(o.empresaId(), id -> cadastros.empresa(tx, id).orElseThrow());\n"),
        (SRC + "job/LancamentoJob.java",
         "        if (!lote.isEmpty()) ordens.gravarPontoDeRetomada(tx, lote.get(lote.size() - 1).id());\n        return lote.size();",
         "        return lote.size();"),
        (SRC + "repo/OrdemRepo.java",
         "                + \"on conflict (ordem_servico_id) do nothing\", o.empresaId(), o.id(), o.valorCents(), competencia, agora);",
         "                , o.empresaId(), o.id(), o.valorCents(), competencia, agora);"),
    ]),

    ("BUG-015", [
        (SRC + "db/Db.java",
         "            super(causa.getMessage(), causa);",
         "            super(causa.getMessage());"),
    ]),

    # schema-dependent code: the legacy schema has no unique index, so these statements cannot use ON CONFLICT
    ("SCHEMA-LEGACY", [
        (SRC + "repo/NotificacaoRepo.java",
         "                + \"values (?, ?, ?, ?, ?, 'PENDENTE', 0, ?) on conflict (chave_evento, destinatario) do nothing\",",
         "                + \"values (?, ?, ?, ?, ?, 'PENDENTE', 0, ?)\","),
        (SRC + "repo/IdempotenciaRepo.java",
         "        return tx.update(\"insert into chave_idempotencia (empresa_id, rota, chave, corpo_hash, criado_em) values (?, ?, ?, ?, ?) \"\n"
         "                + \"on conflict (empresa_id, rota, chave) do nothing\", empresaId, rota, chave, corpoHash, agora) == 1;",
         "        tx.list(\"select pg_advisory_xact_lock(hashtext(?))\", rs -> 0, empresaId + \"|\" + rota + \"|\" + chave);\n"
         "        if (buscar(tx, empresaId, rota, chave).isPresent()) return false;\n"
         "        return tx.update(\"insert into chave_idempotencia (empresa_id, rota, chave, corpo_hash, criado_em) values (?, ?, ?, ?, ?)\",\n"
         "                empresaId, rota, chave, corpoHash, agora) == 1;"),
    ]),
]
