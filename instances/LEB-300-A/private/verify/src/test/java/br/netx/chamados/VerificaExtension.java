package br.netx.chamados;

import org.junit.jupiter.api.extension.ExtensionContext;
import org.junit.jupiter.api.extension.TestExecutionExceptionHandler;

/**
 * Quando um método de verificação termina com exceção, de quem é a culpa? Só uma situação vira veredito: o sistema avaliado respondeu de um jeito que
 * impede o cenário (o login que o teste faz não funciona, a abertura de um chamado devolve erro). Isso é o sistema quebrado, e os veredictos do método
 * viram "não corrigida". Qualquer outra exceção (banco, rede, o cliente HTTP, o próprio código do verificador, um corpo que não é JSON) tem origem que
 * este código não sabe estabelecer: a exceção segue adiante, nenhum veredito é escrito e o relatório diz "sem evidência mecânica".
 */
final class VerificaExtension implements TestExecutionExceptionHandler {
    @Override
    public void handleTestExecutionException(ExtensionContext contexto, Throwable t) throws Throwable {
        Verifica v = contexto.getRequiredTestMethod().getAnnotation(Verifica.class);
        if (v == null || v.value().length == 0 || !oSistemaRespondeuErroAoCenario(t)) throw t;
        for (String id : v.value()) {
            if (!Resultado.existe(id)) Resultado.falhou(id, t);
        }
    }

    /** Os ajudantes do cenário avisam assim quando o sistema respondeu com um erro definido a um passo de preparação. */
    static boolean oSistemaRespondeuErroAoCenario(Throwable t) {
        if (!(t instanceof IllegalStateException) || t.getCause() != null) return false;
        String m = String.valueOf(t.getMessage());
        return m.matches("(?s)^(login de .* falhou: .*|abertura falhou: .*|ordem falhou: .*|\\S+ respondeu \\d{3}: .*)$");
    }
}
