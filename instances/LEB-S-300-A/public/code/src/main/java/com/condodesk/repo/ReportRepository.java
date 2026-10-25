package com.condodesk.repo;

import com.condodesk.db.Db;

import java.math.BigDecimal;
import java.sql.Connection;
import java.sql.SQLException;
import java.time.Instant;
import java.time.YearMonth;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** The aggregate queries behind the reports and dashboards. */
public final class ReportRepository {

    public record MonthTotals(YearMonth month, BigDecimal billed, BigDecimal settled, BigDecimal received) {}

    public record BuildingTotals(long id, String name, int units, int openInvoices) {}

    /** Per billed month of the year: the invoices' total, the total of those settled, and the money received for them. */
    public List<MonthTotals> monthTotals(Connection c, long buildingId, int year) throws SQLException {
        return Db.list(c, "select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, "
                        + "coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id "
                        + "left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id "
                        + "where u.building_id = ? and i.month like ? group by i.month order by i.month",
                rs -> new MonthTotals(YearMonth.parse(rs.getString("month")), rs.getBigDecimal("billed"), rs.getBigDecimal("settled"),
                        rs.getBigDecimal("received")),
                buildingId, String.format("%04d-%%", year));
    }

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

    /** The owners' names of each unit, joined with a comma. */
    public Map<Long, String> ownersByUnit(Connection c, Set<Long> unitIds) throws SQLException {
        Map<Long, String> out = new HashMap<>();
        if (unitIds.isEmpty()) return out;
        String ids = unitIds.stream().map(String::valueOf).collect(java.util.stream.Collectors.joining(","));
        for (Map.Entry<Long, String> row : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r "
                        + "join app_user u on u.id = r.user_id where r.unit_id in (" + ids + ") and r.kind = 'OWNER' group by r.unit_id",
                rs -> Map.entry(rs.getLong("unit_id"), rs.getString("names")))) {
            out.put(row.getKey(), row.getValue());
        }
        return out;
    }
}
