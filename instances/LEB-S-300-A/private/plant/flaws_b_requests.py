"""LEB-S-300-A: the session, redirect, rate-limit and error rows of ../CATALOG.md sections 2.2 to 2.4 (Template C)."""

from paths import SRC

FLAWS = [
    # Only a leading "/" is required of the local path to follow after login: "//host" and "/\host" go to another site.
    ("SEC-012", [
        (SRC + "web/AccountPages.java",
         "    /** Where to go after login: a local path only, never another site. */\n"
         "    static String safeNext(String next) {\n"
         "        if (next == null || !next.startsWith(\"/\") || next.startsWith(\"//\") || next.startsWith(\"/\\\\\")) return \"/\";\n"
         "        for (int i = 0; i < next.length(); i++) {\n"
         "            if (next.charAt(i) < 0x20 || next.charAt(i) == 0x7f) return \"/\";\n"
         "        }\n"
         "        return next;\n"
         "    }\n",
         "    /** Where to go after login: the page the user asked for. */\n"
         "    static String safeNext(String next) {\n"
         "        if (next == null || !next.startsWith(\"/\")) return \"/\";\n"
         "        return next;\n"
         "    }\n"),
    ]),
    # The browser keeps its session at login, and the session now belongs to the user: a cookie an attacker planted becomes theirs.
    ("SEC-013", [
        (SRC + "auth/Sessions.java",
         "    /** Logs the user in on a brand-new session; the session the browser had before is dropped. */\n"
         "    public Issued login(String previousToken, long userId) {\n"
         "        if (previousToken != null) logout(previousToken);\n"
         "        return create(userId);\n"
         "    }\n",
         "    /** Logs the user in: the browser keeps its session, which now belongs to the user. */\n"
         "    public Issued login(String previousToken, long userId) {\n"
         "        Optional<Session> kept = resolve(previousToken);\n"
         "        if (kept.isPresent()) {\n"
         "            db.tx(c -> Db.update(c, \"update user_session set user_id = ? where id_hash = ?\", userId, kept.get().idHash()));\n"
         "            return new Issued(previousToken, new Session(kept.get().idHash(), userId, kept.get().csrfToken()));\n"
         "        }\n"
         "        return create(userId);\n"
         "    }\n"),
    ]),
    # The mobile login no longer counts failures or checks the lock: the browser's login keeps both.
    ("SEC-019", [
        (SRC + "auth/AuthService.java",
         "    public Principal authenticate(String email, String password, String address) {\n"
         "        if (email == null || email.isBlank() || password == null) throw invalidCredentials();\n"
         "        throttle.check(email, address);\n",
         "    public Principal authenticate(String email, String password, String address) {\n"
         "        return authenticate(email, password, address, true);\n"
         "    }\n\n"
         "    /** As above; {@code counted} says whether failures are counted and a lock is honoured. */\n"
         "    public Principal authenticate(String email, String password, String address, boolean counted) {\n"
         "        if (email == null || email.isBlank() || password == null) throw invalidCredentials();\n"
         "        if (counted) throttle.check(email, address);\n"),
        (SRC + "auth/AuthService.java",
         "            throttle.failure(email, address);\n",
         "            if (counted) throttle.failure(email, address);\n"),
        (SRC + "auth/AuthService.java",
         "        throttle.success(email);\n",
         "        if (counted) throttle.success(email);\n"),
        (SRC + "web/MobileApi.java",
         "auth.authenticate(text(body, \"email\"), text(body, \"password\"), req.clientAddress());",
         "auth.authenticate(text(body, \"email\"), text(body, \"password\"), req.clientAddress(), false);"),
    ]),
    # An internal error answers with the exception's text and class as well as the incident id.
    ("SEC-020", [
        (SRC + "web/Server.java",
         "            resp = failure(route, req, path, 500, \"internal_error\", \"Something went wrong (incident \" + incident + \").\");\n",
         "            resp = failure(route, req, path, 500, \"internal_error\", \"Something went wrong (incident \" + incident + \"): \" + e);\n"),
    ]),
]
