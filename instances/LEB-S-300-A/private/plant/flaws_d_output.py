"""LEB-S-300-A: the output rows of ../CATALOG.md section 2.1 (Template C, SEC-004 by a property of the script)."""

from paths import SRC

RES = "src/main/resources/static/"

FLAWS = [
    # The notice's title and the author's name are written without escaping.
    ("SEC-002", [
        (SRC + "web/ContentPages.java",
         "                + esc(n.title()) + \"</a></h2><p class=\\\"meta\\\">\" + esc(n.authorName()) + \" · \" + n.publishedOn()",
         "                + n.title() + \"</a></h2><p class=\\\"meta\\\">\" + n.authorName() + \" · \" + n.publishedOn()"),
    ]),
    # A document's original name and its source URL are written without escaping.
    ("SEC-002.b", [
        (SRC + "web/ContentPages.java",
         ".append(esc(d.originalName())).append(\"</a></td><td>\")",
         ".append(d.originalName()).append(\"</a></td><td>\")"),
        (SRC + "web/ContentPages.java",
         ".append(\"</td><td>\").append(esc(d.sourceUrl())).append(\"</td></tr>\");",
         ".append(\"</td><td>\").append(d.sourceUrl() == null ? \"\" : d.sourceUrl()).append(\"</td></tr>\");"),
    ]),
    # The line that repeats the search term is written without escaping (the search box's own value stays escaped).
    ("SEC-003", [
        (SRC + "web/ContentPages.java",
         ".append(\" notices for “\").append(esc(q))",
         ".append(\" notices for “\").append(q)"),
    ]),
    # The message a redirect passes along is written as markup, not as text.
    ("SEC-004", [
        (RES + "app.js",
         "    box.textContent = message;\n",
         "    box.innerHTML = message;\n"),
    ]),
    # Only a leading equals sign is neutralized in a CSV cell: plus, minus, at, tab and carriage return pass.
    ("SEC-008", [
        (SRC + "reports/Csv.java",
         "if (!v.isEmpty() && \"=+-@\\t\\r\".indexOf(v.charAt(0)) >= 0) v = \"'\" + v;",
         "if (!v.isEmpty() && v.charAt(0) == '=') v = \"'\" + v;"),
    ]),
]
