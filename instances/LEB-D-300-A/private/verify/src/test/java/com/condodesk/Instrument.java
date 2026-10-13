package com.condodesk;

import javax.sql.DataSource;
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
 * Wraps the DataSource the application gets, without touching its code: counts the connections it opens and closes, the commands
 * it sends and the rows it reads, and lets a verifier step in before each command (stop at a point, force an interleaving, fail).
 * It sees only what goes through the JDBC interfaces: what the server returns and the application does not read does not show.
 */
final class Instrument {
    interface Hook {
        /** Called before each command runs, on the thread that runs it. May throw to simulate a failure. */
        void before(Conn connection, String sql, List<Object> args) throws SQLException;
    }

    /** One connection of the application, with the id of its server process. */
    static final class Conn {
        final long id;
        volatile int pid = -1;
        volatile boolean open = true;
        volatile boolean arrived;

        Conn(long id) {
            this.id = id;
        }
    }

    private final DataSource base;
    private final AtomicLong sequence = new AtomicLong();
    final AtomicLong commands = new AtomicLong();
    final AtomicLong rows = new AtomicLong();
    final AtomicLong opened = new AtomicLong();
    final AtomicLong closed = new AtomicLong();
    final List<Conn> connections = Collections.synchronizedList(new ArrayList<>());
    volatile Hook hook;

    Instrument(DataSource base) {
        this.base = base;
    }

    void reset() {
        commands.set(0);
        rows.set(0);
        opened.set(0);
        closed.set(0);
    }

    /** The wrapped DataSource, for App.create. */
    DataSource dataSource() {
        return (DataSource) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {DataSource.class}, (p, m, a) -> {
            Object r = call(base, m, a);
            return "getConnection".equals(m.getName()) ? connection((Connection) r) : r;
        });
    }

    private Connection connection(Connection real) throws SQLException {
        Conn c = new Conn(sequence.incrementAndGet());
        try (Statement st = real.createStatement(); ResultSet rs = st.executeQuery("select pg_backend_pid()")) {
            rs.next();
            c.pid = rs.getInt(1);
        }
        connections.add(c);
        opened.incrementAndGet();
        return (Connection) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {Connection.class}, (p, m, a) -> {
            switch (m.getName()) {
                case "prepareStatement" -> {
                    return prepared((PreparedStatement) call(real, m, a), c, (String) a[0]);
                }
                case "createStatement" -> {
                    return plain((Statement) call(real, m, a), c);
                }
                case "close" -> {
                    if (c.open) closed.incrementAndGet();
                    c.open = false;
                    return call(real, m, a);
                }
                default -> {
                    return call(real, m, a);
                }
            }
        });
    }

    private PreparedStatement prepared(PreparedStatement real, Conn c, String sql) {
        Map<Integer, Object> args = new TreeMap<>();
        return (PreparedStatement) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {PreparedStatement.class}, (p, m, a) -> {
            String name = m.getName();
            if (name.startsWith("set") && a != null && a.length >= 2 && a[0] instanceof Integer i) args.put(i, "setNull".equals(name) ? null : a[1]);
            if (name.startsWith("execute") && !name.equals("executeBatch") && (a == null || a.length == 0)) {
                before(c, sql, new ArrayList<>(args.values()));
                Object r = call(real, m, a);
                return r instanceof ResultSet rs ? read(rs) : r;
            }
            if (name.equals("executeBatch")) before(c, sql, List.of());
            if (name.equals("getResultSet") || name.equals("getGeneratedKeys")) {
                Object r = call(real, m, a);
                return r instanceof ResultSet rs ? read(rs) : r;
            }
            return call(real, m, a);
        });
    }

    private Statement plain(Statement real, Conn c) {
        return (Statement) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {Statement.class}, (p, m, a) -> {
            String name = m.getName();
            if (name.startsWith("execute") && a != null && a.length >= 1 && a[0] instanceof String sql) {
                before(c, sql, List.of());
                Object r = call(real, m, a);
                return r instanceof ResultSet rs ? read(rs) : r;
            }
            if (name.equals("getResultSet")) {
                Object r = call(real, m, a);
                return r instanceof ResultSet rs ? read(rs) : r;
            }
            return call(real, m, a);
        });
    }

    private void before(Conn c, String sql, List<Object> args) throws SQLException {
        commands.incrementAndGet();
        Hook h = hook;
        if (h != null) h.before(c, sql, args);
    }

    private ResultSet read(ResultSet real) {
        return (ResultSet) Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {ResultSet.class}, (p, m, a) -> {
            Object r = call(real, m, a);
            if ("next".equals(m.getName()) && Boolean.TRUE.equals(r)) rows.incrementAndGet();
            return r;
        });
    }

    private static Object call(Object target, Method m, Object[] a) throws Throwable {
        try {
            return m.invoke(target, a);
        } catch (InvocationTargetException e) {
            throw e.getCause();
        }
    }
}
