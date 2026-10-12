package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.CommonArea;
import com.condodesk.domain.Model.Unit;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.ZoneId;
import java.util.List;
import java.util.Optional;

/** Buildings, their units and their common areas. */
public final class BuildingRepository {
    private static final String AREA = "select id, building_id, name, opens, closes, max_hours, capacity, fee from common_area";

    public Optional<Building> byId(Connection c, long id) throws SQLException {
        return Db.one(c, "select id, name, address, time_zone, due_day, late_fee_pct, monthly_interest_pct from building where id = ?",
                BuildingRepository::building, id);
    }

    public List<Building> all(Connection c) throws SQLException {
        return Db.list(c, "select id, name, address, time_zone, due_day, late_fee_pct, monthly_interest_pct from building order by name, id",
                BuildingRepository::building);
    }

    public List<Unit> units(Connection c, long buildingId) throws SQLException {
        return Db.list(c, "select id, building_id, label, ideal_fraction from unit where building_id = ? order by label",
                BuildingRepository::unit, buildingId);
    }

    public Optional<Unit> unit(Connection c, long unitId) throws SQLException {
        return Db.one(c, "select id, building_id, label, ideal_fraction from unit where id = ?", BuildingRepository::unit, unitId);
    }

    public List<CommonArea> areas(Connection c, long buildingId) throws SQLException {
        return Db.list(c, AREA + " where building_id = ? order by name", BuildingRepository::area, buildingId);
    }

    public Optional<CommonArea> area(Connection c, long areaId) throws SQLException {
        return Db.one(c, AREA + " where id = ?", BuildingRepository::area, areaId);
    }

    /** Locks the area's row until the transaction ends, so that bookings of one area are decided one at a time. */
    public Optional<CommonArea> lockArea(Connection c, long areaId) throws SQLException {
        return Db.one(c, AREA + " where id = ? for update", BuildingRepository::area, areaId);
    }

    /** Locks the unit's row until the transaction ends. */
    public void lockUnit(Connection c, long unitId) throws SQLException {
        Db.one(c, "select id from unit where id = ? for update", rs -> rs.getLong("id"), unitId);
    }

    private static Building building(ResultSet rs) throws SQLException {
        return new Building(rs.getLong("id"), rs.getString("name"), rs.getString("address"), ZoneId.of(rs.getString("time_zone")),
                rs.getInt("due_day"), rs.getBigDecimal("late_fee_pct"), rs.getBigDecimal("monthly_interest_pct"));
    }

    private static Unit unit(ResultSet rs) throws SQLException {
        return new Unit(rs.getLong("id"), rs.getLong("building_id"), rs.getString("label"), rs.getBigDecimal("ideal_fraction"));
    }

    private static CommonArea area(ResultSet rs) throws SQLException {
        return new CommonArea(rs.getLong("id"), rs.getLong("building_id"), rs.getString("name"), rs.getTime("opens").toLocalTime(),
                rs.getTime("closes").toLocalTime(), rs.getInt("max_hours"), rs.getInt("capacity"), rs.getBigDecimal("fee"));
    }
}
