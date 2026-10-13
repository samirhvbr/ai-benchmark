package com.condodesk.booking;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Booking;
import com.condodesk.domain.Model.BookingStatus;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.CommonArea;
import com.condodesk.domain.Model.Unit;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BookingRepository;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.FinanceRepository;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalTime;
import java.util.List;
import java.util.Optional;

/** Bookings of the common areas. */
public final class BookingService {
    static final int MAX_UPCOMING_PER_UNIT = 2;
    static final int OVERDUE_DAYS_BLOCKING = 30;
    static final Duration CANCEL_NOTICE = Duration.ofHours(48);

    public record BookingRequest(long areaId, long unitId, LocalDate date, LocalTime start, LocalTime end, int guests) {}

    private final Db db;
    private final BuildingRepository buildings;
    private final BookingRepository bookings;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;

    public BookingService(Db db, BuildingRepository buildings, BookingRepository bookings, FinanceRepository finance, AuditLog audit, Clock clock) {
        this.db = db;
        this.buildings = buildings;
        this.bookings = bookings;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
    }

    /**
     * Books an area for a unit. The area's row is locked first, so two requests for the same area are decided one after the
     * other and cannot both pass the overlap check.
     */
    public Booking book(Principal p, BookingRequest r) {
        return db.tx(c -> {
            CommonArea area = buildings.lockArea(c, r.areaId()).filter(a -> p.belongsTo(a.buildingId())).orElseThrow(AppException::notFound);
            Unit unit = buildings.unit(c, r.unitId()).orElseThrow(AppException::notFound);
            if (unit.buildingId() != area.buildingId() || !p.canSeeUnit(unit.buildingId(), unit.id())) throw AppException.forbidden();
            Building b = buildings.byId(c, area.buildingId()).orElseThrow();
            checkHours(area, r);
            Instant start = r.date().atTime(r.start()).atZone(b.zone()).toInstant();
            Instant end = r.date().atTime(r.end()).atZone(b.zone()).toInstant();
            Instant now = clock.instant();
            if (!start.isAfter(now)) throw AppException.invalid("The booking must start in the future.");
            if (finance.hasOpenDueBefore(c, unit.id(), LocalDate.now(clock).minusDays(OVERDUE_DAYS_BLOCKING))) {
                throw AppException.conflict("The unit has an invoice overdue for more than " + OVERDUE_DAYS_BLOCKING + " days.");
            }
            if (bookings.overlaps(c, area.id(), start, end)) throw AppException.conflict("The area is already booked at that time.");
            if (bookings.upcoming(c, unit.id(), now) >= MAX_UPCOMING_PER_UNIT) {
                throw AppException.conflict("A unit may hold at most " + MAX_UPCOMING_PER_UNIT + " upcoming bookings.");
            }
            BookingStatus status = area.fee().signum() > 0 ? BookingStatus.REQUESTED : BookingStatus.CONFIRMED;
            long id = bookings.insert(c, area.id(), unit.id(), p.userId(), start, end, r.guests(), status, now);
            audit.record(c, p.userId(), "booking", area.name() + " for " + unit.label() + " at " + start, now);
            return bookings.byId(c, id).orElseThrow();
        });
    }

    /** Residents cancel up to 48 hours before the start; the building's manager, any time before it. */
    public void cancel(Principal p, long bookingId) {
        db.tx(c -> {
            Optional<Booking> found = bookings.lock(c, bookingId);
            if (found.isPresent() && p.canSeeUnit(found.get().buildingId(), found.get().unitId())) {
                Booking bk = found.get();
                Instant now = clock.instant();
                if (bk.status() != BookingStatus.CANCELLED) {
                    if (bk.startsAt().isAfter(now)) {
                        boolean allowed = true;
                        if (!p.manages(bk.buildingId())) {
                            if (now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                                allowed = false;
                            }
                        }
                        if (allowed) {
                            bookings.setStatus(c, bk.id(), BookingStatus.CANCELLED);
                            audit.record(c, p.userId(), "booking_cancelled", String.valueOf(bk.id()), now);
                            return null;
                        } else {
                            throw AppException.conflict("Bookings can be cancelled up to 48 hours before they start.");
                        }
                    } else {
                        throw AppException.conflict("The booking has already started.");
                    }
                } else {
                    throw AppException.conflict("The booking is already cancelled.");
                }
            } else {
                throw AppException.notFound();
            }
        });
    }

    public void confirm(Principal p, long bookingId) {
        db.tx(c -> {
            Booking bk = bookings.lock(c, bookingId).orElseThrow(AppException::notFound);
            p.requireManager(bk.buildingId());
            if (bk.status() != BookingStatus.REQUESTED) throw AppException.conflict("Only a requested booking can be confirmed.");
            bookings.setStatus(c, bk.id(), BookingStatus.CONFIRMED);
            audit.record(c, p.userId(), "booking_confirmed", String.valueOf(bk.id()), clock.instant());
            return null;
        });
    }

    /** What the bookings page of a building shows. */
    public record Board(Building building, List<Booking> bookings, List<CommonArea> areas) {}

    /** The building's bookings that have not ended (a resident sees their units' only) and its areas. */
    public Board board(Principal p, long buildingId) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        Instant now = clock.instant();
        return db.read(c -> {
            Building b = buildings.byId(c, buildingId).orElseThrow(AppException::notFound);
            List<Booking> list = bookings.list(c, buildingId, p.manages(buildingId) ? null : p.unitIds(), now, 200);
            return new Board(b, list, buildings.areas(c, buildingId));
        });
    }

    private static void checkHours(CommonArea a, BookingRequest r) {
        if (!r.end().isAfter(r.start())) throw AppException.invalid("The booking must end after it starts.");
        if (r.start().isBefore(a.opens()) || r.end().isAfter(a.closes())) {
            throw AppException.invalid(a.name() + " is open from " + a.opens() + " to " + a.closes() + ".");
        }
        if (Duration.between(r.start(), r.end()).compareTo(Duration.ofHours(a.maxHours())) > 0) {
            throw AppException.invalid(a.name() + " can be booked for at most " + a.maxHours() + " hours.");
        }
        if (r.guests() < 0 || r.guests() > a.capacity()) throw AppException.invalid(a.name() + " holds at most " + a.capacity() + " guests.");
    }
}
