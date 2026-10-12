package br.netx.chamados.http;

import br.netx.chamados.auth.Auth;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Principal;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Servidor HTTP do JDK: roteamento, autenticação por token, leitura do corpo e o formato dos erros. */
public final class Servidor {
    static final ObjectMapper JSON = new ObjectMapper();

    @FunctionalInterface
    public interface Handler {
        Resp tratar(Req req) throws Exception;
    }

    public record Resp(int status, String tipo, String corpo) {
        public static Resp json(int status, Object o) {
            try {
                return new Resp(status, "application/json; charset=utf-8", JSON.writeValueAsString(o));
            } catch (JsonProcessingException e) {
                throw new IllegalStateException(e);
            }
        }

        public static Resp bruto(int status, String json) {
            return new Resp(status, "application/json; charset=utf-8", json);
        }

        public static Resp vazio(int status) {
            return new Resp(status, null, "");
        }
    }

    private record Rota(String metodo, Pattern padrao, List<String> nomes, boolean publica, Handler handler) {}

    private final Auth auth;
    private final List<Rota> rotas = new ArrayList<>();
    private HttpServer servidor;

    public Servidor(Auth auth) {
        this.auth = auth;
    }

    public void rota(String metodo, String caminho, boolean publica, Handler handler) {
        List<String> nomes = new ArrayList<>();
        Matcher m = Pattern.compile("\\{([a-z_]+)}").matcher(caminho);
        StringBuilder regex = new StringBuilder("^");
        int fim = 0;
        while (m.find()) {
            regex.append(Pattern.quote(caminho.substring(fim, m.start()))).append("([^/]+)");
            nomes.add(m.group(1));
            fim = m.end();
        }
        regex.append(Pattern.quote(caminho.substring(fim))).append("$");
        rotas.add(new Rota(metodo, Pattern.compile(regex.toString()), nomes, publica, handler));
    }

    public int iniciar(int porta) {
        try {
            servidor = HttpServer.create(new InetSocketAddress(porta), 0);
        } catch (IOException e) {
            throw new IllegalStateException("não foi possível abrir a porta " + porta, e);
        }
        // servidor.setExecutor(Executors.newCachedThreadPool());
        // servidor.setExecutor(null);
        // System.out.println("porta " + servidor.getAddress().getPort());
        // servidor.createContext("/health", ex -> ex.sendResponseHeaders(200, -1));
        servidor.setExecutor(Executors.newFixedThreadPool(8));
        servidor.createContext("/", this::atender);
        servidor.start();
        return servidor.getAddress().getPort();
    }

    public void parar() {
        if (servidor != null) servidor.stop(0);
    }

    private void atender(HttpExchange ex) throws IOException {
        Resp resp;
        try {
            resp = despachar(ex);
        } catch (Erro e) {
            resp = erro(e.status, e.codigo, e.getMessage());
        } catch (Exception e) {
            e.printStackTrace();
            resp = erro(500, "erro_interno", "erro interno");
        }
        byte[] bytes = resp.corpo().getBytes(StandardCharsets.UTF_8);
        if (resp.tipo() != null) ex.getResponseHeaders().set("Content-Type", resp.tipo());
        ex.sendResponseHeaders(resp.status(), resp.status() == 204 ? -1 : bytes.length);
        if (resp.status() != 204) {
            try (OutputStream out = ex.getResponseBody()) {
                out.write(bytes);
            }
        }
        ex.close();
    }

    private Resp despachar(HttpExchange ex) throws Exception {
        String metodo = ex.getRequestMethod();
        String caminho = ex.getRequestURI().getPath();
        for (Rota r : rotas) {
            Matcher m = r.padrao().matcher(caminho);
            if (!r.metodo().equals(metodo) || !m.matches()) continue;
            Map<String, String> vars = new LinkedHashMap<>();
            for (int i = 0; i < r.nomes().size(); i++) vars.put(r.nomes().get(i), m.group(i + 1));
            String token = token(ex);
            Principal p = null;
            if (!r.publica()) p = auth.validar(token == null ? "" : token).orElseThrow(Erro::naoAutenticado);
            byte[] bruto = ex.getRequestBody().readAllBytes();
            JsonNode corpo = null;
            if (bruto.length > 0) {
                try {
                    corpo = JSON.readTree(bruto);
                } catch (IOException e) {
                    throw Erro.malformado("o corpo não é um JSON válido");
                }
            }
            return r.handler().tratar(new Req(metodo, caminho, vars, consulta(ex.getRequestURI().getRawQuery()), corpo, ex, p, token));
        }
        throw Erro.naoEncontrado();
    }

    private static String token(HttpExchange ex) {
        String h = ex.getRequestHeaders().getFirst("Authorization");
        return h != null && h.startsWith("Bearer ") ? h.substring(7).trim() : null;
    }

    private static Map<String, String> consulta(String bruta) {
        Map<String, String> q = new LinkedHashMap<>();
        if (bruta == null || bruta.isEmpty()) return q;
        for (String par : bruta.split("&")) {
            int i = par.indexOf('=');
            String k = URLDecoder.decode(i < 0 ? par : par.substring(0, i), StandardCharsets.UTF_8);
            q.put(k, i < 0 ? "" : URLDecoder.decode(par.substring(i + 1), StandardCharsets.UTF_8));
        }
        return q;
    }

    static Resp erro(int status, String codigo, String mensagem) {
        Map<String, Object> e = new LinkedHashMap<>();
        e.put("codigo", codigo);
        e.put("mensagem", mensagem);
        return Resp.json(status, Map.of("erro", e));
    }

    static String iso(Instant t) {
        return t == null ? null : t.toString();
    }

    /** O pedido já lido: variáveis do caminho, consulta, corpo JSON e quem chamou. */
    public static final class Req {
        private final String metodo;
        private final String caminho;
        private final Map<String, String> vars;
        private final Map<String, String> consulta;
        private final JsonNode corpo;
        private final HttpExchange ex;
        private final Principal principal;
        private final String token;

        Req(String metodo, String caminho, Map<String, String> vars, Map<String, String> consulta, JsonNode corpo, HttpExchange ex, Principal principal, String token) {
            this.metodo = metodo;
            this.caminho = caminho;
            this.vars = vars;
            this.consulta = consulta;
            this.corpo = corpo;
            this.ex = ex;
            this.principal = principal;
            this.token = token;
        }

        public String metodo() { return metodo; }
        public String caminho() { return caminho; }
        public Principal principal() { return principal; }
        public String token() { return token; }
        public String cabecalho(String nome) { return ex.getRequestHeaders().getFirst(nome); }
        public String consulta(String nome) { return consulta.get(nome); }
        public JsonNode corpo() { return corpo; }

        public long id() {
            try {
                return Long.parseLong(vars.get("id"));
            } catch (NumberFormatException e) {
                throw Erro.naoEncontrado();
            }
        }

        public int pagina() {
            return inteiro("pagina", 1, 1, Integer.MAX_VALUE);
        }

        public int tamanho() {
            return inteiro("tamanho", 20, 1, 100);
        }

        private int inteiro(String nome, int padrao, int min, int max) {
            String v = consulta.get(nome);
            if (v == null) return padrao;
            try {
                int n = Integer.parseInt(v);
                if (n < min || n > max) throw new NumberFormatException();
                return n;
            } catch (NumberFormatException e) {
                throw Erro.invalido(nome + " deve ser um inteiro entre " + min + " e " + max);
            }
        }

        public Long empresaIdPedida() {
            String v = consulta.get("empresa_id");
            if (v == null) return null;
            try {
                return Long.parseLong(v);
            } catch (NumberFormatException e) {
                throw Erro.invalido("empresa_id deve ser um número");
            }
        }

        public JsonNode objeto() {
            if (corpo == null || !corpo.isObject()) throw Erro.malformado("o corpo deve ser um objeto JSON");
            return corpo;
        }

        public String texto(String campo) {
            JsonNode n = objeto().get(campo);
            if (n == null || !n.isTextual() || n.asText().isBlank()) throw Erro.invalido(campo + " é obrigatório");
            return n.asText();
        }

        public String textoOuPadrao(String campo, String padrao) {
            JsonNode n = objeto().get(campo);
            if (n == null || n.isNull()) return padrao;
            if (!n.isTextual()) throw Erro.invalido(campo + " deve ser texto");
            return n.asText();
        }

        public long longo(String campo) {
            JsonNode n = objeto().get(campo);
            if (n == null || !n.isIntegralNumber()) throw Erro.invalido(campo + " deve ser um número inteiro");
            return n.asLong();
        }

        public Instant instante(String campo) {
            try {
                return Instant.parse(texto(campo));
            } catch (java.time.format.DateTimeParseException e) {
                throw Erro.invalido(campo + " deve ser um instante ISO-8601 em UTC");
            }
        }

        public <E extends Enum<E>> E enumeracao(String campo, Class<E> tipo) {
            return converter(campo, texto(campo), tipo);
        }

        public <E extends Enum<E>> E enumeracaoOuPadrao(String campo, Class<E> tipo, E padrao) {
            JsonNode n = objeto().get(campo);
            return n == null || n.isNull() ? padrao : converter(campo, n.asText(), tipo);
        }

        public <E extends Enum<E>> E enumeracaoDaConsulta(String campo, Class<E> tipo) {
            String v = consulta.get(campo);
            return v == null ? null : converter(campo, v, tipo);
        }

        private static <E extends Enum<E>> E converter(String campo, String valor, Class<E> tipo) {
            try {
                return Enum.valueOf(tipo, valor);
            } catch (IllegalArgumentException e) {
                throw Erro.invalido(campo + " inválido: " + valor);
            }
        }
    }
}
