package com.condodesk.db;

import javax.sql.DataSource;
import java.math.BigDecimal;
import java.sql.Connection;
import java.sql.Date;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.sql.Time;
import java.sql.Timestamp;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalTime;
import java.util.ArrayList;
import java.util.Collection;
import java.util.List;
import java.util.Optional;

/** JDBC access: one connection per unit of work, and small helpers for the statements the repositories run. */
public final class Db {

    @FunctionalInterface
    public interface Work<T> {
        T run(Connection c) throws SQLException;
    }

    @FunctionalInterface
    public interface Row<T> {
        T map(ResultSet rs) throws SQLException;
    }

    private final DataSource dataSource;

    public Db(DataSource dataSource) {
        this.dataSource = dataSource;
    }

    /** Runs the work in one transaction: committed when it returns, rolled back when it throws. */
    public <T> T tx(Work<T> work) {
        try (Connection c = dataSource.getConnection()) {
            c.setAutoCommit(false);
            try {
                T result = work.run(c);
                c.commit();
                return result;
            } catch (SQLException | RuntimeException e) {
                c.rollback();
                throw e;
            }
        } catch (SQLException e) {
            throw new DataAccessException(e);
        }
    }

    /** Runs read-only work on a connection in autocommit mode. */
    public <T> T read(Work<T> work) {
        try (Connection c = dataSource.getConnection()) {
            return work.run(c);
        } catch (SQLException e) {
            throw new DataAccessException(e);
        }
    }

    public static <T> List<T> list(Connection c, String sql, Row<T> row, Object... args) throws SQLException {
        try (PreparedStatement ps = c.prepareStatement(sql)) {
            bind(ps, args);
            try (ResultSet rs = ps.executeQuery()) {
                List<T> out = new ArrayList<>();
                while (rs.next()) out.add(row.map(rs));
                return out;
            }
        }
    }

    public static <T> Optional<T> one(Connection c, String sql, Row<T> row, Object... args) throws SQLException {
        List<T> rows = list(c, sql, row, args);
        return rows.isEmpty() ? Optional.empty() : Optional.of(rows.get(0));
    }

    public static int update(Connection c, String sql, Object... args) throws SQLException {
        try (PreparedStatement ps = c.prepareStatement(sql)) {
            bind(ps, args);
            return ps.executeUpdate();
        }
    }

    /** Runs an insert and returns the generated id. */
    public static long insert(Connection c, String sql, Object... args) throws SQLException {
        try (PreparedStatement ps = c.prepareStatement(sql, Statement.RETURN_GENERATED_KEYS)) {
            bind(ps, args);
            ps.executeUpdate();
            try (ResultSet keys = ps.getGeneratedKeys()) {
                keys.next();
                return keys.getLong("id");
            }
        }
    }

    /** A set of ids as one argument, for {@code = any(?)}. Typed as Object so that it never spreads into varargs. */
    public static Object ids(Collection<Long> ids) {
        return ids.toArray(new Long[0]);
    }

    public static Instant instant(ResultSet rs, String column) throws SQLException {
        Timestamp t = rs.getTimestamp(column);
        return t == null ? null : t.toInstant();
    }

    public static LocalDate date(ResultSet rs, String column) throws SQLException {
        Date d = rs.getDate(column);
        return d == null ? null : d.toLocalDate();
    }

    public static Long nullableLong(ResultSet rs, String column) throws SQLException {
        long v = rs.getLong(column);
        return rs.wasNull() ? null : v;
    }

    private static void bind(PreparedStatement ps, Object[] args) throws SQLException {
        for (int i = 0; i < args.length; i++) {
            Object v = args[i];
            if (v instanceof Instant t) ps.setTimestamp(i + 1, Timestamp.from(t));
            else if (v instanceof LocalDate d) ps.setDate(i + 1, Date.valueOf(d));
            else if (v instanceof LocalTime t) ps.setTime(i + 1, Time.valueOf(t));
            else if (v instanceof BigDecimal b) ps.setBigDecimal(i + 1, b);
            else if (v instanceof Enum<?> e) ps.setString(i + 1, e.name());
            else ps.setObject(i + 1, v);
        }
    }

    /** A database failure, unchecked so that services do not have to declare it. */
    public static final class DataAccessException extends RuntimeException {
        public DataAccessException(SQLException cause) {
            super(cause);
        }

        public String sqlState() {
            return ((SQLException) getCause()).getSQLState();
        }

        public boolean isUniqueViolation() {
            return "23505".equals(sqlState());
        }
    }
}
