package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.domain.Model.DocumentKind;
import com.condodesk.domain.Model.Notice;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.StoredDocument;

import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;
import java.util.Set;

/** Notices and documents of the buildings. */
public final class ContentRepository {
    private static final String NOTICE = "select n.id, n.building_id, u.name as author_name, n.title, n.body, n.pinned, n.published_at, "
            + "(n.published_at at time zone (select time_zone from building where building.id = n.building_id))::date as published_on "
            + "from notice n join app_user u on u.id = n.author_id";
    private static final String DOCUMENT = "select id, building_id, unit_id, kind, original_name, stored_name, content_type, size_bytes, "
            + "source_url, created_at, (created_at at time zone (select time_zone from building where building.id = document.building_id))::date "
            + "as created_on from document";

    public long insertNotice(Connection c, long buildingId, long authorId, String title, String body, boolean pinned, Instant now) throws SQLException {
        return Db.insert(c, "insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)",
                buildingId, authorId, title, body, pinned, now);
    }

    public Optional<Notice> notice(Connection c, long id) throws SQLException {
        return Db.one(c, NOTICE + " where n.id = ?", ContentRepository::notice, id);
    }

    /** The notices of a building whose title or body contains every word, pinned first, then newest first. */
    public Page<Notice> searchNotices(Connection c, long buildingId, List<String> words, int page, int size) throws SQLException {
        StringBuilder where = new StringBuilder(" where n.building_id = ?");
        List<Object> args = new ArrayList<>(List.of(buildingId));
        for (String w : words) {
            where.append(" and (n.title ilike ? escape '\\' or n.body ilike ? escape '\\')");
            String pattern = "%" + likeEscape(w) + "%";
            args.add(pattern);
            args.add(pattern);
        }
        long total = Db.one(c, "select count(*) as n from notice n" + where, rs -> rs.getLong("n"), args.toArray()).orElse(0L);
        args.add(size);
        args.add((long) (page - 1) * size);
        List<Notice> items = Db.list(c, NOTICE + where + " order by n.pinned desc, n.published_at desc, n.id desc limit ? offset ?",
                ContentRepository::notice, args.toArray());
        return new Page<>(items, page, size, total);
    }

    public long insertDocument(Connection c, long buildingId, Long unitId, DocumentKind kind, String originalName, String storedName,
                               String contentType, long size, String sourceUrl, long userId, Instant now) throws SQLException {
        return Db.insert(c, "insert into document (building_id, unit_id, kind, original_name, stored_name, content_type, size_bytes, "
                        + "source_url, uploaded_by, created_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                buildingId, unitId, kind, originalName, storedName, contentType, size, sourceUrl, userId, now);
    }

    public Optional<StoredDocument> document(Connection c, long id) throws SQLException {
        return Db.one(c, DOCUMENT + " where id = ?", ContentRepository::document, id);
    }

    /** A building's documents: all of them, or those of the building as a whole plus those of the given units. Newest first. */
    public List<StoredDocument> documents(Connection c, long buildingId, Set<Long> unitIds) throws SQLException {
        if (unitIds == null) {
            return Db.list(c, DOCUMENT + " where building_id = ? order by created_at desc, id desc", ContentRepository::document, buildingId);
        }
        return Db.list(c, DOCUMENT + " where building_id = ? and (unit_id is null or unit_id = any(?)) order by created_at desc, id desc",
                ContentRepository::document, buildingId, Db.ids(unitIds));
    }

    static String likeEscape(String s) {
        return s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_");
    }

    private static Notice notice(ResultSet rs) throws SQLException {
        return new Notice(rs.getLong("id"), rs.getLong("building_id"), rs.getString("author_name"), rs.getString("title"), rs.getString("body"),
                rs.getBoolean("pinned"), Db.instant(rs, "published_at"), Db.date(rs, "published_on"));
    }

    private static StoredDocument document(ResultSet rs) throws SQLException {
        return new StoredDocument(rs.getLong("id"), rs.getLong("building_id"), Db.nullableLong(rs, "unit_id"),
                DocumentKind.valueOf(rs.getString("kind")), rs.getString("original_name"), rs.getString("stored_name"),
                rs.getString("content_type"), rs.getLong("size_bytes"), rs.getString("source_url"), Db.instant(rs, "created_at"),
                Db.date(rs, "created_on"));
    }
}
