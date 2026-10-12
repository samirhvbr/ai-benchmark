package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.io.ByteArrayOutputStream;
import java.io.PrintStream;
import java.nio.charset.StandardCharsets;
import java.sql.SQLException;

/** Uma falha interna precisa deixar rastro de quem a causou, sem vazar o motivo para o cliente. */
class V07_DiagnosticoTest extends VBase {

    /** Exceção com nome único: se ele aparecer na saída de erro, a causa original chegou até lá. */
    static final class FalhaInjetadaNoBanco extends SQLException {
        FalhaInjetadaNoBanco() {
            super("banco indisponível");
        }
    }

    @Test
    @Verifica({"BUG-015"})
    void bug015_aCausaDeUmaFalhaInternaApareceNoRegistro() throws Exception {
        String t = token(ATENDENTE_A);
        PrintStream err = System.err, out = System.out;
        ByteArrayOutputStream capturaErr = new ByteArrayOutputStream(), capturaOut = new ByteArrayOutputStream();
        instr.gancho = (c, sql, args) -> { if (sql.toLowerCase().contains("from chamado c")) throw new FalhaInjetadaNoBanco(); };
        R r;
        try {
            System.setErr(new PrintStream(capturaErr, true, StandardCharsets.UTF_8));
            System.setOut(new PrintStream(capturaOut, true, StandardCharsets.UTF_8));
            r = get("/chamados", t);
        } finally {
            System.setErr(err);
            System.setOut(out);
            instr.gancho = null;
        }
        String registro = capturaErr.toString(StandardCharsets.UTF_8) + capturaOut.toString(StandardCharsets.UTF_8);
        boolean cliente = r.status() == 500 && !r.bruto().contains("banco indisponível") && !r.bruto().contains("FalhaInjetadaNoBanco");
        boolean rastro = registro.contains("FalhaInjetadaNoBanco");
        Resultado.c("BUG-015", cliente && rastro, "resposta " + r.status() + (cliente ? " genérica" : " (esperado 500 sem o motivo)") + "; a causa original " + (rastro ? "aparece" : "não aparece") + " no registro da falha");
    }
}
