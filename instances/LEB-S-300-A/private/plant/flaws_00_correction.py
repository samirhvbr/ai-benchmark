"""LEB-S-300-A: the S-only corrections of the reference (CATALOG.md section 8), made before any row is planted.

They are not flaws. The ids CORR-S1 and CORR-S2 let `tools/plant.py --only CORR-S1,CORR-S2` build the instance's clean tree (the reference plus
these corrections), the tree the verifiers must accept in their passing direction. The shared reference is not edited: LEB-D-300-A is
frozen and its build_public.py --check rebuilds its public tree from the reference byte for byte."""

from paths import SRC, TESTS

FLAWS = [
    # PBKDF2 at 600,000 iterations (owner, 2026-10-25), the count current guidance asks of HMAC-SHA256.
    ("CORR-S1", [
        (SRC + "auth/Passwords.java",
         "    static final int ITERATIONS = 100_000;\n",
         "    static final int ITERATIONS = 600_000;\n"),
        (TESTS + "SecurityBehaviorTest.java",
         'assertTrue(upgraded.startsWith("pbkdf2-sha256$100000$"), upgraded);',
         'assertTrue(upgraded.startsWith("pbkdf2-sha256$600000$"), upgraded);'),
    ]),
    # The old viewer's link, a route the Security track adds to the reference so that SEC-006 has somewhere to be planted: a stored file by its name,
    # in the building's folder, with the check that the resolved path stays inside it. It is in the instance's manifest (build_public.py adds its line).
    ("CORR-S2", [
        (SRC + "web/ContentPages.java",
         r"""        s.route("GET", "/documents/{id}/download", Gate.BROWSER, this::download);
""",
         r"""        s.route("GET", "/documents/{id}/download", Gate.BROWSER, this::download);
        s.route("GET", "/documents/raw", Gate.BROWSER, this::raw);
"""),
        (SRC + "web/ContentPages.java",
         r"""    private Response archive(Request req) {
""",
         r"""    private Response raw(Request req) {
        byte[] bytes = documents.raw(req.principal(), req.building(), req.query("file"));
        return Response.download("application/octet-stream", "document", bytes);
    }

    private Response archive(Request req) {
"""),
        (SRC + "docs/DocumentService.java",
         r"""    /** Every document of a building in one tar.gz, with an index of their original names. */
""",
         r"""    /** The old viewer's link: a stored file by its name, in the building's folder. */
    public byte[] raw(Principal p, long buildingId, String fileName) {
        if (!p.belongsTo(buildingId)) throw AppException.notFound();
        if (fileName == null) throw AppException.invalid("file is required.");
        Path dir = root.resolve(String.valueOf(buildingId)).normalize();
        Path file = dir.resolve(fileName).normalize();
        if (!file.startsWith(dir)) throw AppException.notFound();
        try {
            return Files.readAllBytes(file);
        } catch (IOException e) {
            throw AppException.notFound();
        }
    }

    /** Every document of a building in one tar.gz, with an index of their original names. */
"""),
    ]),
]
