package br.netx.chamados.repo;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.SlaPolitica;
import br.netx.chamados.dominio.Dominio.Tecnico;
import br.netx.chamados.dominio.Dominio.Usuario;

import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.LocalTime;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

/** Empresas, usuários, clientes, técnicos e políticas de SLA. */
public final class CadastroRepo {
    private static final String EMPRESA = "select id, nome, fuso_horario, expediente_inicio, expediente_fim, ativa from empresa";
    private static final String USUARIO = "select id, empresa_id, email, nome, papel, senha_hash, ativo from usuario";
    private static final String TECNICO = "select id, empresa_id, usuario_id, nome, ativo from tecnico";
    private static final Set<String> CAMPOS_EMPRESA = Set.of("nome", "fuso_horario", "expediente_inicio", "expediente_fim", "ativa");
    private static final Set<String> CAMPOS_USUARIO = Set.of("email", "nome", "papel", "ativo", "senha_hash");
    private static final Set<String> CAMPOS_CLIENTE = Set.of("nome", "documento", "email", "telefone", "endereco", "ativo");

    public static Empresa empresa(ResultSet rs) throws SQLException {
        return new Empresa(rs.getLong("id"), rs.getString("nome"), rs.getString("fuso_horario"),
                rs.getObject("expediente_inicio", LocalTime.class), rs.getObject("expediente_fim", LocalTime.class), rs.getBoolean("ativa"));
    }

    public static Usuario usuario(ResultSet rs) throws SQLException {
        return new Usuario(rs.getLong("id"), Db.longOuNulo(rs, "empresa_id"), rs.getString("email"), rs.getString("nome"),
                Papel.valueOf(rs.getString("papel")), rs.getString("senha_hash"), rs.getBoolean("ativo"));
    }

    public static Tecnico tecnico(ResultSet rs) throws SQLException {
        return new Tecnico(rs.getLong("id"), rs.getLong("empresa_id"), Db.longOuNulo(rs, "usuario_id"), rs.getString("nome"), rs.getBoolean("ativo"));
    }

    // ---- empresas

    public Optional<Empresa> empresa(Tx tx, long id) {
        return tx.one(EMPRESA + " where id = ?", CadastroRepo::empresa, id);
    }

    public List<Empresa> empresas(Tx tx, Long soEssa, int limite, int deslocamento) {
        return soEssa == null ? tx.list(EMPRESA + " order by id limit ? offset ?", CadastroRepo::empresa, limite, deslocamento)
                : tx.list(EMPRESA + " where id = ? order by id limit ? offset ?", CadastroRepo::empresa, soEssa, limite, deslocamento);
    }

    public long totalEmpresas(Tx tx, Long soEssa) {
        return soEssa == null ? tx.count("select count(*) from empresa") : tx.count("select count(*) from empresa where id = ?", soEssa);
    }

    public long inserirEmpresa(Tx tx, String nome, String fuso, LocalTime inicio, LocalTime fim) {
        return tx.insert("insert into empresa (nome, fuso_horario, expediente_inicio, expediente_fim) values (?, ?, ?, ?) returning id",
                nome, fuso, inicio, fim);
    }

    public int atualizarEmpresa(Tx tx, long id, Map<String, Object> campos) {
        return atualizar(tx, "empresa", "id = ?", id, campos, CAMPOS_EMPRESA);
    }

    // ---- usuários

    public Optional<Usuario> usuarioPorEmail(Tx tx, String email) {
        return tx.one(USUARIO + " where lower(email) = lower(?) and ativo", CadastroRepo::usuario, email);
    }

    public Optional<Usuario> usuario(Tx tx, long id, Long empresaId) {
        return empresaId == null ? tx.one(USUARIO + " where id = ?", CadastroRepo::usuario, id)
                : tx.one(USUARIO + " where id = ? and empresa_id = ?", CadastroRepo::usuario, id, empresaId);
    }

    public Pagina<Usuario> usuarios(Tx tx, Long empresaId, int pagina, int tamanho) {
        String filtro = empresaId == null ? "" : " where empresa_id = ?";
        long total = empresaId == null ? tx.count("select count(*) from usuario") : tx.count("select count(*) from usuario" + filtro, empresaId);
        List<Usuario> itens = empresaId == null
                ? tx.list(USUARIO + " order by id limit ? offset ?", CadastroRepo::usuario, tamanho, (pagina - 1) * tamanho)
                : tx.list(USUARIO + filtro + " order by id limit ? offset ?", CadastroRepo::usuario, empresaId, tamanho, (pagina - 1) * tamanho);
        return new Pagina<>(itens, pagina, tamanho, total);
    }

    public long inserirUsuario(Tx tx, Long empresaId, String email, String nome, Papel papel, String senhaHash) {
        return tx.insert("insert into usuario (empresa_id, email, nome, papel, senha_hash) values (?, ?, ?, ?, ?) returning id",
                empresaId, email, nome, papel, senhaHash);
    }

    public int atualizarUsuario(Tx tx, long id, Long empresaId, Map<String, Object> campos) {
        return empresaId == null ? atualizar(tx, "usuario", "id = ?", id, campos, CAMPOS_USUARIO)
                : atualizar(tx, "usuario", "id = ? and empresa_id = " + empresaId, id, campos, CAMPOS_USUARIO);
    }

    public void atualizarSenha(Tx tx, long id, String hash) {
        tx.update("update usuario set senha_hash = ? where id = ?", hash, id);
    }

    // ---- clientes

    public Pagina<Map<String, Object>> clientes(Tx tx, long empresaId, int pagina, int tamanho) {
        long total = tx.count("select count(*) from cliente where empresa_id = ?", empresaId);
        List<Map<String, Object>> itens = tx.list("select id, empresa_id, nome, documento, email, telefone, endereco, ativo from cliente "
                + "where empresa_id = ? order by id limit ? offset ?", CadastroRepo::cliente, empresaId, tamanho, (pagina - 1) * tamanho);
        return new Pagina<>(itens, pagina, tamanho, total);
    }

    public Optional<Map<String, Object>> cliente(Tx tx, long id, long empresaId) {
        return tx.one("select id, empresa_id, nome, documento, email, telefone, endereco, ativo from cliente where id = ? and empresa_id = ?",
                CadastroRepo::cliente, id, empresaId);
    }

    public long inserirCliente(Tx tx, long empresaId, Map<String, Object> c) {
        return tx.insert("insert into cliente (empresa_id, nome, documento, email, telefone, endereco) values (?, ?, ?, ?, ?, ?) returning id",
                empresaId, c.get("nome"), c.get("documento"), c.get("email"), c.get("telefone"), c.get("endereco"));
    }

    public int atualizarCliente(Tx tx, long id, long empresaId, Map<String, Object> campos) {
        return atualizar(tx, "cliente", "id = ? and empresa_id = " + empresaId, id, campos, CAMPOS_CLIENTE);
    }

    private static Map<String, Object> cliente(ResultSet rs) throws SQLException {
        Map<String, Object> m = new java.util.LinkedHashMap<>();
        for (String col : List.of("id", "empresa_id", "nome", "documento", "email", "telefone", "endereco", "ativo")) m.put(col, rs.getObject(col));
        return m;
    }

    // ---- técnicos

    public Pagina<Tecnico> tecnicos(Tx tx, long empresaId, int pagina, int tamanho) {
        long total = tx.count("select count(*) from tecnico where empresa_id = ?", empresaId);
        List<Tecnico> itens = tx.list(TECNICO + " where empresa_id = ? order by id limit ? offset ?", CadastroRepo::tecnico, empresaId, tamanho, (pagina - 1) * tamanho);
        return new Pagina<>(itens, pagina, tamanho, total);
    }

    public Optional<Tecnico> tecnico(Tx tx, long id, long empresaId) {
        return tx.one(TECNICO + " where id = ? and empresa_id = ?", CadastroRepo::tecnico, id, empresaId);
    }

    public Optional<Tecnico> tecnicoDoUsuario(Tx tx, long usuarioId) {
        return tx.one(TECNICO + " where usuario_id = ? and ativo", CadastroRepo::tecnico, usuarioId);
    }

    public long inserirTecnico(Tx tx, long empresaId, Long usuarioId, String nome) {
        return tx.insert("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, ?) returning id", empresaId, usuarioId, nome);
    }

    public int atualizarTecnico(Tx tx, long id, long empresaId, Map<String, Object> campos) {
        return atualizar(tx, "tecnico", "id = ? and empresa_id = " + empresaId, id, campos, Set.of("nome", "usuario_id", "ativo"));
    }

    // ---- políticas de SLA

    public List<SlaPolitica> politicas(Tx tx, long empresaId) {
        return tx.list("select prioridade, horas_resposta, horas_resolucao, somente_expediente from sla_politica where empresa_id = ?",
                rs -> new SlaPolitica(Prioridade.valueOf(rs.getString("prioridade")), rs.getInt("horas_resposta"), rs.getInt("horas_resolucao"),
                        rs.getBoolean("somente_expediente")), empresaId);
    }

    // ---- atualização parcial: só as colunas recebidas, de uma lista fechada

    private static int atualizar(Tx tx, String tabela, String onde, long id, Map<String, Object> campos, Set<String> permitidos) {
        List<String> sets = new ArrayList<>();
        List<Object> valores = new ArrayList<>();
        for (Map.Entry<String, Object> e : campos.entrySet()) {
            if (!permitidos.contains(e.getKey())) throw new IllegalArgumentException("coluna não permitida: " + e.getKey());
            sets.add(e.getKey() + " = ?");
            valores.add(e.getValue());
        }
        if (sets.isEmpty()) return 0;
        valores.add(id);
        return tx.update("update " + tabela + " set " + String.join(", ", sets) + " where " + onde, valores.toArray());
    }

    public boolean emailExiste(Tx tx, String email) {
        return tx.count("select count(*) from usuario where lower(email) = lower(?)", email) > 0;
    }

    /** O cliente tem chamado atribuído a este usuário técnico. */
    public boolean clienteDoTecnico(Tx tx, long clienteId, long usuarioId) {
        return tx.count("select count(*) from chamado c join tecnico t on t.id = c.tecnico_id where c.cliente_id = ? and t.usuario_id = ?", clienteId, usuarioId) > 0;
    }
}
