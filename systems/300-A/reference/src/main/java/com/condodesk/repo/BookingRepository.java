package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.domain.Model.Booking;
import com.condodesk.domain.Model.BookingStatus;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import java.util.Set;

public final class BookingRepository {
    private static final String BOOKING = "select b.id, b.area_id, a.name as area_name, a.building_id, b.unit_id, u.label as unit_label, "
            + "b.user_id, b.starts_at, b.ends_at, b.guests, b.status from booking b join common_area a on a.id = b.area_id "
            + "join unit u on u.id = b.unit_id";

    /** Whether an active booking of the area overlaps the interval. */
    public boolean overlaps(Connection c, long areaId, Instant start, Instant end) throws SQLException {
        return Db.one(c, "select exists (select 1 from booking where area_id = ? and status <> 'CANCELLED' and starts_at < ? and ends_at > ?) as taken",
                rs -> rs.getBoolean("taken"), areaId, end, start).orElse(false);
    }

    /** How many active bookings of the unit start after the instant. */
    public int upcoming(Connection c, long unitId, Instant after) throws SQLException {
        return Db.one(c, "select count(*) as n from booking where unit_id = ? and status <> 'CANCELLED' and starts_at > ?",
                rs -> rs.getInt("n"), unitId, after).orElse(0);
    }

    public long insert(Connection c, long areaId, long unitId, long userId, Instant start, Instant end, int guests, BookingStatus status,
                       Instant now) throws SQLException {
        return Db.insert(c, "insert into booking (area_id, unit_id, user_id, starts_at, ends_at, guests, status, created_at) values (?, ?, ?, ?, ?, ?, ?, ?)",
                areaId, unitId, userId, start, end, guests, status, now);
    }

    public Optional<Booking> byId(Connection c, long id) throws SQLException {
        return Db.one(c, BOOKING + " where b.id = ?", BookingRepository::booking, id);
    }

    public Optional<Booking> lock(Connection c, long id) throws SQLException {
        return Db.one(c, BOOKING + " where b.id = ? for update of b", BookingRepository::booking, id);
    }

    public void setStatus(Connection c, long id, BookingStatus status) throws SQLException {
        Db.update(c, "update booking set status = ? where id = ?", status, id);
    }

    /** The bookings of a building (or of the given units) that end after the instant, by start. */
    public List<Booking> list(Connection c, Long buildingId, Set<Long> unitIds, Instant endingAfter, int limit) throws SQLException {
        StringBuilder where = new StringBuilder(" where b.ends_at > ?");
        List<Object> args = new ArrayList<>(List.of(endingAfter));
        if (buildingId != null) {
            where.append(" and a.building_id = ?");
            args.add(buildingId);
        }
        if (unitIds != null) {
            where.append(" and b.unit_id = any(?)");
            args.add(Db.ids(unitIds));
        }
        args.add(limit);
        return Db.list(c, BOOKING + where + " order by b.starts_at, b.id limit ?", BookingRepository::booking, args.toArray());
    }

    private static Booking booking(ResultSet rs) throws SQLException {
        return new Booking(rs.getLong("id"), rs.getLong("area_id"), rs.getString("area_name"), rs.getLong("building_id"), rs.getLong("unit_id"),
                rs.getString("unit_label"), rs.getLong("user_id"), Db.instant(rs, "starts_at"), Db.instant(rs, "ends_at"), rs.getInt("guests"),
                BookingStatus.valueOf(rs.getString("status")));
    }
}
