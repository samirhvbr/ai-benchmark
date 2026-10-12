package br.netx.chamados.servico;

import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.SlaPolitica;

import java.time.DayOfWeek;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.util.EnumMap;
import java.util.List;
import java.util.Map;

/** Prazos de SLA: horas corridas ou só de expediente, no fuso da empresa. */
public final class SlaService {
    /** Fração do prazo de resolução a partir da qual o chamado sobe uma prioridade. */
    public static final int LIMIAR_NUMERADOR = 8;
    public static final int LIMIAR_DENOMINADOR = 10;

    /** Padrões do manifesto, seção 3.4, para a prioridade sem linha em sla_politica. */
    private static final Map<Prioridade, SlaPolitica> PADROES = new EnumMap<>(Prioridade.class);

    static {
        PADROES.put(Prioridade.BAIXA, new SlaPolitica(Prioridade.BAIXA, 24, 120, true));
        PADROES.put(Prioridade.NORMAL, new SlaPolitica(Prioridade.NORMAL, 8, 48, true));
        PADROES.put(Prioridade.ALTA, new SlaPolitica(Prioridade.ALTA, 4, 24, false));
        PADROES.put(Prioridade.CRITICA, new SlaPolitica(Prioridade.CRITICA, 1, 8, false));
    }

    public record Prazos(Instant resposta, Instant resolucao) {}

    public SlaPolitica politica(List<SlaPolitica> daEmpresa, Prioridade prioridade) {
        return daEmpresa.stream().filter(p -> p.prioridade() == prioridade).findFirst().orElse(PADROES.get(prioridade));
    }

    public Prazos prazos(Empresa empresa, List<SlaPolitica> daEmpresa, Prioridade prioridade, Instant abertoEm) {
        SlaPolitica p = politica(daEmpresa, prioridade);
        return new Prazos(somarHoras(abertoEm, p.horasResposta(), p.somenteExpediente(), empresa),
                somarHoras(abertoEm, p.horasResolucao(), p.somenteExpediente(), empresa));
    }

    /** Soma horas a partir de um instante. Com {@code somenteExpediente}, só contam seg a sex em [inicio, fim) no fuso da empresa. */
    static Instant somarHoras(Instant inicio, int horas, boolean somenteExpediente, Empresa empresa) {
        if (!somenteExpediente) return inicio.plus(Duration.ofHours(horas));
        ZoneId zona = empresa.zona();
        long restante = horas * 3600L;
        ZonedDateTime t = inicio.atZone(zona);
        while (true) {
            DayOfWeek dia = t.getDayOfWeek();
            ZonedDateTime abre = ZonedDateTime.of(t.toLocalDate(), empresa.expedienteInicio(), zona);
            ZonedDateTime fecha = ZonedDateTime.of(t.toLocalDate(), empresa.expedienteFim(), zona);
            if (dia == DayOfWeek.SATURDAY || dia == DayOfWeek.SUNDAY || !t.isBefore(fecha)) {
                t = ZonedDateTime.of(t.toLocalDate().plusDays(1), empresa.expedienteInicio(), zona);
                continue;
            }
            if (t.isBefore(abre)) t = abre;
            long disponivel = Duration.between(t, fecha).getSeconds();
            if (restante <= disponivel) return t.plusSeconds(restante).toInstant();
            restante -= disponivel;
            t = ZonedDateTime.of(t.toLocalDate().plusDays(1), empresa.expedienteInicio(), zona);
        }
    }

    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */
    public Instant limiteDeEscalonamento(Instant abertoEm, Instant prazoResolucao) {
        Duration total = Duration.between(abertoEm, prazoResolucao);
        return abertoEm.plus(total.multipliedBy(LIMIAR_NUMERADOR).dividedBy(LIMIAR_DENOMINADOR));
    }

    public boolean cumprido(Chamado c) {
        return cumprido(c.resolvidoEm(), c.prazoResolucao());
    }

    /** Cumprido quando resolvido até o prazo, inclusive. */
    public boolean cumprido(Instant resolvidoEm, Instant prazoResolucao) {
        return resolvidoEm != null && !resolvidoEm.isAfter(prazoResolucao);
    }
}
