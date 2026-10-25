package com.condodesk.web;

import com.condodesk.auth.AuthService;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Role;
import com.condodesk.repo.UserRepository.Listed;
import com.condodesk.web.Server.Gate;

import static com.condodesk.web.Html.esc;

/** The admin's user list and the form that creates an account. */
final class AdminPages {
    private final AuthService auth;

    AdminPages(AuthService auth) {
        this.auth = auth;
    }

    void register(Server s) {
        s.route("GET", "/admin/users", Gate.BROWSER, this::users);
        s.route("POST", "/admin/users", Gate.BROWSER, this::create);
    }

    private Response users(Request req) {
        StringBuilder b = new StringBuilder("<p><a href=\"/admin/billing\">Monthly billing</a> · <a href=\"/admin/statements\">Bank statement</a></p>")
                .append("<table><tr><th>Name</th><th>Email</th><th>Role</th><th>Building</th><th>Units</th><th>Active</th></tr>");
        for (Listed u : auth.users(req.principal(), req.queryLong("building"))) {
            b.append("<tr><td>").append(esc(u.name())).append("</td><td>").append(esc(u.email())).append("</td><td>").append(u.role())
                    .append("</td><td>").append(u.buildingId() == null ? "" : u.buildingId()).append("</td><td>").append(esc(u.units()))
                    .append("</td><td>").append(u.active() ? "yes" : "no").append("</td></tr>");
        }
        b.append("</table><h2>New account</h2><form method=\"post\" action=\"/admin/users\">").append(Html.csrf(req))
                .append("<label>Email <input type=\"email\" name=\"email\" required></label><label>Name <input name=\"name\" required></label>")
                .append("<label>Role <select name=\"role\"><option>RESIDENT</option><option>MANAGER</option><option>ADMIN</option></select></label>")
                .append("<label>Building id <input name=\"building\"></label><label>Unit id <input name=\"unit\"></label>")
                .append("<label>Residency <select name=\"kind\"><option>OWNER</option><option>TENANT</option></select></label>")
                .append("<label>Initial password <input type=\"password\" name=\"password\" required minlength=\"10\"></label><button>Create</button></form>");
        return Response.html(Html.page(req, "Users", b.toString()));
    }

    private Response create(Request req) {
        Role role = FinancePages.enumOf(Role.class, req.requireForm("role"));
        long id = auth.createUser(req.principal(), req.form("email"), req.form("name"), role, optionalLong(req, "building"), optionalLong(req, "unit"),
                req.form("kind"), req.form("password"));
        return Response.redirect(Html.flash("/admin/users", "Account " + id + " created."));
    }

    private static Long optionalLong(Request req, String name) {
        String v = req.form(name);
        if (v == null) return null;
        try {
            return Long.parseLong(v);
        } catch (NumberFormatException e) {
            throw AppException.invalid(name + " must be a number.");
        }
    }
}
