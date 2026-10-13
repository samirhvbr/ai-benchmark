package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.reports.ReportService.BuildingTotals;

import java.sql.Connection;
import java.sql.SQLException;
import java.time.Instant;
import java.util.List;

/** The aggregate queries behind the reports and dashboards. */
public final class ReportRepository {

    public List<BuildingTotals> buildingTotals(Connection c) throws SQLException {
        return Db.list(c, "select b.id, b.name, (select count(*) from unit u where u.building_id = b.id) as units, "
                        + "(select count(*) from invoice i join unit u on u.id = i.unit_id where u.building_id = b.id and i.status = 'OPEN') as open "
                        + "from building b order by b.name, b.id",
                rs -> new BuildingTotals(rs.getLong("id"), rs.getString("name"), rs.getInt("units"), rs.getInt("open")));
    }

    /** Bookings of the building that wait for confirmation and have not started. */
    public int pendingBookings(Connection c, long buildingId, Instant now) throws SQLException {
        return Db.one(c, "select count(*) as n from booking k join common_area a on a.id = k.area_id "
                + "where a.building_id = ? and k.status = 'REQUESTED' and k.starts_at > ?", rs -> rs.getInt("n"), buildingId, now).orElse(0);
    }

    /** The owners' names of a unit, joined with a comma. */
    public String ownersOf(Connection c, long unitId) throws SQLException {
        return Db.one(c, "select string_agg(u.name, ', ' order by u.name) as names from residency r join app_user u on u.id = r.user_id "
                + "where r.unit_id = ? and r.kind = 'OWNER'", rs -> rs.getString("names"), unitId).orElse("");
    }
}
