package br.netx.chamados.relatorio;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.servico.ChamadoService;

import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneId;
import java.time.ZoneOffset;
import java.time.format.DateTimeFormatter;

/** Exportação em CSV: delimitador ";", UTF-8, fim de linha "\n", datas no fuso da empresa. */
public final class CsvExportador {
    static final String CABECALHO = "ID;Titulo;Cliente;Prioridade;Status;Aberto em;Prazo resolucao;Resolvido em;Tecnico;SLA cumprido";
    private static final DateTimeFormatter DATA = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm");

    private final ChamadoService sla;

    public CsvExportador(ChamadoService sla) {
        this.sla = sla;
    }

    public String gerar(Tx tx, long empresaId, Instant de, Instant ate, ZoneId zona) {
        StringBuilder csv = new StringBuilder(CABECALHO).append('\n');
        try (PreparedStatement ps = tx.connection().prepareStatement(
                "select c.id, c.titulo, cl.nome as cliente_nome, c.prioridade, c.status, c.aberto_em, c.prazo_resolucao, c.resolvido_em, t.nome as tecnico_nome "
                        + "from chamado c join cliente cl on cl.id = c.cliente_id left join tecnico t on t.id = c.tecnico_id "
                        + "where c.empresa_id = ? and c.aberto_em >= ? and c.aberto_em < ? order by c.aberto_em, c.id")) {
            ps.setLong(1, empresaId);
            ps.setObject(2, OffsetDateTime.ofInstant(de, ZoneOffset.UTC));
            ps.setObject(3, OffsetDateTime.ofInstant(ate, ZoneOffset.UTC));
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    Instant prazo = Db.instante(rs, "prazo_resolucao");
                    Instant resolvido = Db.instante(rs, "resolvido_em");
                    String cumprido = resolvido == null ? "" : sla.cumprido(resolvido, prazo) ? "SIM" : "NAO";
                    csv.append(String.join(";",
                            String.valueOf(rs.getLong("id")), texto(rs.getString("titulo")), texto(rs.getString("cliente_nome")),
                            rs.getString("prioridade"), rs.getString("status"), data(Db.instante(rs, "aberto_em"), zona), data(prazo, zona),
                            data(resolvido, zona), texto(rs.getString("tecnico_nome")), cumprido)).append('\n');
                }
            }
        } catch (SQLException e) {
            throw new Db.DbException(e);
        }
        return csv.toString();
    }

    private static String data(Instant t, ZoneId zona) {
        return t == null ? "" : DATA.format(t.atZone(zona));
    }

    /** Texto livre: protege o delimitador, as aspas e a quebra de linha. */
    static String texto(String valor) {
        if (valor == null) return "";
        String v = valor;
        if (v.contains(";") || v.contains("\"") || v.contains("\n") || v.contains("\r")) v = "\"" + v.replace("\"", "\"\"") + "\"";
        return v;
    }
}
