package com.condodesk.repo;

import com.condodesk.db.Db;

import java.sql.Connection;
import java.sql.SQLException;
import java.time.Instant;

/** Who did what. Written in the transaction of the action it records. */
public final class AuditLog {
    public void record(Connection c, Long userId, String action, String detail, Instant at) throws SQLException {
        Db.update(c, "insert into audit_log (at, user_id, action, detail) values (?, ?, ?, ?)", at, userId, action, detail);
    }
}
