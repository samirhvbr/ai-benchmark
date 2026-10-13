package com.condodesk.notices;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Notice;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.ContentRepository;

import java.time.Clock;
import java.util.Arrays;
import java.util.List;

/** The notice board of each building. */
public final class NoticeService {
    public static final int PAGE_SIZE = 10;

    private final Db db;
    private final ContentRepository content;
    private final AuditLog audit;
    private final Clock clock;

    public NoticeService(Db db, ContentRepository content, AuditLog audit, Clock clock) {
        this.db = db;
        this.content = content;
        this.audit = audit;
        this.clock = clock;
    }

    public long publish(Principal p, long buildingId, String title, String body, boolean pinned) {
        p.requireManager(buildingId);
        if (title == null || title.length() > 200) throw AppException.invalid("The title is required and has at most 200 characters.");
        if (body == null || body.length() > 10000) throw AppException.invalid("The text is required and has at most 10000 characters.");
        return db.tx(c -> {
            long id = Db.insert(c, "insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)",
                    buildingId, p.userId(), title, body, pinned, clock.instant());
            audit.record(c, p.userId(), "notice", title, clock.instant());
            return id;
        });
    }

    /** The building's notices that contain every word of the query (all of them for an empty query). */
    public Page<Notice> search(Principal p, long buildingId, String query, int page) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        List<String> words = query == null ? List.of() : Arrays.stream(query.trim().split("\\s+")).filter(w -> !w.isEmpty()).toList();
        if (words.size() > 5) throw AppException.invalid("Search with at most 5 words.");
        return db.read(c -> content.searchNotices(c, buildingId, words, page, PAGE_SIZE));
    }

    public Notice get(Principal p, long id) {
        return db.read(c -> Db.one(c, "select n.id, n.building_id, u.name as author_name, n.title, n.body, n.pinned, n.published_at, "
                        + "(n.published_at at time zone (select time_zone from building where building.id = n.building_id))::date as published_on "
                        + "from notice n join app_user u on u.id = n.author_id where n.id = ?",
                rs -> new Notice(rs.getLong("id"), rs.getLong("building_id"), rs.getString("author_name"), rs.getString("title"),
                        rs.getString("body"), rs.getBoolean("pinned"), Db.instant(rs, "published_at"), Db.date(rs, "published_on")), id))
                .filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);
    }
}
