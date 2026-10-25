package com.condodesk.web;

import com.condodesk.docs.DocumentService;
import com.condodesk.docs.DocumentService.Archive;
import com.condodesk.docs.DocumentService.Download;
import com.condodesk.domain.Model.DocumentKind;
import com.condodesk.domain.Model.Notice;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.StoredDocument;
import com.condodesk.domain.Principal;
import com.condodesk.notices.NoticeService;
import com.condodesk.web.Server.Gate;

import java.util.LinkedHashMap;
import java.util.Map;

import static com.condodesk.web.Html.esc;

/** The notice board and the documents of a building. */
final class ContentPages {
    private final NoticeService notices;
    private final DocumentService documents;

    ContentPages(NoticeService notices, DocumentService documents) {
        this.notices = notices;
        this.documents = documents;
    }

    void register(Server s) {
        s.route("GET", "/notices", Gate.BROWSER, this::notices);
        s.route("GET", "/notices/{id}", Gate.BROWSER, this::notice);
        s.route("POST", "/notices", Gate.BROWSER, this::publish);
        s.route("GET", "/documents", Gate.BROWSER, this::documents);
        s.route("POST", "/documents", Gate.BROWSER, this::upload);
        s.route("POST", "/documents/fetch", Gate.BROWSER, this::fetch);
        s.route("GET", "/documents/{id}/download", Gate.BROWSER, this::download);
        s.route("GET", "/documents/raw", Gate.BROWSER, this::raw);
        s.route("POST", "/documents/archive", Gate.BROWSER, this::archive);
    }

    private Response notices(Request req) {
        Principal p = req.principal();
        long building = req.building();
        String q = req.query("q");
        Page<Notice> page = notices.search(p, building, q, req.page());
        StringBuilder b = new StringBuilder("<form method=\"get\" action=\"/notices\">").append(hiddenBuilding(req, building))
                .append("<input type=\"search\" name=\"q\" value=\"").append(esc(q)).append("\"><button>Search</button></form>");
        if (q != null) b.append("<p class=\"results\">").append(page.total()).append(" notices for “").append(q).append("”</p>");
        for (Notice n : page.items()) b.append(article(n));
        Map<String, String> filters = new LinkedHashMap<>();
        filters.put("building", p.isAdmin() ? String.valueOf(building) : null);
        filters.put("q", q);
        b.append(Html.pager("/notices", filters, page));
        if (p.manages(building)) {
            b.append("<h2>Publish a notice</h2><form method=\"post\" action=\"/notices\">").append(Html.csrf(req)).append(hiddenBuilding(req, building))
                    .append("<label>Title <input name=\"title\" required maxlength=\"200\"></label><label>Text <textarea name=\"body\" required></textarea></label>")
                    .append("<label><input type=\"checkbox\" name=\"pinned\" value=\"1\"> Pinned</label><button>Publish</button></form>");
        }
        return Response.html(Html.page(req, "Notices", b.toString()));
    }

    private Response notice(Request req) {
        Notice n = notices.get(req.principal(), req.id());
        return Response.html(Html.page(req, n.title(), article(n)));
    }

    private Response publish(Request req) {
        long building = req.building();
        long id = notices.publish(req.principal(), building, req.form("title"), req.form("body"), req.form("pinned") != null);
        return Response.redirect("/notices" + (req.principal().isAdmin() ? "?building=" + building : "") + "#notice-" + id);
    }

    private Response documents(Request req) {
        Principal p = req.principal();
        long building = req.building();
        StringBuilder b = new StringBuilder("<table><tr><th>Name</th><th>Kind</th><th>Size</th><th>Added</th><th>From</th></tr>");
        for (StoredDocument d : documents.list(p, building)) {
            b.append("<tr><td><a href=\"/documents/").append(d.id()).append("/download\">").append(d.originalName()).append("</a></td><td>")
                    .append(d.kind()).append("</td><td class=\"num\">").append(d.sizeBytes() / 1024 + 1).append(" KB</td><td>")
                    .append(d.createdOn()).append("</td><td>").append(d.sourceUrl() == null ? "" : d.sourceUrl()).append("</td></tr>");
        }
        b.append("</table>");
        if (p.manages(building)) {
            String kinds = kindOptions();
            b.append("<h2>Upload</h2><form method=\"post\" action=\"/documents\" enctype=\"multipart/form-data\">").append(Html.csrf(req))
                    .append(hiddenBuilding(req, building)).append("<select name=\"kind\">").append(kinds).append("</select>")
                    .append("<input type=\"file\" name=\"file\" accept=\".pdf,.png,.jpg,.jpeg\" required><button>Upload</button></form>")
                    .append("<h2>Fetch from an approved site</h2><form method=\"post\" action=\"/documents/fetch\">").append(Html.csrf(req))
                    .append(hiddenBuilding(req, building)).append("<select name=\"kind\">").append(kinds).append("</select>")
                    .append("<input type=\"url\" name=\"url\" required><button>Fetch</button></form>")
                    .append("<h2>Download everything</h2><form method=\"post\" action=\"/documents/archive\">").append(Html.csrf(req))
                    .append(hiddenBuilding(req, building)).append("<label>Label <input name=\"label\" pattern=\"[A-Za-z0-9._\\-]{1,40}\"></label>")
                    .append("<button>Download archive</button></form>");
        }
        return Response.html(Html.page(req, "Documents", b.toString()));
    }

    private Response upload(Request req) {
        long building = req.building();
        Multipart.Part f = req.file("file");
        documents.upload(req.principal(), building, FinancePages.enumOf(DocumentKind.class, req.requireForm("kind")), f.fileName(), f.data());
        return Response.redirect(Html.flash(documentsPath(req, building), "Document uploaded."));
    }

    private Response fetch(Request req) {
        long building = req.building();
        documents.fetch(req.principal(), building, FinancePages.enumOf(DocumentKind.class, req.requireForm("kind")), req.requireForm("url"));
        return Response.redirect(Html.flash(documentsPath(req, building), "Document fetched."));
    }

    private Response download(Request req) {
        Download d = documents.download(req.principal(), req.id());
        String type = d.document().contentType();
        if (type.startsWith("text/") || type.contains("svg")) return new Response(200, type, d.bytes(), java.util.List.of());
        return Response.download(type, d.document().originalName(), d.bytes());
    }

    private Response raw(Request req) {
        byte[] bytes = documents.raw(req.principal(), req.building(), req.query("file"));
        return Response.download("application/octet-stream", "document", bytes);
    }

    private Response archive(Request req) {
        Archive a = documents.archive(req.principal(), req.building(), req.form("label"));
        return Response.download("application/gzip", a.fileName(), a.bytes());
    }

    /** A notice with its text: escaped first, then line breaks and **bold** become markup. */
    private static String article(Notice n) {
        String body = esc(n.body()).replaceAll("\\*\\*([^*\\n]+)\\*\\*", "<strong>$1</strong>").replace("\r\n", "\n").replace("\n", "<br>\n");
        return "<article id=\"notice-" + n.id() + "\" class=\"notice" + (n.pinned() ? " pinned" : "") + "\"><h2><a href=\"/notices/" + n.id() + "\">"
                + n.title() + "</a></h2><p class=\"meta\">" + n.authorName() + " · " + n.publishedOn()
                + "</p><div class=\"body\">" + body + "</div></article>\n";
    }

    private static String kindOptions() {
        StringBuilder o = new StringBuilder();
        for (DocumentKind k : DocumentKind.values()) {
            if (k != DocumentKind.PROOF) o.append("<option>").append(k).append("</option>");
        }
        return o.toString();
    }

    private static String hiddenBuilding(Request req, long building) {
        return req.principal().isAdmin() ? "<input type=\"hidden\" name=\"building\" value=\"" + building + "\">" : "";
    }

    private static String documentsPath(Request req, long building) {
        return "/documents" + (req.principal().isAdmin() ? "?building=" + building : "");
    }
}
