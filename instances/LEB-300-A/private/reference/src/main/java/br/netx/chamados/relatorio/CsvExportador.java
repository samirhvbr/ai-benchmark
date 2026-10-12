package br.netx.chamados.relatorio;

import br.netx.chamados.repo.ChamadoRepo.LinhaExportacao;
import br.netx.chamados.servico.SlaService;

import java.time.Instant;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.List;

/** Exportação em CSV: delimitador ";", UTF-8, fim de linha "\n", datas no fuso da empresa. */
public final class CsvExportador {
    static final String CABECALHO = "ID;Titulo;Cliente;Prioridade;Status;Aberto em;Prazo resolucao;Resolvido em;Tecnico;SLA cumprido";
    private static final DateTimeFormatter DATA = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm");

    private final SlaService sla;

    public CsvExportador(SlaService sla) {
        this.sla = sla;
    }

    public String gerar(List<LinhaExportacao> linhas, ZoneId zona) {
        StringBuilder csv = new StringBuilder(CABECALHO).append('\n');
        for (LinhaExportacao l : linhas) {
            String cumprido = l.resolvidoEm() == null ? "" : sla.cumprido(l.resolvidoEm(), l.prazoResolucao()) ? "SIM" : "NAO";
            csv.append(String.join(";",
                    String.valueOf(l.id()), texto(l.titulo()), texto(l.clienteNome()), l.prioridade().name(), l.status().name(),
                    data(l.abertoEm(), zona), data(l.prazoResolucao(), zona), data(l.resolvidoEm(), zona), texto(l.tecnicoNome()), cumprido)).append('\n');
        }
        return csv.toString();
    }

    private static String data(Instant t, ZoneId zona) {
        return t == null ? "" : DATA.format(t.atZone(zona));
    }

    /** Texto livre: neutraliza fórmula de planilha e protege o delimitador, as aspas e a quebra de linha. */
    static String texto(String valor) {
        if (valor == null) return "";
        String v = valor;
        if (!v.isEmpty() && "=+-@\t\r".indexOf(v.charAt(0)) >= 0) v = "'" + v;
        if (v.contains(";") || v.contains("\"") || v.contains("\n") || v.contains("\r")) v = "\"" + v.replace("\"", "\"\"") + "\"";
        return v;
    }
}
