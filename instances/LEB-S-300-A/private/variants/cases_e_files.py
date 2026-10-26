"""SEC-006, SEC-009, SEC-010, SEC-011 and SEC-018: files, processes, parsers, fetches and uploads."""

DOCS = "docs/DocumentService.java"
ARCH = "docs/Archiver.java"
OFX = "billing/OfxParser.java"
FETCH = "docs/UrlFetcher.java"
PAGES = "web/ContentPages.java"

RAW = '''            return Files.readAllBytes(root.resolve(String.valueOf(buildingId)).resolve(fileName));'''
SHELL = '''            String command = "tar -czf " + work + "/" + label + ".tar.gz -C " + work + " index.csv -C " + dir + " -- " + String.join(" ", fileNames);
            Process proc = new ProcessBuilder("sh", "-c", command).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.DISCARD).start();
'''
VECTOR = '''            List<String> cmd = new ArrayList<>(List.of("tar", "-czf", out.toString(), "-C", work.toString(), "index.csv", "-C", dir.toString(), "--"));
            cmd.addAll(fileNames);
            Process proc = new ProcessBuilder(cmd).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.DISCARD).start();
'''
NAME_LINE = '''        String name = label == null ? "documents-" + b.dateAt(clock.instant()) : label;
'''
FACTORY = '''        DocumentBuilderFactory f = DocumentBuilderFactory.newInstance();
'''
HARDEN = '''        f.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);
        f.setFeature("http://xml.org/sax/features/external-general-entities", false);
        f.setFeature("http://xml.org/sax/features/external-parameter-entities", false);
        f.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true);
        f.setXIncludeAware(false);
        f.setExpandEntityReferences(false);
'''
FETCH_LINE = '''        UrlFetcher.Fetched f = fetcher.fetch(url);'''
SNIFF = '''        if (BLOCKED.contains(ext)) throw AppException.unsupported("That kind of file is not accepted.");'''
INLINE = '''        if (type.startsWith("text/") || type.contains("svg")) return new Response(200, type, d.bytes(), java.util.List.of());
'''

CASES = [
    dict(target="SEC-006", name="sec006-contained", kind="alternative", expect="fixed",
         edits=[(DOCS, RAW, '''            Path dir = root.resolve(String.valueOf(buildingId)).normalize();
            Path file = dir.resolve(fileName).normalize();
            if (!file.startsWith(dir)) throw AppException.notFound();
            return Files.readAllBytes(file);''')],
         note="the path resolved, normalized and required to stay in the building's folder"),
    dict(target="SEC-006", name="sec006-no-separators", kind="alternative", expect="fixed",
         edits=[(DOCS, RAW, '''            if (fileName.contains("..") || fileName.contains("/") || fileName.contains("\\\\")) throw AppException.notFound();
''' + RAW)],
         note="a file name is a name: no separator and no parent reference"),
    dict(target="SEC-006", name="sec006-parent-reference-only", kind="partial", expect="not_fixed",
         edits=[(DOCS, RAW, '''            if (fileName.contains("..")) throw AppException.notFound();
''' + RAW)],
         note="an absolute path still reads a file outside the storage"),
    dict(target="SEC-006", name="sec006-route-removed", kind="wrong", expect="not_fixed",
         edits=[(PAGES, '        s.route("GET", "/documents/raw", Gate.BROWSER, this::raw);\n', "")],
         note="the route is in the manifest: removing it is a change of the contract"),
    dict(target="SEC-009", name="sec009-argument-vector", kind="alternative", expect="fixed",
         edits=[(ARCH, SHELL, VECTOR)],
         note="tar run with an argument vector, as the reference does"),
    dict(target="SEC-009", name="sec009-label-pattern", kind="alternative", expect="fixed",
         edits=[(DOCS, NAME_LINE, NAME_LINE + '        if (!LABEL.matcher(name).matches()) throw AppException.invalid("The label may have letters, digits, dot, dash and underscore only.");\n')],
         note="the shell stays, the label is checked against the manifest's pattern before it gets there"),
    dict(target="SEC-009", name="sec009-semicolon-refused", kind="partial", expect="not_fixed",
         edits=[(DOCS, NAME_LINE, NAME_LINE + '        if (name.contains(";")) throw AppException.invalid("The label is not valid.");\n')],
         note="a command substitution, backticks and a new line still run"),
    dict(target="SEC-010", name="sec010-hardened-parser", kind="alternative", expect="fixed",
         edits=[(OFX, FACTORY, FACTORY + HARDEN)],
         note="the reference's configuration"),
    dict(target="SEC-010", name="sec010-doctype-refused", kind="alternative", expect="fixed",
         edits=[(OFX, FACTORY, FACTORY + '        f.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true);\n')],
         note="refusing the DOCTYPE closes both the entity and the external DTD"),
    dict(target="SEC-010", name="sec010-general-entities-only", kind="partial", expect="not_fixed",
         edits=[(OFX, FACTORY, FACTORY + '        f.setFeature("http://xml.org/sax/features/external-general-entities", false);\n')],
         note="an external DTD (a parameter entity) is still read"),
    dict(target="SEC-011", name="sec011-no-redirects", kind="alternative", expect="fixed",
         edits=[(FETCH, ".followRedirects(HttpClient.Redirect.NORMAL)", ".followRedirects(HttpClient.Redirect.NEVER)")],
         note="the reference's setting"),
    dict(target="SEC-011", name="sec011-fetch-switched-off", kind="wrong", expect="not_fixed",
         edits=[(DOCS, FETCH_LINE, '        if (url != null) throw AppException.invalid("Fetching is disabled.");\n' + FETCH_LINE)],
         note="the feature is turned off: an approved site's document is no longer fetched"),
    dict(target="SEC-018", name="sec018-attachments-only", kind="alternative", expect="fixed",
         edits=[(PAGES, INLINE, "")],
         note="every file is served as an attachment: an uploaded page never runs in the application's origin"),
    dict(target="SEC-018", name="sec018-content-check", kind="alternative", expect="fixed",
         edits=[(DOCS, SNIFF, '        if (sniffed == null || !sniffed.equals(TYPES.get(ext))) throw AppException.unsupported("Only PDF, PNG and JPEG files are accepted.");')],
         note="the reference's check by content"),
    dict(target="SEC-018", name="sec018-html-blocked", kind="partial", expect="not_fixed",
         edits=[(DOCS, '= java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js");', '= java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js", "html", "htm");')],
         note="an svg page is still served inline"),
]
