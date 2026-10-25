package com.condodesk.web;

import com.condodesk.auth.AuthService;
import com.condodesk.auth.Sessions;
import com.condodesk.auth.Sessions.Session;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Principal;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.logging.Level;
import java.util.logging.Logger;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** The JDK HTTP server: routing, the session and token gates, CSRF, body limits and the shape of errors. */
public final class Server {
    private static final Logger LOG = Logger.getLogger(Server.class.getName());
    private static final SecureRandom RANDOM = new SecureRandom();

    /** Who may reach a route. */
    public enum Gate {
        /** anyone, no session: static files, the bank webhook, the mobile login */
        OPEN,
        /** a browser that need not be logged in, with a session for the CSRF token: the login form */
        GUEST,
        /** a logged-in browser session */
        BROWSER,
        /** a mobile API token */
        API
    }

    @FunctionalInterface
    public interface Handler {
        Response handle(Request req) throws Exception;
    }

    private record Route(String method, Pattern pattern, List<String> names, Gate gate, Handler handler) {}

    private final Sessions sessions;
    private final AuthService auth;
    private final boolean secureCookies;
    private final int maxBody;
    private final List<Route> routes = new ArrayList<>();
    private HttpServer http;
    private ExecutorService executor;

    public Server(Sessions sessions, AuthService auth, boolean secureCookies, int maxUploadBytes) {
        this.sessions = sessions;
        this.auth = auth;
        this.secureCookies = secureCookies;
        this.maxBody = maxUploadBytes + 64 * 1024;
    }

    public void route(String method, String path, Gate gate, Handler handler) {
        List<String> names = new ArrayList<>();
        Matcher m = Pattern.compile("\\{([a-z]+)}").matcher(path);
        StringBuilder regex = new StringBuilder("^");
        int end = 0;
        while (m.find()) {
            regex.append(Pattern.quote(path.substring(end, m.start()))).append("([^/]+)");
            names.add(m.group(1));
            end = m.end();
        }
        regex.append(Pattern.quote(path.substring(end))).append("$");
        routes.add(new Route(method, Pattern.compile(regex.toString()), names, gate, handler));
    }

    public int start(int port) {
        try {
            http = HttpServer.create(new InetSocketAddress(port), 0);
        } catch (IOException e) {
            throw new IllegalStateException("cannot listen on port " + port, e);
        }
        executor = Executors.newFixedThreadPool(16);
        http.setExecutor(executor);
        http.createContext("/", this::serve);
        http.start();
        return http.getAddress().getPort();
    }

    public void stop() {
        if (http != null) http.stop(0);
        if (executor != null) executor.shutdownNow();
    }

    private void serve(HttpExchange ex) throws IOException {
        Route route = null;
        Request req = null;
        String path = ex.getRequestURI().getPath();
        List<String[]> extra = new ArrayList<>();
        Response resp;
        try {
            Map<String, String> vars = new LinkedHashMap<>();
            route = match(ex.getRequestMethod(), path, vars);
            if (route == null) throw AppException.notFound();
            req = new Request(ex, vars, readBody(ex));
            resp = admit(route, req, extra);
            if (resp == null) resp = route.handler().handle(req);
        } catch (AppException e) {
            resp = failure(route, req, path, e.status(), e.code(), e.getMessage());
        } catch (Exception e) {
            String incident = HexFormat.of().formatHex(randomBytes(4));
            LOG.log(Level.SEVERE, "incident " + incident + " on " + ex.getRequestMethod() + " " + path, e);
            resp = failure(route, req, path, 500, "internal_error", "Something went wrong (incident " + incident + ").");
        }
        write(ex, route, resp, extra);
    }

    /** Applies the route's gate. Returns a response when the request stops here, null when the handler runs. */
    private Response admit(Route route, Request req, List<String[]> extra) {
        switch (route.gate()) {
            case OPEN -> {
                return null;
            }
            case API -> {
                String h = req.header("Authorization");
                String token = h != null && h.startsWith("Bearer ") ? h.substring(7).trim() : null;
                req.principal = sessions.resolveApiToken(token).flatMap(auth::principal).orElseThrow(AppException::unauthorized);
                return null;
            }
            default -> {
                String token = req.cookie(Sessions.COOKIE);
                Optional<Session> session = sessions.resolve(token);
                Optional<Principal> principal = session.map(Session::userId).flatMap(auth::principal);
                if (route.gate() == Gate.BROWSER && principal.isEmpty()) {
                    if (req.method().equals("GET")) return Response.redirect("/login?next=" + Html.url(req.target()));
                    throw AppException.unauthorized();
                }
                if (session.isEmpty()) {
                    Sessions.Issued issued = sessions.anonymous();
                    session = Optional.of(issued.session());
                    token = issued.token();
                    extra.add(new String[]{"Set-Cookie", sessions.cookie(token, secureCookies)});
                }
                req.session = session.get();
                req.sessionToken = token;
                req.principal = principal.orElse(null);
                if (!req.method().equals("GET") && !csrfMatches(req)) {
                    throw new AppException(403, "csrf", "This form has expired. Reload the page and try again.");
                }
                return null;
            }
        }
    }

    private static boolean csrfMatches(Request req) {
        String sent = req.header("X-CSRF-Token");
        if (sent == null) sent = req.form("_csrf");
        return sent != null && MessageDigest.isEqual(sent.getBytes(StandardCharsets.UTF_8), req.csrf().getBytes(StandardCharsets.UTF_8));
    }

    private Route match(String method, String path, Map<String, String> vars) {
        for (Route r : routes) {
            Matcher m = r.pattern().matcher(path);
            if (r.method().equals(method) && m.matches()) {
                for (int i = 0; i < r.names().size(); i++) vars.put(r.names().get(i), m.group(i + 1));
                return r;
            }
        }
        return null;
    }

    private byte[] readBody(HttpExchange ex) throws IOException {
        try (InputStream in = ex.getRequestBody()) {
            byte[] body = in.readNBytes(maxBody + 1);
            if (body.length > maxBody) throw new AppException(413, "too_large", "The request is too large.");
            return body;
        }
    }

    private static Response failure(Route route, Request req, String path, int status, String code, String message) {
        boolean api = route != null && route.gate() == Gate.API || path.startsWith("/api/");
        if (api) return Response.json(status, Map.of("error", Map.of("code", code, "message", message)));
        return Response.html(status, Html.error(req, status, message));
    }

    private static void write(HttpExchange ex, Route route, Response resp, List<String[]> extra) throws IOException {
        var h = ex.getResponseHeaders();
        if (resp.contentType() != null) h.set("Content-Type", resp.contentType());
        for (String[] x : resp.headers()) h.add(x[0], x[1]);
        for (String[] x : extra) h.add(x[0], x[1]);
        h.set("X-Content-Type-Options", "nosniff");
        if (resp.contentType() != null && resp.contentType().startsWith("text/html")) {
            h.set("X-Frame-Options", "DENY");
            h.set("Referrer-Policy", "same-origin");
        }
        if (route != null && route.gate() != Gate.OPEN) h.set("Cache-Control", "no-store");
        byte[] body = resp.body();
        ex.sendResponseHeaders(resp.status(), body.length == 0 ? -1 : body.length);
        if (body.length > 0) {
            try (OutputStream out = ex.getResponseBody()) {
                out.write(body);
            }
        }
        ex.close();
    }

    private static byte[] randomBytes(int n) {
        byte[] b = new byte[n];
        RANDOM.nextBytes(b);
        return b;
    }
}
