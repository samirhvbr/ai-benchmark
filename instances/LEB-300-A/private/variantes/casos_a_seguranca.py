"""Control variants for the security flaws: alternative fixes the verifier must accept, partial or wrong fixes it must reject."""
from motor import Caso

CH = "http/CadastroHandlers.java"
REPO = "repo/ChamadoRepo.java"
AUTH = "auth/Auth.java"
REL = "relatorio/RelatorioService.java"
CSV = "relatorio/CsvExportador.java"

STATUS = '''        if (f.status() != null) onde.append(" and c.status = '" + f.status() + "'");
        if (f.prioridade() != null) onde.append(" and c.prioridade = '" + f.prioridade() + "'");
'''

# What a fix of SEC-014 has to add besides the new hash(): the reader of the new format and the key derivation (the legacy has neither).
PBKDF2_BASE = [
    (AUTH, "import java.nio.charset.StandardCharsets;\n", "import javax.crypto.SecretKeyFactory;\nimport javax.crypto.spec.PBEKeySpec;\nimport java.nio.charset.StandardCharsets;\n"),
    (AUTH, '    /** Confere a senha com o valor guardado, no formato {@code sha256:<hex>}. */\n    static boolean confere(String senha, String armazenado) {\n',
     '''    static boolean confere(String senha, String armazenado) {
        if (armazenado.startsWith("pbkdf2$")) {
            String[] partes = armazenado.split("\\\\$");
            byte[] esperado = Base64.getDecoder().decode(partes[3]);
            byte[] obtido = pbkdf2(senha, Base64.getDecoder().decode(partes[2]), Integer.parseInt(partes[1]), esperado.length * 8);
            return MessageDigest.isEqual(esperado, obtido);
        }
'''),
    (AUTH, "    private static Erro credenciais() {", '''    private static byte[] pbkdf2(String senha, byte[] sal, int iteracoes, int bits) {
        try {
            return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(new PBEKeySpec(senha.toCharArray(), sal, iteracoes, bits)).getEncoded();
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Erro credenciais() {'''),
]

CASOS = [
    # ---- SEC-001.a
    Caso("SEC-001.a", "sec001-parametros", "alternativa", "corrigida", car=True, edits=[(REPO, STATUS,
         '''        if (f.status() != null) { onde.append(" and c.status = ?"); args.add(f.status()); }
        if (f.prioridade() != null) { onde.append(" and c.prioridade = ?"); args.add(f.prioridade()); }
''')]),
    Caso("SEC-001.a", "sec001-lista-permitida", "alternativa", "corrigida", car=True, edits=[
        (REPO, STATUS, '''        if (f.status() != null) onde.append(" and c.status = '" + valido(f.status(), StatusChamado.values()) + "'");
        if (f.prioridade() != null) onde.append(" and c.prioridade = '" + valido(f.prioridade(), Prioridade.values()) + "'");
'''),
        (REPO, "    public Pagina<Visao> listar(Tx tx, Filtro f) {", '''    private static String valido(String valor, Enum<?>[] permitidos) {
        for (Enum<?> e : permitidos) if (e.name().equals(valor)) return valor;
        throw br.netx.chamados.dominio.Dominio.Erro.invalido("filtro inválido: " + valor);
    }

    public Pagina<Visao> listar(Tx tx, Filtro f) {''')]),
    Caso("SEC-001.a", "sec001-so-status", "mutante", "nao_corrigida", edits=[(REPO, STATUS,
         '''        if (f.status() != null) { onde.append(" and c.status = ?"); args.add(f.status()); }
        if (f.prioridade() != null) onde.append(" and c.prioridade = '" + f.prioridade() + "'");
''')]),
    Caso("SEC-001.a", "sec001-so-prioridade", "mutante", "nao_corrigida", edits=[(REPO, STATUS,
         '''        if (f.status() != null) onde.append(" and c.status = '" + f.status() + "'");
        if (f.prioridade() != null) { onde.append(" and c.prioridade = ?"); args.add(f.prioridade()); }
''')]),
    Caso("SEC-001.a", "sec001-quebra-o-filtro", "mutante", "nao_corrigida", edits=[(REPO, STATUS,
         '''        if (f.status() != null) { onde.append(" and c.status = ?"); args.add("ABERTO"); }
        if (f.prioridade() != null) { onde.append(" and c.prioridade = ?"); args.add("NORMAL"); }
''')]),

    # ---- SEC-016
    Caso("SEC-016", "sec016-exige-admin", "alternativa", "corrigida", car=True, edits=[(CH,
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n        p.exigir(Papel.ADMIN);\n")]),
    Caso("SEC-016", "sec016-so-o-papel", "mutante", "nao_corrigida", edits=[(CH,
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n        if (r.objeto().has(\"papel\")) p.exigir(Papel.ADMIN);\n")]),
    Caso("SEC-016", "sec016-so-bloqueia-tecnico", "mutante", "nao_corrigida", edits=[(CH,
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n        if (p.papel() == Papel.TECNICO) throw Erro.proibido();\n")]),
    Caso("SEC-016", "sec016-bloqueia-todos", "mutante", "nao_corrigida", edits=[(CH,
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n",
         "    private Resp alterarUsuario(Req r) {\n        Principal p = r.principal();\n        p.exigir(Papel.PLATAFORMA);\n")]),

    # ---- SEC-017.a
    Caso("SEC-017.a", "sec017a-detalhar-antes", "alternativa", "corrigida", car=True, edits=[("servico/ChamadoService.java",
         "        return chamados.eventos(tx, id);", "        detalhar(tx, p, id);\n        return chamados.eventos(tx, id);")]),
    Caso("SEC-017.a", "sec017a-so-existencia", "mutante", "nao_corrigida", edits=[("servico/ChamadoService.java",
         "        return chamados.eventos(tx, id);", "        chamados.travar(tx, id, null).orElseThrow(Erro::naoEncontrado);\n        return chamados.eventos(tx, id);")]),

    # ---- SEC-015
    Caso("SEC-015", "sec015-variavel-de-ambiente", "alternativa", "corrigida", car=True, edits=[
        ("App.java", "            if (tx.count(\"select count(*) from usuario where papel = 'PLATAFORMA'\") == 0) {",
         "            if (System.getenv(\"LEB_PLATAFORMA_SENHA\") != null && tx.count(\"select count(*) from usuario where papel = 'PLATAFORMA'\") == 0) {"),
        ("App.java", 'auth.hash("Netx@2013!")', 'auth.hash(System.getenv("LEB_PLATAFORMA_SENHA"))')]),
    Caso("SEC-015", "sec015-senha-aleatoria-impressa", "alternativa", "corrigida", car=True, edits=[
        ("App.java", 'auth.hash("Netx@2013!")', 'auth.hash(inicial())'),
        ("App.java", "    private void garantirContaDaPlataforma() {",
         '    private static String inicial() {\n        String s = java.util.UUID.randomUUID().toString();\n        System.out.println("senha inicial da plataforma: " + s);\n        return s;\n    }\n\n    private void garantirContaDaPlataforma() {')]),
    Caso("SEC-015", "sec015-outra-senha-no-codigo", "mutante", "nao_corrigida", edits=[("App.java", '"Netx@2013!"', '"Outra@2024Senha!"')]),
    Caso("SEC-015", "sec015-mesma-senha-em-base64", "mutante", "nao_corrigida", edits=[("App.java", 'auth.hash("Netx@2013!")',
         'auth.hash(new String(java.util.Base64.getDecoder().decode("TmV0eEAyMDEzIQ=="), java.nio.charset.StandardCharsets.UTF_8))')]),

    # ---- SEC-014.a  (the legacy knows only "sha256:<hex>": a fix has to write and read its own format)
    Caso("SEC-014.a", "sec014-pbkdf2", "alternativa", "corrigida", car=True, edits=PBKDF2_BASE + [(AUTH,
         '    public String hash(String senha) {\n        return "sha256:" + sha256Hex(senha);\n    }',
         '''    public String hash(String senha) {
        byte[] sal = new byte[16];
        azar.nextBytes(sal);
        int iteracoes = 120_000;
        return "pbkdf2$" + iteracoes + "$" + Base64.getEncoder().encodeToString(sal) + "$" + Base64.getEncoder().encodeToString(pbkdf2(senha, sal, iteracoes, 256));
    }''')]),
    Caso("SEC-014.a", "sec014-sal-fixo", "mutante", "nao_corrigida", edits=PBKDF2_BASE + [(AUTH,
         '    public String hash(String senha) {\n        return "sha256:" + sha256Hex(senha);\n    }',
         '''    public String hash(String senha) {
        byte[] sal = "netx".getBytes(StandardCharsets.UTF_8);
        return "pbkdf2$1000$" + Base64.getEncoder().encodeToString(sal) + "$" + Base64.getEncoder().encodeToString(pbkdf2(senha, sal, 1000, 256));
    }''')]),
    Caso("SEC-014.a", "sec014-sha256-salgado-rapido", "limite", "corrigida", nota="salted but fast: passes the behavioral probe and is flagged as not verified (work factor)", edits=[
        (AUTH, '    public String hash(String senha) {\n        return "sha256:" + sha256Hex(senha);\n    }',
         '''    public String hash(String senha) {
        byte[] sal = new byte[8];
        azar.nextBytes(sal);
        String saltHex = HexFormat.of().formatHex(sal);
        return "sha256s$" + saltHex + "$" + sha256Hex(saltHex + senha);
    }'''),
        (AUTH, '        if (armazenado.startsWith("sha256:")) {\n            return MessageDigest.isEqual(sha256Hex(senha).getBytes(StandardCharsets.UTF_8), armazenado.substring(7).getBytes(StandardCharsets.UTF_8));\n        }\n        return false;',
         '''        if (armazenado.startsWith("sha256:")) {
            return MessageDigest.isEqual(sha256Hex(senha).getBytes(StandardCharsets.UTF_8), armazenado.substring(7).getBytes(StandardCharsets.UTF_8));
        }
        if (armazenado.startsWith("sha256s$")) {
            String[] p = armazenado.split("\\\\$");
            return MessageDigest.isEqual(sha256Hex(p[1] + senha).getBytes(StandardCharsets.UTF_8), p[2].getBytes(StandardCharsets.UTF_8));
        }
        return false;''')]),
    Caso("SEC-014.a", "sec014-quebra-o-legado", "mutante", "nao_corrigida", edits=PBKDF2_BASE + [
        (AUTH, '    public String hash(String senha) {\n        return "sha256:" + sha256Hex(senha);\n    }',
         '''    public String hash(String senha) {
        byte[] sal = new byte[16];
        azar.nextBytes(sal);
        return "pbkdf2$120000$" + Base64.getEncoder().encodeToString(sal) + "$" + Base64.getEncoder().encodeToString(pbkdf2(senha, sal, 120000, 256));
    }'''),
        (AUTH, '        if (armazenado.startsWith("sha256:")) {\n            return MessageDigest.isEqual(sha256Hex(senha)', '        if (false) {\n            return MessageDigest.isEqual(sha256Hex(senha)')]),

    # ---- SEC-017.b
    Caso("SEC-017.b", "sec017b-chave-empresa-e-mes", "alternativa", "corrigida", car=True, edits=[(REL, "String chave = String.valueOf(mes);", 'String chave = empresaId + ":" + mes;')]),
    Caso("SEC-017.b", "sec017b-sem-cache", "alternativa", "corrigida", car=True, edits=[(REL, "        if (g != null && Duration.between(g.em(), agora).compareTo(VALIDADE_SLA) < 0) return g.relatorio();", "")]),
    Caso("SEC-017.b", "sec017b-so-empresa", "mutante", "nao_corrigida", edits=[(REL, "String chave = String.valueOf(mes);", "String chave = String.valueOf(empresaId);")]),

    # ---- SEC-008
    Caso("SEC-008", "sec008-apostrofo", "alternativa", "corrigida", car=True, edits=[(CSV, "        String v = valor;\n",
         "        String v = valor;\n        if (!v.isEmpty() && \"=+-@\\t\\r\".indexOf(v.charAt(0)) >= 0) v = \"'\" + v;\n")]),
    Caso("SEC-008", "sec008-so-igual", "mutante", "nao_corrigida", edits=[(CSV, "        String v = valor;\n", "        String v = valor;\n        if (v.startsWith(\"=\")) v = \"'\" + v;\n")]),
    Caso("SEC-008", "sec008-prefixa-tudo", "mutante", "nao_corrigida", edits=[(CSV, "        String v = valor;\n", "        String v = valor;\n        if (!v.isEmpty()) v = \"'\" + v;\n")]),

    # ---- SEC-020
    Caso("SEC-020", "sec020-remove-o-campo", "alternativa", "corrigida", car=True, edits=[(CH, '        m.put("senha_hash", u.senhaHash());\n', "")]),
    Caso("SEC-020", "sec020-mascara", "mutante", "nao_corrigida", edits=[(CH, 'm.put("senha_hash", u.senhaHash());', 'm.put("senha_hash", "***");')]),

    # ---- SEC-013
    Caso("SEC-013", "sec013-exige-sessao-ativa", "alternativa", "corrigida", car=True, edits=[(AUTH, "where s.token = ? and s.expira_em > ? and u.ativo", "where s.token = ? and s.ativa and s.expira_em > ? and u.ativo")]),
    Caso("SEC-013", "sec013-apaga-a-sessao", "alternativa", "corrigida", car=True, edits=[(AUTH, 'tx.update("update sessao set ativa = false where token = ?", token)', 'tx.update("delete from sessao where token = ?", token)')]),
    Caso("SEC-013", "sec013-ativa-invertido", "mutante", "nao_corrigida", edits=[(AUTH, "where s.token = ? and s.expira_em > ? and u.ativo", "where s.token = ? and not s.ativa and s.expira_em > ? and u.ativo")]),
]
