package br.netx.chamados.http;

import br.netx.chamados.auth.Auth;
import br.netx.chamados.auth.Auth.Sessao;
import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Tecnico;
import br.netx.chamados.dominio.Dominio.Usuario;
import br.netx.chamados.http.Servidor.Req;
import br.netx.chamados.http.Servidor.Resp;
import br.netx.chamados.repo.CadastroRepo;
import com.fasterxml.jackson.databind.JsonNode;

import java.time.DateTimeException;
import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.LinkedHashMap;
import java.util.Map;

/** Login e logout, empresas, usuários, clientes e técnicos. */
public final class CadastroHandlers {
    private static final DateTimeFormatter HORA = DateTimeFormatter.ofPattern("HH:mm");
    private static final int SENHA_MINIMA = 8;

    private final Db db;
    private final Auth auth;
    private final CadastroRepo repo;

    public CadastroHandlers(Db db, Auth auth, CadastroRepo repo) {
        this.db = db;
        this.auth = auth;
        this.repo = repo;
    }

    public void registrar(Servidor s) {
        s.rota("POST", "/auth/login", true, this::login);
        s.rota("POST", "/auth/logout", false, r -> {
            auth.logout(r.token());
            return Resp.vazio(204);
        });
        s.rota("GET", "/empresas", false, this::listarEmpresas);
        s.rota("GET", "/empresas/{id}", false, this::detalharEmpresa);
        s.rota("POST", "/empresas", false, this::criarEmpresa);
        s.rota("PUT", "/empresas/{id}", false, this::alterarEmpresa);
        s.rota("GET", "/usuarios", false, this::listarUsuarios);
        s.rota("POST", "/usuarios", false, this::criarUsuario);
        s.rota("GET", "/usuarios/{id}", false, this::detalharUsuario);
        s.rota("PATCH", "/usuarios/{id}", false, this::alterarUsuario);
        s.rota("GET", "/clientes", false, this::listarClientes);
        s.rota("POST", "/clientes", false, this::criarCliente);
        s.rota("GET", "/clientes/{id}", false, this::detalharCliente);
        s.rota("PUT", "/clientes/{id}", false, this::alterarCliente);
        s.rota("GET", "/tecnicos", false, this::listarTecnicos);
        s.rota("GET", "/tecnicos/{id}", false, this::detalharTecnico);
        s.rota("POST", "/tecnicos", false, this::criarTecnico);
        s.rota("PUT", "/tecnicos/{id}", false, this::alterarTecnico);
    }

    private Resp login(Req r) {
        Sessao sessao = auth.login(r.texto("email"), r.texto("senha"));
        Map<String, Object> usuario = new LinkedHashMap<>();
        usuario.put("id", sessao.usuario().id());
        usuario.put("nome", sessao.usuario().nome());
        usuario.put("papel", sessao.usuario().papel().name());
        usuario.put("empresa_id", sessao.usuario().empresaId());
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("token", sessao.token());
        m.put("expira_em", Servidor.iso(sessao.expiraEm()));
        m.put("usuario", usuario);
        return Resp.json(200, m);
    }

    // ---- empresas

    private Resp listarEmpresas(Req r) {
        Principal p = r.principal();
        Long so = p.plataforma() ? null : p.empresaId();
        Pagina<Empresa> pagina = db.tx(tx -> new Pagina<>(repo.empresas(tx, so, r.tamanho(), (r.pagina() - 1) * r.tamanho()), r.pagina(), r.tamanho(), repo.totalEmpresas(tx, so)));
        return Resp.json(200, ChamadoHandlers.pagina(pagina, CadastroHandlers::empresaJson));
    }

    private Resp detalharEmpresa(Req r) {
        Principal p = r.principal();
        long id = r.id();
        if (!p.plataforma() && !java.util.Objects.equals(p.empresaId(), id)) throw Erro.naoEncontrado();
        return Resp.json(200, empresaJson(db.tx(tx -> repo.empresa(tx, id)).orElseThrow(Erro::naoEncontrado)));
    }

    private Resp criarEmpresa(Req r) {
        r.principal().exigir(Papel.PLATAFORMA);
        String nome = r.texto("nome");
        String fuso = fuso(r.texto("fuso_horario"));
        LocalTime inicio = hora(r.texto("expediente_inicio"));
        LocalTime fim = hora(r.texto("expediente_fim"));
        if (!inicio.isBefore(fim)) throw Erro.invalido("expediente_inicio deve ser anterior a expediente_fim");
        return Resp.json(201, empresaJson(db.tx(tx -> repo.empresa(tx, repo.inserirEmpresa(tx, nome, fuso, inicio, fim))).orElseThrow()));
    }

    private Resp alterarEmpresa(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN, Papel.PLATAFORMA);
        long id = r.id();
        if (!p.plataforma() && !java.util.Objects.equals(p.empresaId(), id)) throw Erro.naoEncontrado();
        JsonNode c = r.objeto();
        Map<String, Object> campos = new LinkedHashMap<>();
        if (c.has("nome")) campos.put("nome", r.texto("nome"));
        if (c.has("fuso_horario")) campos.put("fuso_horario", fuso(r.texto("fuso_horario")));
        if (c.has("expediente_inicio")) campos.put("expediente_inicio", hora(r.texto("expediente_inicio")));
        if (c.has("expediente_fim")) campos.put("expediente_fim", hora(r.texto("expediente_fim")));
        if (c.has("ativa")) {
            if (!p.plataforma()) throw Erro.proibido();
            campos.put("ativa", c.get("ativa").asBoolean());
        }
        Empresa e = db.tx(tx -> {
            if (repo.empresa(tx, id).isEmpty()) throw Erro.naoEncontrado();
            repo.atualizarEmpresa(tx, id, campos);
            return repo.empresa(tx, id).orElseThrow();
        });
        if (!e.expedienteInicio().isBefore(e.expedienteFim())) throw Erro.invalido("expediente_inicio deve ser anterior a expediente_fim");
        return Resp.json(200, empresaJson(e));
    }

    // ---- usuários

    private Resp listarUsuarios(Req r) {
        Principal p = r.principal();
        if (!p.plataforma()) p.exigir(Papel.ADMIN);
        Long empresa = p.plataforma() ? r.empresaIdPedida() : p.empresaId();
        Pagina<Usuario> pagina = db.tx(tx -> repo.usuarios(tx, empresa, r.pagina(), r.tamanho()));
        return Resp.json(200, ChamadoHandlers.pagina(pagina, CadastroHandlers::usuarioJson));
    }

    private Resp detalharUsuario(Req r) {
        Principal p = r.principal();
        if (!p.plataforma()) p.exigir(Papel.ADMIN);
        long id = r.id();
        return Resp.json(200, usuarioJson(db.tx(tx -> repo.usuario(tx, id, p.plataforma() ? null : p.empresaId())).orElseThrow(Erro::naoEncontrado)));
    }

    private Resp criarUsuario(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN);
        String email = r.texto("email");
        String nome = r.texto("nome");
        Papel papel = papelDaEmpresa(r);
        String senha = senha(r.texto("senha"));
        long id = db.tx(tx -> {
            if (repo.emailExiste(tx, email)) throw Erro.conflito("e-mail já cadastrado");
            return repo.inserirUsuario(tx, p.empresaId(), email, nome, papel, auth.hash(senha));
        });
        return Resp.json(201, usuarioJson(db.tx(tx -> repo.usuario(tx, id, p.empresaId())).orElseThrow()));
    }

    private Resp alterarUsuario(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN);
        JsonNode c = r.objeto();
        Map<String, Object> campos = new LinkedHashMap<>();
        if (c.has("email")) campos.put("email", r.texto("email"));
        if (c.has("nome")) campos.put("nome", r.texto("nome"));
        if (c.has("papel")) campos.put("papel", papelDaEmpresa(r));
        if (c.has("senha")) campos.put("senha_hash", auth.hash(senha(r.texto("senha"))));
        if (c.has("ativo")) campos.put("ativo", c.get("ativo").asBoolean());
        long id = r.id();
        Usuario u = db.tx(tx -> {
            if (repo.usuario(tx, id, p.empresaId()).isEmpty()) throw Erro.naoEncontrado();
            repo.atualizarUsuario(tx, id, p.empresaId(), campos);
            return repo.usuario(tx, id, p.empresaId()).orElseThrow();
        });
        return Resp.json(200, usuarioJson(u));
    }

    // ---- clientes

    private Resp listarClientes(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ATENDENTE, Papel.ADMIN);
        return Resp.json(200, ChamadoHandlers.pagina(db.tx(tx -> repo.clientes(tx, p.empresaId(), r.pagina(), r.tamanho())), m -> m));
    }

    private Resp criarCliente(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ATENDENTE, Papel.ADMIN);
        Map<String, Object> c = campos(r, true);
        long id = db.tx(tx -> repo.inserirCliente(tx, p.empresaId(), c));
        return Resp.json(201, db.tx(tx -> repo.cliente(tx, id, p.empresaId())).orElseThrow());
    }

    private Resp detalharCliente(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ATENDENTE, Papel.ADMIN, Papel.TECNICO);
        long id = r.id();
        return Resp.json(200, db.tx(tx -> {
            if (p.papel() == Papel.TECNICO && !repo.clienteDoTecnico(tx, id, p.usuarioId())) throw Erro.naoEncontrado();
            return repo.cliente(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        }));
    }

    private Resp alterarCliente(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ATENDENTE, Papel.ADMIN);
        Map<String, Object> c = campos(r, false);
        long id = r.id();
        return Resp.json(200, db.tx(tx -> {
            if (repo.cliente(tx, id, p.empresaId()).isEmpty()) throw Erro.naoEncontrado();
            repo.atualizarCliente(tx, id, p.empresaId(), c);
            return repo.cliente(tx, id, p.empresaId()).orElseThrow();
        }));
    }

    private static Map<String, Object> campos(Req r, boolean criacao) {
        JsonNode c = r.objeto();
        Map<String, Object> m = new LinkedHashMap<>();
        if (criacao || c.has("nome")) m.put("nome", r.texto("nome"));
        for (String campo : new String[] {"documento", "email", "telefone", "endereco"}) {
            if (c.has(campo)) m.put(campo, r.textoOuPadrao(campo, null));
            else if (criacao) m.put(campo, null);
        }
        if (!criacao && c.has("ativo")) m.put("ativo", c.get("ativo").asBoolean());
        return m;
    }

    // ---- técnicos

    private Resp listarTecnicos(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN, Papel.ATENDENTE);
        return Resp.json(200, ChamadoHandlers.pagina(db.tx(tx -> repo.tecnicos(tx, p.empresaId(), r.pagina(), r.tamanho())), CadastroHandlers::tecnicoJson));
    }

    private Resp detalharTecnico(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN, Papel.ATENDENTE);
        long id = r.id();
        return Resp.json(200, tecnicoJson(db.tx(tx -> repo.tecnico(tx, id, p.empresaId())).orElseThrow(Erro::naoEncontrado)));
    }

    private Resp criarTecnico(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN);
        long usuario = r.longo("usuario_id");
        String nome = r.texto("nome");
        return Resp.json(201, tecnicoJson(db.tx(tx -> {
            tecnicoValido(tx, usuario, p.empresaId());
            return repo.tecnico(tx, repo.inserirTecnico(tx, p.empresaId(), usuario, nome), p.empresaId()).orElseThrow();
        })));
    }

    private Resp alterarTecnico(Req r) {
        Principal p = r.principal();
        p.exigir(Papel.ADMIN);
        JsonNode c = r.objeto();
        Map<String, Object> campos = new LinkedHashMap<>();
        if (c.has("nome")) campos.put("nome", r.texto("nome"));
        if (c.has("usuario_id")) campos.put("usuario_id", r.longo("usuario_id"));
        if (c.has("ativo")) campos.put("ativo", c.get("ativo").asBoolean());
        long id = r.id();
        return Resp.json(200, tecnicoJson(db.tx(tx -> {
            if (repo.tecnico(tx, id, p.empresaId()).isEmpty()) throw Erro.naoEncontrado();
            if (campos.get("usuario_id") != null) tecnicoValido(tx, (Long) campos.get("usuario_id"), p.empresaId());
            repo.atualizarTecnico(tx, id, p.empresaId(), campos);
            return repo.tecnico(tx, id, p.empresaId()).orElseThrow();
        })));
    }

    private void tecnicoValido(Db.Tx tx, long usuarioId, long empresaId) {
        Usuario u = repo.usuario(tx, usuarioId, empresaId).orElseThrow(() -> Erro.invalido("usuario_id inexistente nesta empresa"));
        if (u.papel() != Papel.TECNICO) throw Erro.invalido("o usuário precisa ter o papel TECNICO");
    }

    // ---- validações e formato

    private static Papel papelDaEmpresa(Req r) {
        Papel papel = r.enumeracao("papel", Papel.class);
        if (papel == Papel.PLATAFORMA) throw Erro.invalido("papel inválido para usuário de empresa");
        return papel;
    }

    private static String senha(String senha) {
        if (senha.length() < SENHA_MINIMA) throw Erro.invalido("a senha deve ter ao menos " + SENHA_MINIMA + " caracteres");
        return senha;
    }

    private static String fuso(String texto) {
        try {
            return ZoneId.of(texto).getId();
        } catch (DateTimeException e) {
            throw Erro.invalido("fuso_horario inválido: " + texto);
        }
    }

    private static LocalTime hora(String texto) {
        try {
            return LocalTime.parse(texto, HORA);
        } catch (DateTimeParseException e) {
            throw Erro.invalido("horário deve ser HH:mm: " + texto);
        }
    }

    static Map<String, Object> empresaJson(Empresa e) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", e.id());
        m.put("nome", e.nome());
        m.put("fuso_horario", e.fusoHorario());
        m.put("expediente_inicio", HORA.format(e.expedienteInicio()));
        m.put("expediente_fim", HORA.format(e.expedienteFim()));
        m.put("ativa", e.ativa());
        return m;
    }

    static Map<String, Object> usuarioJson(Usuario u) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", u.id());
        m.put("empresa_id", u.empresaId());
        m.put("email", u.email());
        m.put("nome", u.nome());
        m.put("papel", u.papel().name());
        m.put("ativo", u.ativo());
        return m;
    }

    static Map<String, Object> tecnicoJson(Tecnico t) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", t.id());
        m.put("empresa_id", t.empresaId());
        m.put("usuario_id", t.usuarioId());
        m.put("nome", t.nome());
        m.put("ativo", t.ativo());
        return m;
    }
}
