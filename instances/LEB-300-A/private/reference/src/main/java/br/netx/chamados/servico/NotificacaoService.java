package br.netx.chamados.servico;

import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.repo.NotificacaoRepo;
import br.netx.chamados.repo.NotificacaoRepo.Registro;

import java.time.Clock;
import java.util.ArrayList;
import java.util.Collection;
import java.util.LinkedHashSet;
import java.util.List;

/** Quem recebe o aviso de cada evento. As notificações nascem na mesma transação do evento. */
public final class NotificacaoService {
    private final NotificacaoRepo repo;
    private final Clock clock;

    public NotificacaoService(NotificacaoRepo repo, Clock clock) {
        this.repo = repo;
        this.clock = clock;
    }

    public void criar(Tx tx, long empresaId, String tipo, long chamadoId, long eventoId, Collection<String> destinatarios, String assunto, String corpo) {
        String chave = tipo + ":" + chamadoId + ":" + eventoId;
        for (String destinatario : new LinkedHashSet<>(destinatarios)) {
            repo.inserir(tx, empresaId, chave, destinatario, assunto, corpo, clock.instant());
        }
    }

    public Pagina<Registro> listarNotificacoes(Tx tx, long empresaId, String estado, int pagina, int tamanho) {
        return repo.listar(tx, empresaId, estado, pagina, tamanho);
    }

    public List<String> atendentesEAdmins(Tx tx, long empresaId) {
        return repo.emailsAtendentesEAdmins(tx, empresaId);
    }

    public List<String> doTecnico(Tx tx, Long tecnicoId) {
        return tecnicoId == null ? List.of() : repo.emailDoTecnico(tx, tecnicoId).map(List::of).orElse(List.of());
    }

    public List<String> doUsuario(Tx tx, long usuarioId) {
        return repo.emailDoUsuario(tx, usuarioId).map(List::of).orElse(List.of());
    }

    /** Atendentes, administradores e o técnico atribuído, sem repetir ninguém. */
    public List<String> equipeDoChamado(Tx tx, long empresaId, Long tecnicoId) {
        List<String> todos = new ArrayList<>(atendentesEAdmins(tx, empresaId));
        todos.addAll(doTecnico(tx, tecnicoId));
        return new ArrayList<>(new LinkedHashSet<>(todos));
    }
}
