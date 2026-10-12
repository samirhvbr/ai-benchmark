package br.netx.chamados.db;

import javax.sql.DataSource;
import java.sql.Array;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Types;
import java.time.Instant;
import java.time.LocalTime;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;

/** Acesso ao banco: uma transação por unidade de trabalho, sobre o DataSource recebido na composição. */
public final class Db {
    public Db(DataSource ds) {
        Contexto.definir(ds);
    }

    @FunctionalInterface
    public interface Work<T> {
        T run(Tx tx);
    }

    @FunctionalInterface
    public interface Mapper<T> {
        T map(ResultSet rs) throws SQLException;
    }

    public static final class DbException extends RuntimeException {
        public DbException(Throwable causa) {
            super(causa.getMessage());
        }
    }

    /** Executa o trabalho numa transação; confirma se terminar bem e desfaz se lançar. */
    public <T> T tx(Work<T> work) {
        try (Connection c = Contexto.dataSource().getConnection()) {
            c.setAutoCommit(false);
            try {
                T resultado = work.run(new Tx(c));
                c.commit();
                return resultado;
            } catch (RuntimeException | Error e) {
                desfazer(c, e);
                throw e;
            }
        } catch (SQLException e) {
            throw new DbException(e);
        }
    }

    private static void desfazer(Connection c, Throwable causa) {
        try {
            c.rollback();
        } catch (SQLException e) {
            causa.addSuppressed(e);
        }
    }

    public static final class Tx {
        private final Connection c;

        Tx(Connection c) {
            this.c = c;
        }

        public Connection connection() {
            return c;
        }

        public <T> List<T> list(String sql, Mapper<T> mapper, Object... args) {
            try (PreparedStatement ps = preparar(sql, args); ResultSet rs = ps.executeQuery()) {
                List<T> out = new ArrayList<>();
                while (rs.next()) out.add(mapper.map(rs));
                return out;
            } catch (SQLException e) {
                throw new DbException(e);
            }
        }

        public <T> Optional<T> one(String sql, Mapper<T> mapper, Object... args) {
            List<T> linhas = list(sql, mapper, args);
            return linhas.isEmpty() ? Optional.empty() : Optional.of(linhas.get(0));
        }

        public long count(String sql, Object... args) {
            return one(sql, rs -> rs.getLong(1), args).orElse(0L);
        }

        public int update(String sql, Object... args) {
            try (PreparedStatement ps = preparar(sql, args)) {
                return ps.executeUpdate();
            } catch (SQLException e) {
                throw new DbException(e);
            }
        }

        /** O comando deve terminar em {@code returning id}. */
        public long insert(String sql, Object... args) {
            return one(sql, rs -> rs.getLong(1), args).orElseThrow(() -> new DbException(new SQLException("insert sem id")));
        }

        private PreparedStatement preparar(String sql, Object[] args) throws SQLException {
            PreparedStatement ps = c.prepareStatement(sql);
            try {
                for (int i = 0; i < args.length; i++) ligar(ps, i + 1, args[i]);
            } catch (SQLException | RuntimeException e) {
                ps.close();
                throw e;
            }
            return ps;
        }

        private void ligar(PreparedStatement ps, int i, Object v) throws SQLException {
            if (v == null) ps.setNull(i, Types.NULL);
            else if (v instanceof Instant t) ps.setObject(i, OffsetDateTime.ofInstant(t, ZoneOffset.UTC));
            else if (v instanceof LocalTime t) ps.setObject(i, t);
            else if (v instanceof Enum<?> e) ps.setString(i, e.name());
            else if (v instanceof long[] ids) {
                Long[] boxed = new Long[ids.length];
                for (int k = 0; k < ids.length; k++) boxed[k] = ids[k];
                Array a = c.createArrayOf("bigint", boxed);
                ps.setArray(i, a);
            } else ps.setObject(i, v);
        }
    }

    public static Instant instante(ResultSet rs, String coluna) throws SQLException {
        OffsetDateTime t = rs.getObject(coluna, OffsetDateTime.class);
        return t == null ? null : t.toInstant();
    }

    public static Long longOuNulo(ResultSet rs, String coluna) throws SQLException {
        long v = rs.getLong(coluna);
        return rs.wasNull() ? null : v;
    }
}
