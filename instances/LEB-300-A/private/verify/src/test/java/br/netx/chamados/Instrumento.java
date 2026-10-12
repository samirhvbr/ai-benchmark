package br.netx.chamados;

import javax.sql.DataSource;
import java.lang.reflect.InvocationHandler;
import java.lang.reflect.InvocationTargetException;
import java.lang.reflect.Method;
import java.lang.reflect.Proxy;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.atomic.AtomicLong;

/**
 * Decora o DataSource que a aplicação recebe, sem tocar no código dela: conta comandos e linhas consumidas, guarda os SELECT com os argumentos,
 * e deixa o teste interferir antes de cada comando (parar num ponto, forçar um entrelaçamento, falhar no commit).
 * Só observa o que passa pela interface JDBC: o que o servidor devolve e a aplicação não lê não aparece.
 */
final class Instrumento {
    interface Gancho {
        /** Chamado antes de cada comando executar, na thread que o executa. Pode lançar para simular uma falha. */
        void antes(Conexao conexao, String sql, List<Object> args) throws SQLException;
    }

    interface AoCommitar {
        void antes(Conexao conexao) throws SQLException;
    }

    /** Uma conexão da aplicação, com o identificador do processo no servidor. */
    static final class Conexao {
        final long id;
        volatile int pid = -1;
        volatile boolean aberta = true;
        volatile boolean chegou;

        Conexao(long id) {
            this.id = id;
        }
    }

    private final DataSource base;
    private final AtomicLong sequencia = new AtomicLong();
    final AtomicLong comandos = new AtomicLong();
    final AtomicLong linhas = new AtomicLong();
    final List<String> selects = Collections.synchronizedList(new ArrayList<>());
    /** Cada comando na ordem em que foi enviado: tipo (L leitura, E escrita), tabela e argumentos. */
    final List<String[]> registro = Collections.synchronizedList(new ArrayList<>());
    final List<Conexao> conexoes = Collections.synchronizedList(new ArrayList<>());
    volatile Gancho gancho;
    volatile AoCommitar aoCommitar;

    Instrumento(DataSource base) {
        this.base = base;
    }

    void zerar() {
        comandos.set(0);
        linhas.set(0);
        selects.clear();
        registro.clear();
    }

    /** Leituras repetidas: a mesma tabela lida com os mesmos argumentos sem nenhuma escrita nessa tabela entre as duas. */
    List<String> leiturasRepetidas() {
        java.util.Map<String, Integer> vistas = new TreeMap<>();
        List<String> repetidas = new ArrayList<>();
        synchronized (registro) {
            for (String[] r : registro) {
                if (r[0].equals("E")) {
                    vistas.keySet().removeIf(k -> k.startsWith(r[1] + " "));
                } else if (vistas.merge(r[1] + " " + r[2], 1, Integer::sum) == 2) {
                    repetidas.add(r[1] + " " + r[2]);
                }
            }
        }
        return repetidas;
    }

    /** O DataSource decorado, para passar a App.create. */
    DataSource dataSource() {
        return (DataSource) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {DataSource.class}, (p, m, a) -> {
            Object r = chamar(base, m, a);
            return "getConnection".equals(m.getName()) ? conexao((Connection) r) : r;
        });
    }

    /** Comandos agrupados por texto, para ver o que se repete. */
    Map<String, Integer> repeticoesDeSelect() {
        Map<String, Integer> contagem = new TreeMap<>();
        synchronized (selects) {
            for (String s : selects) contagem.merge(s, 1, Integer::sum);
        }
        return contagem;
    }

    private Connection conexao(Connection real) throws SQLException {
        Conexao c = new Conexao(sequencia.incrementAndGet());
        try (Statement st = real.createStatement(); ResultSet rs = st.executeQuery("select pg_backend_pid()")) {
            rs.next();
            c.pid = rs.getInt(1);
        }
        conexoes.add(c);
        return (Connection) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {Connection.class}, (p, m, a) -> {
            switch (m.getName()) {
                case "prepareStatement" -> {
                    return preparado((PreparedStatement) chamar(real, m, a), c, (String) a[0]);
                }
                case "createStatement" -> {
                    return simples((Statement) chamar(real, m, a), c);
                }
                case "commit" -> {
                    AoCommitar g = aoCommitar;
                    if (g != null) g.antes(c);
                    return chamar(real, m, a);
                }
                case "close" -> {
                    c.aberta = false;
                    return chamar(real, m, a);
                }
                default -> {
                    return chamar(real, m, a);
                }
            }
        });
    }

    private PreparedStatement preparado(PreparedStatement real, Conexao c, String sql) {
        Map<Integer, Object> args = new TreeMap<>();
        return (PreparedStatement) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {PreparedStatement.class}, (p, m, a) -> {
            String nome = m.getName();
            if (nome.startsWith("set") && a != null && a.length >= 2 && a[0] instanceof Integer i) args.put(i, "setNull".equals(nome) ? null : a[1]);
            if (("executeQuery".equals(nome) || "executeUpdate".equals(nome) || "execute".equals(nome)) && (a == null || a.length == 0)) {
                antes(c, sql, new ArrayList<>(args.values()));
                Object r = chamar(real, m, a);
                return r instanceof ResultSet rs ? lido(rs) : r;
            }
            return chamar(real, m, a);
        });
    }

    private Statement simples(Statement real, Conexao c) {
        return (Statement) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {Statement.class}, (p, m, a) -> {
            String nome = m.getName();
            if (("executeQuery".equals(nome) || "executeUpdate".equals(nome) || "execute".equals(nome)) && a != null && a.length >= 1 && a[0] instanceof String sql) {
                antes(c, sql, List.of());
                Object r = chamar(real, m, a);
                return r instanceof ResultSet rs ? lido(rs) : r;
            }
            return chamar(real, m, a);
        });
    }

    private void antes(Conexao c, String sql, List<Object> args) throws SQLException {
        comandos.incrementAndGet();
        String baixo = sql.stripLeading().toLowerCase();
        if (baixo.startsWith("select")) selects.add(sql.strip() + " | " + args);
        java.util.regex.Matcher m = java.util.regex.Pattern.compile(baixo.startsWith("select") ? "\\bfrom\\s+(\\w+)" : "^(?:update|insert\\s+into|delete\\s+from)\\s+(\\w+)").matcher(baixo);
        if (m.find()) registro.add(new String[] {baixo.startsWith("select") ? "L" : "E", m.group(1), String.valueOf(args)});
        Gancho g = gancho;
        if (g != null) g.antes(c, sql, args);
    }

    private ResultSet lido(ResultSet real) {
        return (ResultSet) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {ResultSet.class}, (p, m, a) -> {
            Object r = chamar(real, m, a);
            if ("next".equals(m.getName()) && Boolean.TRUE.equals(r)) linhas.incrementAndGet();
            return r;
        });
    }

    private static Object chamar(Object alvo, Method m, Object[] a) throws Throwable {
        try {
            return m.invoke(alvo, a);
        } catch (InvocationTargetException e) {
            throw e.getCause();
        }
    }
}
