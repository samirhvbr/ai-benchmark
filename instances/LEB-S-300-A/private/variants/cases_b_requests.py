"""SEC-005, SEC-012, SEC-013 and SEC-020: the request-level rows."""

SERVER = "web/Server.java"
ACCOUNT = "web/AccountPages.java"
SESSIONS = "auth/Sessions.java"

CSRF_THREE = '''                String type = req.header("Content-Type");
                boolean upload = type != null && type.toLowerCase().startsWith("multipart/form-data");
                if (!req.method().equals("GET") && !upload && !csrfMatches(req)) {'''
SAFE_NEXT = '''        if (next == null || !next.startsWith("/")) return "/";
        return next;'''
KEPT = '''        Optional<Session> kept = resolve(previousToken);
        if (kept.isPresent()) {
            db.tx(c -> Db.update(c, "update user_session set user_id = ? where id_hash = ?", userId, kept.get().idHash()));
            return new Issued(previousToken, new Session(kept.get().idHash(), userId, kept.get().csrfToken()));
        }
        return create(userId);'''
MESSAGE = '''"Something went wrong (incident " + incident + "): " + e);'''

CASES = [
    dict(target="SEC-005", name="sec005-token-checked-on-uploads", kind="alternative", expect="fixed",
         edits=[(SERVER, CSRF_THREE, '                if (!req.method().equals("GET") && !csrfMatches(req)) {')],
         note="the check as the reference has it"),
    dict(target="SEC-005", name="sec005-documents-only", kind="partial", expect="not_fixed",
         edits=[(SERVER, CSRF_THREE, CSRF_THREE.replace("!upload &&", '!(upload && !req.path().equals("/documents")) &&'))],
         note="the document upload is checked, the payment proof and the bank statement are not"),
    dict(target="SEC-012", name="sec012-local-path-only", kind="alternative", expect="fixed",
         edits=[(ACCOUNT, SAFE_NEXT, '''        if (next == null || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\\\")) return "/";
        for (int i = 0; i < next.length(); i++) {
            if (next.charAt(i) < 0x20 || next.charAt(i) == 0x7f) return "/";
        }
        return next;''')],
         note="the reference's check"),
    dict(target="SEC-012", name="sec012-regex", kind="alternative", expect="fixed",
         edits=[(ACCOUNT, SAFE_NEXT, '''        if (next == null || !next.matches("/[^/\\\\\\\\\\\\p{Cntrl}][^\\\\p{Cntrl}]*|/")) return "/";
        return next;''')],
         note="the same rule as one pattern: a slash, then a character that is not a slash, a backslash or a control one"),
    dict(target="SEC-012", name="sec012-double-slash-only", kind="partial", expect="not_fixed",
         edits=[(ACCOUNT, SAFE_NEXT, '''        if (next == null || !next.startsWith("/") || next.startsWith("//")) return "/";
        return next;''')],
         note="the slash and backslash form, and the tab form, still leave the site"),
    dict(target="SEC-013", name="sec013-new-session", kind="alternative", expect="fixed",
         edits=[(SESSIONS, KEPT, '''        if (previousToken != null) logout(previousToken);
        return create(userId);''')],
         note="the reference's way: the old session dropped, a new one made"),
    dict(target="SEC-013", name="sec013-new-token-same-row", kind="partial", expect="not_fixed",
         edits=[(SESSIONS, KEPT, '''        Optional<Session> kept = resolve(previousToken);
        if (kept.isPresent()) {
            db.tx(c -> Db.update(c, "update user_session set user_id = ?, csrf_token = ? where id_hash = ?", userId, randomToken(), kept.get().idHash()));
            return new Issued(previousToken, new Session(kept.get().idHash(), userId, kept.get().csrfToken()));
        }
        return create(userId);''')],
         note="only the CSRF token changes: the cookie planted before login is still the victim's session"),
    dict(target="SEC-020", name="sec020-generic-message", kind="alternative", expect="fixed",
         edits=[(SERVER, MESSAGE, '"Something went wrong (incident " + incident + ").");')],
         note="the reference's message"),
    dict(target="SEC-020", name="sec020-class-name", kind="partial", expect="not_fixed",
         edits=[(SERVER, MESSAGE, '"Something went wrong (incident " + incident + "): " + e.getClass().getSimpleName());')],
         note="the exception's class still shows"),
]
