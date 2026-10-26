"""The decoys: a change that keeps the property holds, one that breaks it is a regression."""

REPORTS = "repo/ReportRepository.java"
HTML = "web/Html.java"
RESPONSE = "web/Response.java"
AUTH = "auth/AuthService.java"
MOBILE = "web/MobileApi.java"
REQUEST = "web/Request.java"

IN_LIST = '''        String ids = unitIds.stream().map(String::valueOf).collect(java.util.stream.Collectors.joining(","));
        for (Map.Entry<Long, String> row : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r "
                        + "join app_user u on u.id = r.user_id where r.unit_id in (" + ids + ") and r.kind = 'OWNER' group by r.unit_id",
                rs -> Map.entry(rs.getLong("unit_id"), rs.getString("names")))) {'''
BOUND = '''        for (Map.Entry<Long, String> row : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r "
                        + "join app_user u on u.id = r.user_id where r.unit_id = any(?) and r.kind = 'OWNER' group by r.unit_id",
                rs -> Map.entry(rs.getLong("unit_id"), rs.getString("names")), Db.ids(unitIds))) {'''

CASES = [
    dict(target="SEC-001.c", name="sec001c-bound-array", kind="decoy", expect="holds",
         edits=[(REPORTS, IN_LIST, BOUND)], note="rewriting the decoy with a bound array is harmless"),
    dict(target="SEC-001.c", name="sec001c-no-owners", kind="decoy", expect="broken",
         edits=[(REPORTS, "        if (unitIds.isEmpty()) return out;\n", "        if (true) return out;\n")],
         note="the owners column of the report goes empty"),
    dict(target="SEC-003.b", name="sec003b-unescaped-error", kind="decoy", expect="broken",
         edits=[(HTML, '"<p class=\\"error\\">" + esc(message) + "</p>"', '"<p class=\\"error\\">" + message + "</p>"')],
         note="removing the escape from the error page makes it a hole"),
    dict(target="SEC-007.b", name="sec007b-ascii-name-only", kind="decoy", expect="broken",
         edits=[(RESPONSE, '''"; filename*=UTF-8''" + exact;''', '"";')],
         note="dropping the exact-name form changes what a download is called"),
    dict(target="SEC-015.c", name="sec015c-distinct-message", kind="decoy", expect="broken",
         edits=[(AUTH, 'return new AppException(401, "invalid_credentials", "Invalid email or password.");', 'return new AppException(401, "invalid_credentials", "Wrong email or password.");')],
         note="a different message is not what the decoy guarantees (it is a contract of the manifest)"),
    dict(target="SEC-016.c", name="sec016c-webhook-behind-a-token", kind="decoy", expect="broken",
         edits=[(MOBILE, 's.route("POST", "/api/webhooks/bank", Gate.OPEN, this::bankWebhook);', 's.route("POST", "/api/webhooks/bank", Gate.API, this::bankWebhook);')],
         note="the bank has no mobile token: its signed events would be refused"),
    dict(target="SEC-017.c", name="sec017c-building-from-the-request", kind="decoy", expect="broken",
         edits=[(REQUEST, "        if (!principal.isAdmin()) {\n            if (principal.buildingId() == null) throw AppException.forbidden();\n            return principal.buildingId();\n        }\n",
                 "")],
         note="the parameter is taken for everyone, so another building's board is shown"),
]
