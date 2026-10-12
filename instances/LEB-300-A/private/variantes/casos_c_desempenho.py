"""Control variants for the performance flaws."""
import re
from motor import Caso

REPO = "repo/ChamadoRepo.java"
ORD = "repo/OrdemRepo.java"
REL = "relatorio/RelatorioService.java"
ESC = "job/EscalonamentoJob.java"
DESP = "job/DespachoJob.java"
CS = "servico/ChamadoService.java"

LISTA_N1 = '''        List<Chamado> basicos = tx.list("select " + COLUNAS + " from chamado c left join tecnico t on t.id = c.tecnico_id" + onde
                + " order by " + ordenacaoPorPrioridade() + ", c.prazo_resolucao, c.id limit ? offset ?", ChamadoRepo::chamado, args.toArray());
        List<Visao> itens = new ArrayList<>();
        for (Chamado c : basicos) {
            String cliente = tx.one("select nome from cliente where id = ?", rs -> rs.getString(1), c.clienteId()).orElse(null);
            String tecnico = c.tecnicoId() == null ? null : tx.one("select nome from tecnico where id = ?", rs -> rs.getString(1), c.tecnicoId()).orElse(null);
            itens.add(new Visao(c, cliente, tecnico));
        }
'''
LISTA_JOIN = '''        List<Visao> itens = tx.list(SELECAO + onde + " order by " + ordenacaoPorPrioridade() + ", c.prazo_resolucao, c.id limit ? offset ?",
                ChamadoRepo::visao, args.toArray());
'''
LISTA_CACHE = LISTA_N1.replace("        List<Visao> itens = new ArrayList<>();\n", "        List<Visao> itens = new ArrayList<>();\n        java.util.Map<Long, String> nomesCliente = new java.util.HashMap<>();\n        java.util.Map<Long, String> nomesTecnico = new java.util.HashMap<>();\n") \
    .replace('String cliente = tx.one("select nome from cliente where id = ?", rs -> rs.getString(1), c.clienteId()).orElse(null);',
             'String cliente = nomesCliente.computeIfAbsent(c.clienteId(), k -> tx.one("select nome from cliente where id = ?", rs -> rs.getString(1), k).orElse(null));') \
    .replace('tx.one("select nome from tecnico where id = ?", rs -> rs.getString(1), c.tecnicoId()).orElse(null);',
             'nomesTecnico.computeIfAbsent(c.tecnicoId(), k -> tx.one("select nome from tecnico where id = ?", rs -> rs.getString(1), k).orElse(null));')

PAG_N1 = '''        List<Ordem> todas = tx.list("select " + COLUNAS + de + " order by o.agendada_para, o.id", OrdemRepo::ordem, args.toArray());
        int inicio = Math.min((pagina - 1) * tamanho, todas.size());
        List<Ordem> itens = new ArrayList<>(todas.subList(inicio, Math.min(inicio + tamanho, todas.size())));
        return new Pagina<>(itens, pagina, tamanho, todas.size());'''
PAG_OK = '''        long total = tx.count("select count(*)" + de, args.toArray());
        args.add(tamanho);
        args.add((pagina - 1) * tamanho);
        List<Ordem> itens = tx.list("select " + COLUNAS + de + " order by o.agendada_para, o.id limit ? offset ?", OrdemRepo::ordem, args.toArray());
        return new Pagina<>(itens, pagina, tamanho, total);'''
PAG_MIL = PAG_N1.replace('" order by o.agendada_para, o.id"', '" order by o.agendada_para, o.id limit 1000"')

TEC_N1 = '''            List<Produtividade> todos = new ArrayList<>();
            for (long[] tecnico : ordens.tecnicosAtivos(tx, empresaId)) todos.add(ordens.produtividadeDo(tx, tecnico[0], periodo[0], periodo[1]));
            todos.sort(Comparator.comparingLong(Produtividade::concluidas).reversed().thenComparing(Produtividade::nome));
            for (Produtividade t : todos) {'''
TEC_OK = '''            for (Produtividade t : ordens.produtividade(tx, empresaId, periodo[0], periodo[1])) {'''

INDICES_BOM = '''create index ix_chamado_empresa_status on chamado (empresa_id, status, prioridade);
create index ix_ordem_chamado on ordem_servico (chamado_id);
'''
INDICES_COMPOSTOS = '''create index ix_chamado_empresa_aberto on chamado (empresa_id, aberto_em);
create index ix_ordem_chamado_status on ordem_servico (chamado_id, status);
'''
INDICES_SEM_LIDER = '''create index ix_chamado_status_empresa on chamado (status, empresa_id);
create index ix_ordem_status_chamado on ordem_servico (status, chamado_id);
'''
INDICES_ERRADOS = '''create index ix_chamado_status on chamado (status);
create index ix_ordem_status on ordem_servico (status);
create index ix_evento_chamado on evento_chamado (chamado_id);
'''

CASOS = [
    Caso("PERF-001", "perf001-join", "alternativa", "corrigida", car=True, edits=[(REPO, LISTA_N1, LISTA_JOIN)]),
    Caso("PERF-001", "perf001-cache-por-requisicao", "mutante", "nao_corrigida", edits=[(REPO, LISTA_N1, LISTA_CACHE)]),
    Caso("PERF-003", "perf003-limit-offset", "alternativa", "corrigida", car=True, edits=[(ORD, PAG_N1, PAG_OK)]),
    Caso("PERF-003", "perf003-teto-de-mil", "mutante", "nao_corrigida", edits=[(ORD, PAG_N1, PAG_MIL)]),
    Caso("PERF-005", "perf005-uma-consulta", "alternativa", "corrigida", car=True, edits=[(REL, TEC_N1, TEC_OK)]),
    Caso("PERF-005", "perf005-teto-de-dez-tecnicos", "mutante", "nao_corrigida", edits=[
        (ORD, 'select id from tecnico where empresa_id = ? and ativo order by id"', 'select id from tecnico where empresa_id = ? and ativo order by id limit 10"')]),
    Caso("PERF-006", "perf006-indices-liderados", "alternativa", "corrigida", car=True, novos={"migrations/002_indices.sql": INDICES_BOM}),
    Caso("PERF-006", "perf006-indices-compostos", "alternativa", "corrigida", car=True, novos={"migrations/002_indices.sql": INDICES_COMPOSTOS}),
    Caso("PERF-006", "perf006-sem-coluna-lider", "mutante", "nao_corrigida", novos={"migrations/002_indices.sql": INDICES_SEM_LIDER}),
    Caso("PERF-006", "perf006-indices-em-outras-colunas", "mutante", "nao_corrigida", novos={"migrations/002_indices.sql": INDICES_ERRADOS}),
    Caso("PERF-011", "perf011-projecao", "alternativa", "corrigida", car=True, edits=[(ESC,
        '        List<Chamado> candidatos = db.tx(chamados::emAndamento);\n        List<Long> devidos = new ArrayList<>();\n        for (Chamado c : candidatos) {',
        '        List<br.netx.chamados.repo.ChamadoRepo.Candidato> candidatos = db.tx(chamados::candidatosAoEscalonamento);\n        List<Long> devidos = new ArrayList<>();\n        for (br.netx.chamados.repo.ChamadoRepo.Candidato c : candidatos) {')]),
    Caso("PERF-012", "perf012-conjunto", "alternativa", "corrigida", car=True, de_referencia=[DESP]),
    Caso("PERF-012", "perf012-stream-sobre-lista", "mutante", "nao_corrigida", edits=[(DESP, "if (!chaves.contains(chave)) {", "if (chaves.stream().noneMatch(chave::equals)) {")]),
    Caso("PERF-013", "perf013-sem-releitura", "alternativa", "corrigida", car=True, edits=[(CS, "        c = chamados.travar(tx, c.id(), c.empresaId()).orElseThrow();\n", "")]),
    Caso("PERF-013", "perf013-releitura-por-outro-metodo", "mutante", "nao_corrigida", edits=[(CS, "c = chamados.travar(tx, c.id(), c.empresaId()).orElseThrow();", "c = chamados.porId(tx, c.id(), c.empresaId()).orElseThrow();")]),
]
