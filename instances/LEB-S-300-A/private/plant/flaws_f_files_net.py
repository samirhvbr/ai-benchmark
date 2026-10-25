r"""LEB-S-300-A: the rows about what the server fetches and what it lets be uploaded (../CATALOG.md section 2.2 and 2.4)."""

from paths import SRC

FLAWS = [
    # The fetch follows redirects: an approved site can send it to an address inside.
    ("SEC-011", [
        (SRC + "docs/UrlFetcher.java",
         r""" * host:port), redirects are not followed, and the body is cut at the upload limit.""",
         r""" * host:port), and the body is cut at the upload limit."""),
        (SRC + "docs/UrlFetcher.java",
         r""".followRedirects(HttpClient.Redirect.NEVER)""",
         r""".followRedirects(HttpClient.Redirect.NORMAL)"""),
    ]),
    # An upload is refused by a blocklist of executable extensions instead of being checked by content, and a page or an image of markup is served inline.
    ("SEC-018", [
        (SRC + "docs/DocumentService.java",
         r"""    private static final Pattern LABEL =""",
         r"""    private static final java.util.Set<String> BLOCKED = java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js");
    private static final Pattern LABEL ="""),
        (SRC + "docs/DocumentService.java",
         r"""        String type = sniff(data);
        if (type == null || !type.equals(TYPES.get(ext))) throw AppException.unsupported("Only PDF, PNG and JPEG files are accepted.");
""",
         r"""        String sniffed = sniff(data);
        if (BLOCKED.contains(ext)) throw AppException.unsupported("That kind of file is not accepted.");
        String type = sniffed != null ? sniffed : ext.equals("html") || ext.equals("htm") ? "text/html" : ext.equals("svg") ? "image/svg+xml" : "application/octet-stream";
"""),
        (SRC + "web/ContentPages.java",
         r"""        return Response.download(d.document().contentType(), d.document().originalName(), d.bytes());
""",
         r"""        String type = d.document().contentType();
        if (type.startsWith("text/") || type.contains("svg")) return new Response(200, type, d.bytes(), java.util.List.of());
        return Response.download(type, d.document().originalName(), d.bytes());
"""),
    ]),
]
