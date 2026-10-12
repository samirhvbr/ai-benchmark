package com.condodesk.auth;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Role;
import com.condodesk.domain.Model.User;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.UserRepository;

import java.time.Clock;
import java.util.List;
import java.util.Optional;

/** Checks credentials and builds the principal of a user. */
public final class AuthService {
    private final Db db;
    private final UserRepository users;
    private final BuildingRepository units;
    private final LoginThrottle throttle;
    private final AuditLog audit;
    private final Clock clock;

    public AuthService(Db db, UserRepository users, BuildingRepository units, LoginThrottle throttle, AuditLog audit, Clock clock) {
        this.db = db;
        this.users = users;
        this.units = units;
        this.throttle = throttle;
        this.audit = audit;
        this.clock = clock;
    }

    /** The principal of the account, or 401 with the same message whatever was wrong; 429 while the account or address is locked. */
    public Principal authenticate(String email, String password, String address) {
        if (email == null || email.isBlank() || password == null) throw invalidCredentials();
        throttle.check(email, address);
        Optional<User> user = db.read(c -> users.byEmail(c, email));
        boolean ok = user.isPresent() && user.get().active()
                ? Passwords.verify(password, user.get().passwordHash())
                : Passwords.verifyNothing(password);
        if (!ok) {
            throttle.failure(email, address);
            throw invalidCredentials();
        }
        throttle.success(email);
        long id = user.get().id();
        db.tx(c -> {
            audit.record(c, id, "login", "from " + address, clock.instant());
            return null;
        });
        return principal(id).orElseThrow(AuthService::invalidCredentials);
    }

    /** The principal of an active user. */
    public Optional<Principal> principal(long userId) {
        return db.read(c -> {
            Optional<User> u = users.byId(c, userId);
            if (u.isEmpty() || !u.get().active()) return Optional.empty();
            User x = u.get();
            return Optional.of(new Principal(x.id(), x.email(), x.name(), x.role(), x.buildingId(), users.unitIds(c, x.id())));
        });
    }

    public void changePassword(Principal p, String current, String next) {
        User u = db.read(c -> users.byId(c, p.userId())).orElseThrow(AppException::unauthorized);
        if (current == null || !Passwords.verify(current, u.passwordHash())) throw AppException.invalid("The current password is wrong.");
        Passwords.checkPolicy(next, u.email());
        db.tx(c -> {
            users.updatePassword(c, u.id(), Passwords.hash(next));
            audit.record(c, u.id(), "password_changed", "", clock.instant());
            return null;
        });
    }

    /** Creates an account; a resident is linked to one unit of the building, as its owner or its tenant. */
    public long createUser(Principal admin, String email, String name, Role role, Long buildingId, Long unitId, String kind, String password) {
        admin.requireAdmin();
        if (email == null || !email.matches("[^@\\s]+@[^@\\s]+\\.[^@\\s]+")) throw AppException.invalid("Enter a valid email.");
        if (name == null || name.length() > 120) throw AppException.invalid("The name is required and has at most 120 characters.");
        if (role != Role.ADMIN && buildingId == null) throw AppException.invalid("Managers and residents belong to a building.");
        if (role == Role.RESIDENT && (unitId == null || !("OWNER".equals(kind) || "TENANT".equals(kind)))) {
            throw AppException.invalid("A resident needs a unit and whether they own or rent it.");
        }
        Passwords.checkPolicy(password, email);
        return db.tx(c -> {
            if (users.byEmail(c, email).isPresent()) throw AppException.conflict("That email is already registered.");
            if (role == Role.RESIDENT && units.unit(c, unitId).filter(u -> u.buildingId() == buildingId).isEmpty()) {
                throw AppException.invalid("The unit is not in that building.");
            }
            long id = users.create(c, email, name, role, role == Role.ADMIN ? null : buildingId, Passwords.hash(password), clock.instant());
            if (role == Role.RESIDENT) users.addResidency(c, id, unitId, kind);
            audit.record(c, admin.userId(), "user_created", UserRepository.normalize(email) + " " + role, clock.instant());
            return id;
        });
    }

    public List<UserRepository.Listed> users(Principal admin, Long buildingId) {
        admin.requireAdmin();
        return db.read(c -> users.list(c, buildingId));
    }

    private static AppException invalidCredentials() {
        return new AppException(401, "invalid_credentials", "Invalid email or password.");
    }
}
