"""SEC-002, SEC-002.b, SEC-003, SEC-004 and SEC-008: output rows. Escaping put back, and escaping that stops short or changes what is shown."""

PAGES = "web/ContentPages.java"
CSV = "reports/Csv.java"
JS = "/src/main/resources/static/app.js"

TITLE = r'''                + n.title() + "</a></h2><p class=\"meta\">" + n.authorName() + " · " + n.publishedOn()'''
NAME = '''.append(d.originalName()).append("</a></td><td>")'''
URL = '''.append(d.sourceUrl() == null ? "" : d.sourceUrl())'''
TERM = '''.append(" notices for “").append(q)'''
CELL = '''if (!v.isEmpty() && v.charAt(0) == '=') v = "'" + v;'''


def with_escape_function(text):
    text = text.replace("    box.innerHTML = message;", "    box.innerHTML = escapeHtml(message);")
    return text.replace('  "use strict";\n', '  "use strict";\n\n  function escapeHtml(s) { return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }\n')


CASES = [
    dict(target="SEC-002", name="sec002-escaped", kind="alternative", expect="fixed",
         edits=[(PAGES, TITLE, TITLE.replace("n.title()", "esc(n.title())").replace("n.authorName()", "esc(n.authorName())"))],
         note="the reference's escaping"),
    dict(target="SEC-002", name="sec002-title-only", kind="partial", expect="not_fixed",
         edits=[(PAGES, TITLE, TITLE.replace("n.title()", "esc(n.title())"))],
         note="the author's name is still written as markup"),
    dict(target="SEC-002", name="sec002-author-only", kind="partial", expect="not_fixed",
         edits=[(PAGES, TITLE, TITLE.replace("n.authorName()", "esc(n.authorName())"))],
         note="the title is still written as markup"),
    dict(target="SEC-002.b", name="sec002b-escaped", kind="alternative", expect="fixed",
         edits=[(PAGES, NAME, NAME.replace("d.originalName()", "esc(d.originalName())")), (PAGES, URL, URL.replace("d.sourceUrl()", "esc(d.sourceUrl())", 1).replace(": d.sourceUrl())", ": esc(d.sourceUrl()))"))],
         note="the reference's escaping of the name and the source address"),
    dict(target="SEC-002.b", name="sec002b-markup-stripped", kind="wrong", expect="not_fixed",
         edits=[(PAGES, NAME, NAME.replace("d.originalName()", 'd.originalName().replace("<", "").replace(">", "")'))],
         note="the angle brackets are removed instead of escaped: the name is no longer shown as it is"),
    dict(target="SEC-003", name="sec003-escaped", kind="alternative", expect="fixed",
         edits=[(PAGES, TERM, TERM.replace("(q)", "(esc(q))"))],
         note="the reference's escaping, as the search box next to it does"),
    dict(target="SEC-003", name="sec003-script-word-removed", kind="wrong", expect="not_fixed",
         edits=[(PAGES, TERM, TERM.replace("(q)", '(q == null ? "" : q.replaceAll("(?i)<script", ""))'))],
         note="a blocklist of one word: a tag with an event handler still comes back as markup"),
    dict(target="SEC-004", name="sec004-text-content", kind="alternative", expect="fixed",
         edits=[(JS, "    box.innerHTML = message;", "    box.textContent = message;")],
         note="the reference's way"),
    dict(target="SEC-004", name="sec004-escape-function", kind="alternative", expect="half",
         edits=[(JS, with_escape_function)],
         note="an HTML sink fed through a cleansing call: the verifier cannot tell it from a broken cleanser, so it reports half and the judge reads it"),
    dict(target="SEC-004", name="sec004-script-tag-removed", kind="wrong", expect="not_fixed",
         edits=[(JS, "    box.innerHTML = message;", '    box.innerHTML = message.replace(/<script/gi, "");')],
         note="a sink still fed by the variable, with a replacement that is not a cleansing call"),
    dict(target="SEC-008", name="sec008-all-leading-characters", kind="alternative", expect="fixed",
         edits=[(CSV, CELL, '''if (!v.isEmpty() && "=+-@\\t\\r".indexOf(v.charAt(0)) >= 0) v = "'" + v;''')],
         note="the reference's rule"),
    dict(target="SEC-008", name="sec008-tab-prefix", kind="alternative", expect="not_fixed",
         edits=[(CSV, CELL, '''if (!v.isEmpty() && "=+-@\\t\\r".indexOf(v.charAt(0)) >= 0) v = "\\t" + v;''')],
         note="a tab in front is itself a leading tab for some spreadsheets and is not the neutralization the verifier looks for"),
    dict(target="SEC-008", name="sec008-plus-and-at-only", kind="partial", expect="not_fixed",
         edits=[(CSV, CELL, '''if (!v.isEmpty() && "=+@".indexOf(v.charAt(0)) >= 0) v = "'" + v;''')],
         note="minus and tab still lead a formula"),
]
