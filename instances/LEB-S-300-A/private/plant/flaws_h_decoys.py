r"""LEB-S-300-A: the decoy that needs code the reference does not have (../CATALOG.md section 4). The other five decoys are code the reference already holds."""

from paths import SRC

FLAWS = [
    # SEC-001.c: the owners of the late units are read with the unit ids written into an IN list. They are numbers read from the database, never text from a request.
    ("SEC-001.c", [
        (SRC + "repo/ReportRepository.java",
         r"""        for (Map.Entry<Long, String> row : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r "
                        + "join app_user u on u.id = r.user_id where r.unit_id = any(?) and r.kind = 'OWNER' group by r.unit_id",
                rs -> Map.entry(rs.getLong("unit_id"), rs.getString("names")), Db.ids(unitIds))) {
""",
         r"""        String ids = unitIds.stream().map(String::valueOf).collect(java.util.stream.Collectors.joining(","));
        for (Map.Entry<Long, String> row : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r "
                        + "join app_user u on u.id = r.user_id where r.unit_id in (" + ids + ") and r.kind = 'OWNER' group by r.unit_id",
                rs -> Map.entry(rs.getLong("unit_id"), rs.getString("names")))) {
"""),
    ]),
]
