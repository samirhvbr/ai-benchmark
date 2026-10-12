package br.netx.chamados.auth;

import br.netx.chamados.Config;
import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Usuario;
import br.netx.chamados.repo.CadastroRepo;

import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.HexFormat;
import java.util.Optional;

/** Login, senha, token de sessão e papéis. */
public final class Auth {
    private final Db db;
    private final Clock clock;
    private final Config config;
    private final CadastroRepo cadastros;
    private final SecureRandom azar = new SecureRandom();

    public Auth(Db db, Clock clock, Config config, CadastroRepo cadastros) {
        this.db = db;
        this.clock = clock;
        this.config = config;
        this.cadastros = cadastros;
    }

    public record Sessao(String token, Instant expiraEm, Usuario usuario) {}

    public Sessao login(String email, String senha) {
        return db.tx(tx -> {
            Usuario u = cadastros.usuarioPorEmail(tx, email).orElseThrow(Auth::credenciais);
            if (!confere(senha, u.senhaHash())) throw credenciais();
            if (u.empresaId() != null) {
                Empresa e = cadastros.empresa(tx, u.empresaId()).orElseThrow(Auth::credenciais);
                if (!e.ativa()) throw credenciais();
            }
            byte[] bruto = new byte[32];
            azar.nextBytes(bruto);
            String token = Base64.getUrlEncoder().withoutPadding().encodeToString(bruto);
            Instant expira = clock.instant().plus(Duration.ofHours(8));
            tx.update("insert into sessao (token, usuario_id, expira_em) values (?, ?, ?)", token, u.id(), expira);
            return new Sessao(token, expira, u);
        });
    }

    /** Devolve quem é o dono do token, se a sessão está ativa e não expirou. */
    public Optional<Principal> validar(String token) {
        return db.tx(tx -> tx.one(
                "select u.id, u.empresa_id, u.papel, u.nome from sessao s join usuario u on u.id = s.usuario_id "
                        + "left join empresa e on e.id = u.empresa_id "
                        + "where s.token = ? and s.expira_em > ? and u.ativo and (e.id is null or e.ativa)",
                rs -> new Principal(rs.getLong("id"), Db.longOuNulo(rs, "empresa_id"), Papel.valueOf(rs.getString("papel")), rs.getString("nome")),
                token, clock.instant()));
    }

    public void logout(String token) {
        db.tx(tx -> tx.update("update sessao set ativa = false where token = ?", token));
    }

    public String hash(String senha) {
        return "sha256:" + sha256Hex(senha);
    }

    /** Confere a senha com o valor guardado, no formato {@code sha256:<hex>}. */
    static boolean confere(String senha, String armazenado) {
        if (armazenado.startsWith("sha256:")) {
            return MessageDigest.isEqual(sha256Hex(senha).getBytes(StandardCharsets.UTF_8), armazenado.substring(7).getBytes(StandardCharsets.UTF_8));
        }
        return false;
    }

    public static String sha256Hex(String texto) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(texto.getBytes(StandardCharsets.UTF_8)));
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Erro credenciais() {
        return new Erro(401, "credenciais_invalidas", "e-mail ou senha inválidos");
    }
}
