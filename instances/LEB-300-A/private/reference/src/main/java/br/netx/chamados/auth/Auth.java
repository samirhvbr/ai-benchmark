package br.netx.chamados.auth;

import br.netx.chamados.Config;
import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Usuario;
import br.netx.chamados.repo.CadastroRepo;

import javax.crypto.SecretKeyFactory;
import javax.crypto.spec.PBEKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.time.Clock;
import java.time.Instant;
import java.util.Base64;
import java.util.HexFormat;
import java.util.Optional;

/** Login, senha, token de sessão e papéis. */
public final class Auth {
    private static final int ITERACOES = 120_000;
    private static final int BYTES_SAL = 16;
    private static final int BITS_HASH = 256;

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
            if (u.senhaHash().startsWith("sha256:")) cadastros.atualizarSenha(tx, u.id(), hash(senha));
            byte[] bruto = new byte[32];
            azar.nextBytes(bruto);
            String token = Base64.getUrlEncoder().withoutPadding().encodeToString(bruto);
            Instant expira = clock.instant().plus(config.tokenValidade());
            tx.update("insert into sessao (token, usuario_id, expira_em) values (?, ?, ?)", token, u.id(), expira);
            return new Sessao(token, expira, u);
        });
    }

    /** Devolve quem é o dono do token, se a sessão está ativa e não expirou. */
    public Optional<Principal> validar(String token) {
        return db.tx(tx -> tx.one(
                "select u.id, u.empresa_id, u.papel, u.nome from sessao s join usuario u on u.id = s.usuario_id "
                        + "left join empresa e on e.id = u.empresa_id "
                        + "where s.token = ? and s.ativa and s.expira_em > ? and u.ativo and (e.id is null or e.ativa)",
                rs -> new Principal(rs.getLong("id"), Db.longOuNulo(rs, "empresa_id"), Papel.valueOf(rs.getString("papel")), rs.getString("nome")),
                token, clock.instant()));
    }

    public void logout(String token) {
        db.tx(tx -> tx.update("update sessao set ativa = false where token = ?", token));
    }

    public String hash(String senha) {
        byte[] sal = new byte[BYTES_SAL];
        azar.nextBytes(sal);
        byte[] derivado = pbkdf2(senha, sal, ITERACOES, BITS_HASH);
        Base64.Encoder b64 = Base64.getEncoder().withoutPadding();
        return "pbkdf2$" + ITERACOES + "$" + b64.encodeToString(sal) + "$" + b64.encodeToString(derivado);
    }

    /** Aceita o formato atual e o legado {@code sha256:<hex>}, que o login regrava no formato atual. */
    static boolean confere(String senha, String armazenado) {
        if (armazenado.startsWith("pbkdf2$")) {
            String[] partes = armazenado.split("\\$");
            byte[] esperado = Base64.getDecoder().decode(partes[3]);
            byte[] obtido = pbkdf2(senha, Base64.getDecoder().decode(partes[2]), Integer.parseInt(partes[1]), esperado.length * 8);
            return MessageDigest.isEqual(esperado, obtido);
        }
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

    private static byte[] pbkdf2(String senha, byte[] sal, int iteracoes, int bits) {
        try {
            return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(new PBEKeySpec(senha.toCharArray(), sal, iteracoes, bits)).getEncoded();
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Erro credenciais() {
        return new Erro(401, "credenciais_invalidas", "e-mail ou senha inválidos");
    }
}
