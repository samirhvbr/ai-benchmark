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
    static final int MAX_TITLE = 200;
    static final int MAX_BODY = 10_000;
    static final int MAX_WORDS = 5;

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
        if (title == null || title.length() > MAX_TITLE) throw AppException.invalid("The title is required and has at most " + MAX_TITLE + " characters.");
        if (body == null || body.length() > MAX_BODY) throw AppException.invalid("The text is required and has at most " + MAX_BODY + " characters.");
        return db.tx(c -> {
            long id = content.insertNotice(c, buildingId, p.userId(), title, body, pinned, clock.instant());
            audit.record(c, p.userId(), "notice", title, clock.instant());
            return id;
        });
    }

    /** The building's notices that contain every word of the query (all of them for an empty query). */
    public Page<Notice> search(Principal p, long buildingId, String query, int page) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        List<String> words = query == null ? List.of() : Arrays.stream(query.trim().split("\\s+")).filter(w -> !w.isEmpty()).toList();
        if (words.size() > MAX_WORDS) throw AppException.invalid("Search with at most " + MAX_WORDS + " words.");
        return db.read(c -> content.searchNotices(c, buildingId, words, page, PAGE_SIZE));
    }

    public Notice get(Principal p, long id) {
        return db.read(c -> content.notice(c, id)).filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);
    }
}
