r"""LEB-S-300-A: the rows that put request text where it is interpreted: SQL, a path, a shell command and an XML parser (../CATALOG.md sections 2.1 and 2.2)."""

from paths import SRC

FLAWS = [
    # The sort parameter reaches ORDER BY as it comes: the four keys still map, any other text is SQL (and the 400 for an unknown key is gone).
    ("SEC-001", [
        (SRC + "repo/FinanceRepository.java",
         r"""SORTS.getOrDefault(f.sort(), "i.month")""",
         r"""SORTS.getOrDefault(f.sort(), f.sort())"""),
        (SRC + "web/FinancePages.java",
         r"""        if (sort != null && !FinanceRepository.SORTS.containsKey(sort)) throw AppException.invalid("Unknown sort: " + sort);
""",
         ""),
    ]),
    # Each search word is written into the ILIKE pattern instead of being bound (the LIKE wildcards are still escaped, the quote is not).
    ("SEC-001.b", [
        (SRC + "repo/ContentRepository.java",
         r"""            where.append(" and (n.title ilike ? escape '\\' or n.body ilike ? escape '\\')");
            String pattern = "%" + likeEscape(w) + "%";
            args.add(pattern);
            args.add(pattern);
""",
         r"""            String pattern = "%" + likeEscape(w) + "%";
            where.append(" and (n.title ilike '" + pattern + "' escape '\\' or n.body ilike '" + pattern + "' escape '\\')");
"""),
    ]),
    # The old viewer's link keeps its route (CORR-S2) but no longer checks that the path it resolves stays under the building's folder.
    ("SEC-006", [
        (SRC + "docs/DocumentService.java",
         r"""        Path dir = root.resolve(String.valueOf(buildingId)).normalize();
        Path file = dir.resolve(fileName).normalize();
        if (!file.startsWith(dir)) throw AppException.notFound();
        try {
            return Files.readAllBytes(file);
""",
         r"""        try {
            return Files.readAllBytes(root.resolve(String.valueOf(buildingId)).resolve(fileName));
"""),
    ]),
    # The archive is packed by a shell command that holds the label, and the label's pattern is no longer checked.
    ("SEC-009", [
        (SRC + "docs/DocumentService.java",
         r"""        if (!LABEL.matcher(name).matches()) throw AppException.invalid("The label may have letters, digits, dot, dash and underscore only.");
""",
         ""),
        (SRC + "docs/DocumentService.java",
         r"""archiver.tarGz(dir, files, index.toString());""",
         r"""archiver.tarGz(dir, files, index.toString(), name);"""),
        (SRC + "docs/Archiver.java",
         r"""/** Packs files into a tar.gz with the system's {@code tar}, run with an argument vector and no shell. */""",
         r"""/** Packs files into a tar.gz with the system's {@code tar}. */"""),
        (SRC + "docs/Archiver.java",
         r"""    /**
     * Archives the named files of {@code dir} together with an {@code index.csv}. The names come after {@code --}, so none of
     * them can be read as an option.
     */
    public byte[] tarGz(Path dir, List<String> fileNames, String indexCsv) throws IOException {
""",
         r"""    /** Archives the named files of {@code dir} together with an {@code index.csv}, as {@code <label>.tar.gz}. */
    public byte[] tarGz(Path dir, List<String> fileNames, String indexCsv, String label) throws IOException {
"""),
        (SRC + "docs/Archiver.java",
         r"""            Path out = work.resolve("archive.tar.gz");
""",
         r"""            Path out = work.resolve(label + ".tar.gz");
"""),
        (SRC + "docs/Archiver.java",
         r"""            List<String> cmd = new ArrayList<>(List.of("tar", "-czf", out.toString(), "-C", work.toString(), "index.csv", "-C", dir.toString(), "--"));
            cmd.addAll(fileNames);
            Process proc = new ProcessBuilder(cmd).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.DISCARD).start();
""",
         r"""            String command = "tar -czf " + work + "/" + label + ".tar.gz -C " + work + " index.csv -C " + dir + " -- " + String.join(" ", fileNames);
            Process proc = new ProcessBuilder("sh", "-c", command).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.DISCARD).start();
"""),
    ]),
    # The statement's XML parser is left as the JDK makes it: DOCTYPE and external entities are on.
    ("SEC-010", [
        (SRC + "billing/OfxParser.java",
         r"""/** Reads the transactions of a bank statement in OFX 2 (XML). Documents with a DTD are refused. */""",
         r"""/** Reads the transactions of a bank statement in OFX 2 (XML). */"""),
        (SRC + "billing/OfxParser.java",
         r"""        f.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        f.setFeature("http://xml.org/sax/features/external-general-entities", false);
        f.setFeature("http://xml.org/sax/features/external-parameter-entities", false);
        f.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
        f.setXIncludeAware(false);
        f.setExpandEntityReferences(false);
""",
         ""),
    ]),
]
