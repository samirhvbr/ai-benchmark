package br.netx.chamados.http;

import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.http.Servidor.Req;
import br.netx.chamados.http.Servidor.Resp;
import br.netx.chamados.relatorio.RelatorioService;

import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Relatórios gerenciais e a exportação em CSV. */
public final class RelatorioHandlers {
    private final RelatorioService relatorios;
    private final Db db;

    public RelatorioHandlers(RelatorioService relatorios, Db db) {
        this.relatorios = relatorios;
        this.db = db;
    }

    public void registrar(Servidor s) {
        s.rota("GET", "/relatorios/sla", false, r -> {
            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);
            return Resp.json(200, relatorios.sla(p, r.empresaIdPedida(), r.consulta("mes")));
        });
        s.rota("GET", "/relatorios/status", false, r -> {
            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);
            Long empresa = p.plataforma() ? r.empresaIdPedida() : p.empresaId();
            if (empresa == null) throw Erro.invalido("empresa_id é obrigatório");
            Map<String, Object> contagem = db.tx(tx -> {
                Map<String, Object> m = new LinkedHashMap<>();
                for (StatusChamado st : StatusChamado.values()) m.put(st.name(), 0L);
                try (PreparedStatement ps = tx.connection().prepareStatement("select status, count(*) from chamado where empresa_id = ? group by status")) {
                    ps.setLong(1, empresa);
                    try (ResultSet rs = ps.executeQuery()) {
                        while (rs.next()) m.put(rs.getString(1), rs.getLong(2));
                    }
                } catch (SQLException e) {
                    throw new Db.DbException(e);
                }
                return m;
            });
            return Resp.json(200, contagem);
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
