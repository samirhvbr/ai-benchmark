package com.condodesk.domain;

import com.condodesk.domain.Model.Role;

import java.util.Set;

/** The user behind a request, with the units they live in. Every access check of the services goes through here. */
public record Principal(long userId, String email, String name, Role role, Long buildingId, Set<Long> unitIds) {

    public boolean isAdmin() {
        return role == Role.ADMIN;
    }

    /** Whether this user may act as the manager of the building: its manager, or an admin. */
    public boolean manages(long building) {
        return role == Role.ADMIN || (role == Role.MANAGER && buildingId != null && buildingId == building);
    }

    public boolean livesIn(long unitId) {
        return unitIds.contains(unitId);
    }

    /** Whether this user may see what belongs to a unit: someone who lives there, or whoever manages its building. */
    public boolean canSeeUnit(long building, long unitId) {
        return livesIn(unitId) || manages(building);
    }

    /** Whether this user may see what belongs to a building as a whole: anyone of the building, or an admin. */
    public boolean belongsTo(long building) {
        return role == Role.ADMIN || (buildingId != null && buildingId == building);
    }

    public void requireAdmin() {
        if (!isAdmin()) throw AppException.forbidden();
    }

    public void requireManager(long building) {
        if (!manages(building)) throw AppException.forbidden();
    }
}
