package br.netx.chamados.http;

import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.http.Servidor.Req;
import br.netx.chamados.http.Servidor.Resp;
import br.netx.chamados.relatorio.RelatorioService;

import java.util.List;
import java.util.Map;

/** Relatórios gerenciais e a exportação em CSV. */
public final class RelatorioHandlers {
    private final RelatorioService relatorios;

    public RelatorioHandlers(RelatorioService relatorios) {
        this.relatorios = relatorios;
    }

    public void registrar(Servidor s) {
        s.rota("GET", "/relatorios/sla", false, r -> {
            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);
            return Resp.json(200, relatorios.sla(p, r.empresaIdPedida(), r.consulta("mes")));
        });
        s.rota("GET", "/relatorios/status", false, r -> {
            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);
            return Resp.json(200, relatorios.status(p, r.empresaIdPedida()));
        });
        s.rota("GET", "/relatorios/tecnicos", false, r -> {
            Principal p = quem(r, Papel.ADMIN);
            List<Map<String, Object>> linhas = relatorios.tecnicos(p, r.empresaIdPedida(), r.consulta("mes"));
            return Resp.json(200, Map.of("itens", linhas));
        });
        s.rota("GET", "/relatorios/exportacao.csv", false, r -> {
            Principal p = quem(r, Papel.ADMIN);
            return new Resp(200, "text/csv; charset=utf-8", relatorios.exportacao(p, r.empresaIdPedida(), r.consulta("mes")));
        });
    }

    /** ADMIN e ATENDENTE da própria empresa, ou PLATAFORMA (leitura, com empresa_id). */
    private static Principal quem(Req r, Papel... empresa) {
        Principal p = r.principal();
        if (p.plataforma()) return p;
        p.exigir(empresa);
        return p;
    }
}
