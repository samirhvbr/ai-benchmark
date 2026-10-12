package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.domain.Model.Role;
import com.condodesk.domain.Model.User;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import java.util.Set;

public final class UserRepository {
    private static final String COLUMNS = "id, email, name, role, building_id, password_hash, active";

    /** A user as the admin's user list shows it. */
    public record Listed(long id, String email, String name, Role role, Long buildingId, boolean active, String units) {}

    public Optional<User> byEmail(Connection c, String email) throws SQLException {
        return Db.one(c, "select " + COLUMNS + " from app_user where email = ?", UserRepository::user, normalize(email));
    }

    public Optional<User> byId(Connection c, long id) throws SQLException {
        return Db.one(c, "select " + COLUMNS + " from app_user where id = ?", UserRepository::user, id);
    }

    public Set<Long> unitIds(Connection c, long userId) throws SQLException {
        return new HashSet<>(Db.list(c, "select unit_id from residency where user_id = ?", rs -> rs.getLong("unit_id"), userId));
    }

    public long create(Connection c, String email, String name, Role role, Long buildingId, String passwordHash, Instant now) throws SQLException {
        return Db.insert(c, "insert into app_user (email, name, role, building_id, password_hash, created_at) values (?, ?, ?, ?, ?, ?)",
                normalize(email), name, role, buildingId, passwordHash, now);
    }

    public void addResidency(Connection c, long userId, long unitId, String kind) throws SQLException {
        Db.update(c, "insert into residency (user_id, unit_id, kind) values (?, ?, ?)", userId, unitId, kind);
    }

    public void updatePassword(Connection c, long userId, String passwordHash) throws SQLException {
        Db.update(c, "update app_user set password_hash = ? where id = ?", passwordHash, userId);
    }

    /** The users of a building, or every user when the building is null, with the labels of their units. */
    public List<Listed> list(Connection c, Long buildingId) throws SQLException {
        return Db.list(c, "select u.id, u.email, u.name, u.role, u.building_id, u.active, "
                        + "coalesce(string_agg(un.label, ', ' order by un.label), '') as units "
                        + "from app_user u left join residency r on r.user_id = u.id left join unit un on un.id = r.unit_id "
                        + "where (?::bigint is null or u.building_id = ?) group by u.id order by u.name, u.id",
                rs -> new Listed(rs.getLong("id"), rs.getString("email"), rs.getString("name"), Role.valueOf(rs.getString("role")),
                        Db.nullableLong(rs, "building_id"), rs.getBoolean("active"), rs.getString("units")),
                buildingId, buildingId);
    }

    public static String normalize(String email) {
        return email.trim().toLowerCase(Locale.ROOT);
    }

    private static User user(ResultSet rs) throws SQLException {
        return new User(rs.getLong("id"), rs.getString("email"), rs.getString("name"), Role.valueOf(rs.getString("role")),
                Db.nullableLong(rs, "building_id"), rs.getString("password_hash"), rs.getBoolean("active"));
    }
}
