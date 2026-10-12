"""SEC flaws. Written and planted last, after everything that is not security."""
from plantar import SRC

BOOTSTRAP = '''    /** Primeira instalação: cria a conta da plataforma para o primeiro acesso. */
    private void garantirContaDaPlataforma() {
        db.tx(tx -> {
            if (tx.count("select count(*) from usuario where papel = 'PLATAFORMA'") == 0) {
                cadastros.inserirUsuario(tx, null, "plataforma@netx.local", "Plataforma", Papel.PLATAFORMA, auth.hash("Netx@2013!"));
            }
            return null;
        });
    }

'''

FLAWS = [

    ("SEC-001", [
        (SRC + "repo/ChamadoRepo.java",
         "    public record Filtro(Long empresaId, StatusChamado status, Prioridade prioridade, Long tecnicoId, Long tecnicoUsuarioId, int pagina, int tamanho) {}",
         "    public record Filtro(Long empresaId, String status, String prioridade, Long tecnicoId, Long tecnicoUsuarioId, int pagina, int tamanho) {}"),
        (SRC + "repo/ChamadoRepo.java",
         "        if (f.status() != null) { onde.append(\" and c.status = ?\"); args.add(f.status()); }\n"
         "        if (f.prioridade() != null) { onde.append(\" and c.prioridade = ?\"); args.add(f.prioridade()); }",
         "        if (f.status() != null) onde.append(\" and c.status = '\" + f.status() + \"'\");\n"
         "        if (f.prioridade() != null) onde.append(\" and c.prioridade = '\" + f.prioridade() + \"'\");"),
        (SRC + "servico/ChamadoService.java",
         "Long empresaIdPedida, StatusChamado status, Prioridade prioridade, Long tecnicoId, int pagina, int tamanho) {",
         "Long empresaIdPedida, String status, String prioridade, Long tecnicoId, int pagina, int tamanho) {"),
        (SRC + "http/ChamadoHandlers.java",
         "        StatusChamado status = r.enumeracaoDaConsulta(\"status\", StatusChamado.class);\n"
         "        Prioridade prioridade = r.enumeracaoDaConsulta(\"prioridade\", Prioridade.class);",
         "        String status = r.consulta(\"status\");\n"
         "        String prioridade = r.consulta(\"prioridade\");"),
    ]),

    ("SEC-016", [
        (SRC + "http/CadastroHandlers.java",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n        p.exigir(Papel.ADMIN);\n",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n"),
    ]),

    ("SEC-017.a", [
        (SRC + "servico/ChamadoService.java",
         "        detalhar(tx, p, id);\n        return chamados.eventos(tx, id);",
         "        return chamados.eventos(tx, id);"),
    ]),

    ("SEC-015", [
        (SRC + "App.java", "import br.netx.chamados.db.Db;\nimport br.netx.chamados.http.CadastroHandlers;",
         "import br.netx.chamados.db.Db;\nimport br.netx.chamados.dominio.Dominio.Papel;\nimport br.netx.chamados.http.CadastroHandlers;"),
        (SRC + "App.java",
         "    private final JobRunner jobs;\n    private ScheduledExecutorService agendador;\n\n"
         "    private App(Config config, Servidor servidor, JobRunner jobs) {\n        this.config = config;\n        this.servidor = servidor;\n        this.jobs = jobs;\n    }",
         "    private final JobRunner jobs;\n    private final Db db;\n    private final Auth auth;\n    private final CadastroRepo cadastros;\n    private ScheduledExecutorService agendador;\n\n"
         "    private App(Config config, Servidor servidor, JobRunner jobs, Db db, Auth auth, CadastroRepo cadastros) {\n"
         "        this.config = config;\n        this.servidor = servidor;\n        this.jobs = jobs;\n        this.db = db;\n        this.auth = auth;\n        this.cadastros = cadastros;\n    }"),
        (SRC + "App.java", "        return new App(config, servidor, jobs);", "        return new App(config, servidor, jobs, db, auth, cadastros);"),
        (SRC + "App.java", "        int porta = servidor.iniciar(config.porta());", "        garantirContaDaPlataforma();\n        int porta = servidor.iniciar(config.porta());"),
        (SRC + "App.java", "    public JobRunner jobs() {", BOOTSTRAP + "    public JobRunner jobs() {"),
    ]),

    ("SEC-014", [
        (SRC + "auth/Auth.java",
         "    public String hash(String senha) {\n"
         "        byte[] sal = new byte[BYTES_SAL];\n"
         "        azar.nextBytes(sal);\n"
         "        byte[] derivado = pbkdf2(senha, sal, ITERACOES, BITS_HASH);\n"
         "        Base64.Encoder b64 = Base64.getEncoder().withoutPadding();\n"
         "        return \"pbkdf2$\" + ITERACOES + \"$\" + b64.encodeToString(sal) + \"$\" + b64.encodeToString(derivado);\n"
         "    }",
         "    public String hash(String senha) {\n        return \"sha256:\" + sha256Hex(senha);\n    }"),
    ]),

    # What building the reference left behind in the legacy of SEC-014: nothing in the legacy writes or documents the pbkdf2 format, the manifest does not
    # mention it and no test uses it. It is not a capability of the legacy, so it goes (2026-10-08, owner's review before freezing the pilot).
    ("SEC-014-RESIDUO", [
        (SRC + "auth/Auth.java",
         "    private static final int ITERACOES = 120_000;\n    private static final int BYTES_SAL = 16;\n    private static final int BITS_HASH = 256;\n\n",
         ""),
        (SRC + "auth/Auth.java", "import javax.crypto.SecretKeyFactory;\nimport javax.crypto.spec.PBEKeySpec;\n", ""),
        (SRC + "auth/Auth.java",
         "            if (u.senhaHash().startsWith(\"sha256:\")) cadastros.atualizarSenha(tx, u.id(), hash(senha));\n", ""),
        (SRC + "auth/Auth.java",
         "    /** Aceita o formato atual e o legado {@code sha256:<hex>}, que o login regrava no formato atual. */\n"
         "    static boolean confere(String senha, String armazenado) {\n"
         "        if (armazenado.startsWith(\"pbkdf2$\")) {\n"
         "            String[] partes = armazenado.split(\"\\\\$\");\n"
         "            byte[] esperado = Base64.getDecoder().decode(partes[3]);\n"
         "            byte[] obtido = pbkdf2(senha, Base64.getDecoder().decode(partes[2]), Integer.parseInt(partes[1]), esperado.length * 8);\n"
         "            return MessageDigest.isEqual(esperado, obtido);\n"
         "        }\n"
         "        if (armazenado.startsWith(\"sha256:\")) {",
         "    /** Confere a senha com o valor guardado, no formato {@code sha256:<hex>}. */\n"
         "    static boolean confere(String senha, String armazenado) {\n"
         "        if (armazenado.startsWith(\"sha256:\")) {"),
        (SRC + "auth/Auth.java",
         "    private static byte[] pbkdf2(String senha, byte[] sal, int iteracoes, int bits) {\n"
         "        try {\n"
         "            return SecretKeyFactory.getInstance(\"PBKDF2WithHmacSHA256\").generateSecret(new PBEKeySpec(senha.toCharArray(), sal, iteracoes, bits)).getEncoded();\n"
         "        } catch (GeneralSecurityException e) {\n"
         "            throw new IllegalStateException(e);\n"
         "        }\n"
         "    }\n\n",
         ""),
        (SRC + "repo/CadastroRepo.java",
         "    public void atualizarSenha(Tx tx, long id, String hash) {\n        tx.update(\"update usuario set senha_hash = ? where id = ?\", hash, id);\n    }\n\n",
         ""),
    ]),

    ("SEC-017.b", [
        (SRC + "relatorio/RelatorioService.java",
         "        String chave = empresaId + \":\" + mes;",
         "        String chave = String.valueOf(mes);"),
    ]),

    ("SEC-008", [
        (SRC + "relatorio/CsvExportador.java",
         "        if (!v.isEmpty() && \"=+-@\\t\\r\".indexOf(v.charAt(0)) >= 0) v = \"'\" + v;\n",
         ""),
    ]),

    ("SEC-020", [
        (SRC + "http/CadastroHandlers.java",
         "        m.put(\"papel\", u.papel().name());\n        m.put(\"ativo\", u.ativo());",
         "        m.put(\"papel\", u.papel().name());\n        m.put(\"senha_hash\", u.senhaHash());\n        m.put(\"ativo\", u.ativo());"),
    ]),

    ("SEC-013", [
        (SRC + "auth/Auth.java",
         "where s.token = ? and s.ativa and s.expira_em > ? and u.ativo",
         "where s.token = ? and s.expira_em > ? and u.ativo"),
    ]),
]
