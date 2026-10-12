package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;

/** Falhas de segurança. Escritas e executadas por último na ordem de trabalho. */
class V05_SegurancaTest extends TestBase {

    @Test
    @Verifica({"SEC-001.a"})
    void sec001_umFiltroNaoMudaAConsulta() throws Exception {
        String a = token(ATENDENTE_A);
        abrirChamado(a, cliA, "da empresa A", "NORMAL");
        abrirChamado(a, cliA, "outro da empresa A", "ALTA");
        String payload = URLEncoder.encode("ABERTO' OR '1'='1", StandardCharsets.UTF_8);
        R r = get("/chamados?status=" + payload, token(ADMIN_B));
        boolean seguro = r.status() == 422 || (r.status() == 200 && r.j("total").asInt() == 0);
        String payloadPrioridade = URLEncoder.encode("NORMAL' OR '1'='1", StandardCharsets.UTF_8);
        R p = get("/chamados?prioridade=" + payloadPrioridade, token(ADMIN_B));
        boolean seguraPrioridade = p.status() == 422 || (p.status() == 200 && p.j("total").asInt() == 0);
        R honesto = get("/chamados?status=ABERTO&prioridade=ALTA", a);
        boolean funciona = honesto.status() == 200 && honesto.j("total").asInt() == 1 && "ALTA".equals(honesto.j("itens").get(0).get("prioridade").asText());   // o filtro legítimo continua filtrando
        Resultado.c("SEC-001.a", seguro && seguraPrioridade && funciona,
                "status injetado devolveu " + r.status() + (r.status() == 200 ? " com " + r.j("total") + " linhas de outra empresa" : "")
                        + "; prioridade injetada devolveu " + p.status() + (p.status() == 200 ? " com " + p.j("total") + " linhas" : "") + "; filtro legítimo=" + funciona);
    }

    @Test
    @Verifica({"SEC-016"})
    void sec016_soOAdminAlteraUmUsuario() throws Exception {
        R r = patch("/usuarios/" + atdA, token(ATENDENTE_A), Map.of("papel", "ADMIN"));
        String papel = String.valueOf(consultar("select papel from usuario where id = ?", atdA).get(0).get("papel"));
        // as outras alterações também são do administrador: trocar a senha ou desativar a conta de outro usuário
        String admin = token(ADMIN_A);
        R senha = patch("/usuarios/" + admJa(), token(ATENDENTE_A), Map.of("senha", "senha-trocada-por-outro"));
        R desativa = patch("/usuarios/" + admJa(), token(ATENDENTE_A), Map.of("ativo", false));
        boolean adminIntacto = post("/auth/login", null, Map.of("email", ADMIN_A, "senha", SENHA)).status() == 200
                && post("/auth/login", null, Map.of("email", ADMIN_A, "senha", "senha-trocada-por-outro")).status() == 401;
        R legitimo = patch("/usuarios/" + atdA, admin, Map.of("nome", "Renomeado pelo admin"));
        boolean ok = r.status() == 403 && "ATENDENTE".equals(papel) && senha.status() == 403 && desativa.status() == 403 && adminIntacto && legitimo.status() == 200;
        Resultado.c("SEC-016", ok, "o atendente alterou o próprio papel: HTTP " + r.status() + ", papel no banco=" + papel + "; trocar a senha do admin: HTTP " + senha.status()
                + "; desativar o admin: HTTP " + desativa.status() + "; admin intacto=" + adminIntacto + "; o admin continua alterando=" + legitimo.status());
    }

    @Test
    @Verifica({"SEC-017.a"})
    void sec017a_oHistoricoDeEventosEDaEmpresa() throws Exception {
        long meu = abrirChamado(token(ATENDENTE_A), cliA, "eventos privados", "NORMAL");
        R r = get("/chamados/" + meu + "/eventos", token(ADMIN_B));
        Resultado.c("SEC-017.a", r.status() == 404, "outra empresa leu os eventos: HTTP " + r.status());
    }

    @Test
    @Verifica({"SEC-015"})
    void sec015_naoHaContaPadraoComSenhaNoCodigo() throws Exception {
        String literal = "Netx@2013!";
        List<String> emails = new ArrayList<>();
        for (Map<String, Object> u : consultar("select email from usuario")) emails.add(String.valueOf(u.get("email")));
        emails.add("plataforma@netx.local");
        boolean entrou = false;
        for (String email : emails) {
            if (post("/auth/login", null, Map.of("email", email, "senha", literal)).status() == 200) entrou = true;
        }
        Path classes = Path.of(App.class.getProtectionDomain().getCodeSource().getLocation().toURI());
        boolean noCodigo = false;
        try (Stream<Path> arquivos = Files.walk(classes)) {
            for (Path p : arquivos.filter(x -> x.toString().endsWith(".class")).toList()) {
                if (contem(Files.readAllBytes(p), literal.getBytes(StandardCharsets.UTF_8))) noCodigo = true;
            }
        }
        // contas que a própria aplicação criou ao subir: nenhum texto escrito no código pode abrir uma delas
        List<String> doApp = new ArrayList<>();
        for (Map<String, Object> u : consultar("select email from usuario")) {
            String e = String.valueOf(u.get("email"));
            if (!List.of(ADMIN_A, ATENDENTE_A, TECNICO_A, ADMIN_B, PLATAFORMA).contains(e)) doApp.add(e);
        }
        boolean adivinhada = false;
        if (!doApp.isEmpty()) {
            Estrutura estrutura = new Estrutura(Path.of("src/main/java"), Path.of("target/classes"));
            java.util.Set<String> candidatas = new java.util.TreeSet<>();
            candidatas.add(literal);
            for (Estrutura.Texto t : estrutura.textos) if (t.valor().length() >= 8 && t.valor().length() <= 40 && !t.valor().contains("\n")) candidatas.add(t.valor());
            for (String email : doApp) {
                for (String candidata : candidatas) if (post("/auth/login", null, Map.of("email", email, "senha", candidata)).status() == 200) adivinhada = true;
            }
        }
        Resultado.c("SEC-015", !entrou && !noCodigo && !adivinhada,
                "login com a senha padrão=" + entrou + "; senha literal nas classes=" + noCodigo + "; contas criadas pela aplicação=" + doApp.size() + ", aberta por texto do código=" + adivinhada);
    }

    @Test
    @Verifica({"SEC-014.a"})
    void sec014_asSenhasNovasSaoSalgadas() throws Exception {
        String admin = token(ADMIN_A);
        String senha = "mesma-senha-longa";
        post("/usuarios", admin, Map.of("email", "um@a.test", "nome", "Um", "papel", "TECNICO", "senha", senha));
        post("/usuarios", admin, Map.of("email", "dois@a.test", "nome", "Dois", "papel", "TECNICO", "senha", senha));
        String h1 = String.valueOf(consultar("select senha_hash from usuario where email = 'um@a.test'").get(0).get("senha_hash"));
        String h2 = String.valueOf(consultar("select senha_hash from usuario where email = 'dois@a.test'").get(0).get("senha_hash"));
        String puro = HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(senha.getBytes(StandardCharsets.UTF_8)));
        boolean salgado = !h1.equals(h2) && !h1.contains(puro) && !h2.contains(puro) && !h1.contains(senha);
        boolean entram = post("/auth/login", null, Map.of("email", "um@a.test", "senha", senha)).status() == 200
                && post("/auth/login", null, Map.of("email", "dois@a.test", "senha", senha)).status() == 200;
        boolean legadoEntra = post("/auth/login", null, Map.of("email", ADMIN_A, "senha", SENHA)).status() == 200;   // hash legado sha256: ainda vale
        Resultado.c("SEC-014.a", salgado && entram && legadoEntra, "mesma senha, hashes diferentes=" + !h1.equals(h2) + ", sem SHA-256 puro=" + !h1.contains(puro) + ", login ok=" + entram + ", legado ok=" + legadoEntra);
    }

    @Test
    @Verifica({"SEC-017.b"})
    void sec017b_oRelatorioEmCacheNaoVazaEntreEmpresas() throws Exception {
        relogio.agora = Instant.parse("2026-03-10T15:00:00Z");
        Instant prazo = Instant.parse("2026-03-20T12:00:00Z");
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", Instant.parse("2026-03-09T12:00:00Z"), prazo, null);
        chamadoNoBanco(empA, cliA, atdA, "ALTA", "ABERTO", Instant.parse("2026-03-09T13:00:00Z"), prazo, null);
        int deA = get("/relatorios/sla?mes=2026-03", token(ADMIN_A)).j("abertos").asInt();
        int deB = get("/relatorios/sla?mes=2026-03", token(ADMIN_B)).j("abertos").asInt();
        // o relatório de outro mês da mesma empresa não pode vir do cache de março
        R abril = get("/relatorios/sla?mes=2026-04", token(ADMIN_A));
        boolean mesCerto = abril.status() == 200 && "2026-04".equals(abril.j("mes").asText()) && abril.j("abertos").asInt() == 0;
        Resultado.c("SEC-017.b", deA == 2 && deB == 0 && mesCerto, "empresa A viu " + deA + " e, logo depois, a empresa B viu " + deB + " (esperado 2 e 0); abril da empresa A: " + abril.bruto().substring(0, Math.min(60, abril.bruto().length())));
    }

    @Test
    @Verifica({"SEC-008"})
    void sec008_aPlanilhaNaoExecutaFormulas() throws Exception {
        relogio.agora = Instant.parse("2026-03-10T15:00:00Z");
        Instant prazo = Instant.parse("2026-03-20T12:00:00Z");
        String[] titulos = {"=1+1", "+cmd|' /C calc'!A0", "-2+3", "@SUM(A1:A9)", "texto; com ponto e vírgula"};
        for (String titulo : titulos) {
            long id = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", Instant.parse("2026-03-09T12:00:00Z"), prazo, null);
            atualizar("update chamado set titulo = ? where id = ?", titulo, id);
        }
        R r = get("/relatorios/exportacao.csv?mes=2026-03", token(ADMIN_A));
        List<List<String>> linhas = csv(r.bruto());
        boolean formula = false;
        boolean intacto = r.status() == 200 && linhas.size() == 1 + titulos.length;
        boolean pontoEVirgula = false;
        for (List<String> campos : linhas.subList(1, linhas.size())) {
            String titulo = campos.get(1);
            if (!titulo.isEmpty() && "=+-@".indexOf(titulo.charAt(0)) >= 0) formula = true;
            if (titulo.equals("texto; com ponto e vírgula")) pontoEVirgula = true;      // o texto comum sai exatamente como entrou
        }
        Resultado.c("SEC-008", !formula && intacto && pontoEVirgula, "célula começando por fórmula=" + formula + ", linhas=" + linhas.size() + ", ponto e vírgula preservado=" + pontoEVirgula);
    }

    @Test
    @Verifica({"SEC-020"})
    void sec020_nenhumaRespostaTrazOHashDaSenha() throws Exception {
        String admin = token(ADMIN_A);
        String lista = get("/usuarios", admin).bruto();
        String criado = post("/usuarios", admin, Map.of("email", "novo@a.test", "nome", "Novo", "papel", "TECNICO", "senha", "uma-senha-longa")).bruto();
        String alterado = patch("/usuarios/" + atdA, admin, Map.of("nome", "Atendente Renomeado")).bruto();
        String detalhe = get("/usuarios/" + atdA, admin).bruto();
        boolean vaza = Stream.of(lista, criado, alterado, detalhe).anyMatch(s -> s.contains("senha_hash") || s.contains("sha256:") || s.contains("pbkdf2$"));
        Resultado.c("SEC-020", !vaza, "alguma resposta de usuário trouxe o hash=" + vaza);
    }

    @Test
    @Verifica({"SEC-013"})
    void sec013_depoisDoLogoutOTokenNaoVale() throws Exception {
        String t = token(ADMIN_A);
        int antes = get("/chamados", t).status();
        int logout = post("/auth/logout", t, null).status();
        int depois = get("/chamados", t).status();
        Resultado.c("SEC-013", antes == 200 && logout == 204 && depois == 401, "antes=" + antes + " logout=" + logout + " depois=" + depois + " (esperado 200, 204, 401)");
    }

    // ---- auxiliares

    private long admJa() {
        return admA;
    }

    private static boolean contem(byte[] palheiro, byte[] agulha) {
        outer:
        for (int i = 0; i + agulha.length <= palheiro.length; i++) {
            for (int j = 0; j < agulha.length; j++) if (palheiro[i + j] != agulha[j]) continue outer;
            return true;
        }
        return false;
    }

    /** CSV com ";" e aspas duplas, como o manifesto define. */
    static List<List<String>> csv(String texto) {
        List<List<String>> linhas = new ArrayList<>();
        List<String> campos = new ArrayList<>();
        StringBuilder atual = new StringBuilder();
        boolean aspas = false;
        for (int i = 0; i < texto.length(); i++) {
            char c = texto.charAt(i);
            if (aspas) {
                if (c == '"' && i + 1 < texto.length() && texto.charAt(i + 1) == '"') { atual.append('"'); i++; }
                else if (c == '"') aspas = false;
                else atual.append(c);
            } else if (c == '"') aspas = true;
            else if (c == ';') { campos.add(atual.toString()); atual.setLength(0); }
            else if (c == '\n') { campos.add(atual.toString()); atual.setLength(0); linhas.add(campos); campos = new ArrayList<>(); }
            else atual.append(c);
        }
        if (atual.length() > 0 || !campos.isEmpty()) { campos.add(atual.toString()); linhas.add(campos); }
        return linhas;
    }
}
