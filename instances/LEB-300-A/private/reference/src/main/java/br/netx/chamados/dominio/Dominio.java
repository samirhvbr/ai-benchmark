package br.netx.chamados.dominio;

import java.time.Instant;
import java.time.LocalTime;
import java.time.ZoneId;

/** Tipos do domínio: papéis, prioridades, estados, registros e o erro de negócio. */
public final class Dominio {
    private Dominio() {}

    public enum Papel { PLATAFORMA, ADMIN, ATENDENTE, TECNICO }

    public enum Prioridade {
        BAIXA, NORMAL, ALTA, CRITICA;

        public Prioridade acima() {
            return this == CRITICA ? this : values()[ordinal() + 1];
        }
    }

    public enum StatusChamado {
        ABERTO, EM_ATENDIMENTO, AGUARDANDO_CLIENTE, RESOLVIDO, FECHADO;

        public boolean pode(StatusChamado para) {
            return switch (this) {
                case ABERTO -> para == EM_ATENDIMENTO;
                case EM_ATENDIMENTO -> para == AGUARDANDO_CLIENTE || para == RESOLVIDO;
                case AGUARDANDO_CLIENTE -> para == EM_ATENDIMENTO;
                case RESOLVIDO -> para == FECHADO || para == EM_ATENDIMENTO;
                case FECHADO -> false;
            };
        }

        public boolean emAndamento() {
            return this == ABERTO || this == EM_ATENDIMENTO || this == AGUARDANDO_CLIENTE;
        }

        public boolean resolvido() {
            return this == RESOLVIDO || this == FECHADO;
        }
    }

    public enum StatusOrdem { AGENDADA, EM_EXECUCAO, CONCLUIDA, CANCELADA }

    public record Principal(long usuarioId, Long empresaId, Papel papel, String nome) {
        public boolean plataforma() {
            return papel == Papel.PLATAFORMA;
        }

        public void exigir(Papel... aceitos) {
            for (Papel p : aceitos) {
                if (p == papel) return;
            }
            throw Erro.proibido();
        }
    }

    public record Empresa(long id, String nome, String fusoHorario, LocalTime expedienteInicio, LocalTime expedienteFim, boolean ativa) {
        public ZoneId zona() {
            return ZoneId.of(fusoHorario);
        }
    }

    public record Pagina<T>(java.util.List<T> itens, int pagina, int tamanho, long total) {}

    public record Usuario(long id, Long empresaId, String email, String nome, Papel papel, String senhaHash, boolean ativo) {}

    public record Tecnico(long id, long empresaId, Long usuarioId, String nome, boolean ativo) {}

    public record SlaPolitica(Prioridade prioridade, int horasResposta, int horasResolucao, boolean somenteExpediente) {}

    public record Chamado(long id, long empresaId, long clienteId, Long tecnicoId, String titulo, String descricao, Prioridade prioridade,
                          StatusChamado status, Instant abertoEm, Instant prazoResposta, Instant prazoResolucao,
                          Instant primeiraRespostaEm, Instant resolvidoEm, long criadoPor) {}

    public record Ordem(long id, long empresaId, long chamadoId, long tecnicoId, String tipo, StatusOrdem status, Instant agendadaPara,
                        Instant iniciadaEm, Instant concluidaEm, Long valorCents, String observacoes) {}

    public record Evento(long id, long chamadoId, String tipo, Long usuarioId, String detalhe, Instant ocorridoEm) {}

    /** Erro que o cliente vê: status HTTP, código curto e mensagem. */
    public static final class Erro extends RuntimeException {
        public final int status;
        public final String codigo;

        public Erro(int status, String codigo, String mensagem) {
            super(mensagem);
            this.status = status;
            this.codigo = codigo;
        }

        public static Erro malformado(String mensagem) { return new Erro(400, "pedido_malformado", mensagem); }
        public static Erro naoAutenticado() { return new Erro(401, "nao_autenticado", "autenticação necessária"); }
        public static Erro proibido() { return new Erro(403, "proibido", "papel sem permissão para esta operação"); }
        public static Erro naoEncontrado() { return new Erro(404, "nao_encontrado", "recurso não encontrado"); }
        public static Erro conflito(String mensagem) { return new Erro(409, "conflito", mensagem); }
        public static Erro invalido(String mensagem) { return new Erro(422, "validacao", mensagem); }
    }
}
