package com.condodesk;

import org.junit.jupiter.api.Test;

import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.file.Files;
import java.time.Clock;
import java.time.Duration;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** The static files, served without touching the database. This test also runs where there is no database at all. */
class StaticFilesTest {

    @Test
    void theScriptAndTheStylesheetAreServed() throws Exception {
        TestBase.SchemaDataSource unused = new TestBase.SchemaDataSource("never_used");
        App app = App.create(new Config(0, Files.createTempDirectory("condodesk-static"), false, Duration.ofMinutes(30), Set.of(), null,
                Config.DEFAULT_MAX_UPLOAD), unused, Clock.systemUTC());
        String base = "http://127.0.0.1:" + app.start();
        try {
            HttpClient http = HttpClient.newHttpClient();
            HttpResponse<String> js = http.send(HttpRequest.newBuilder(URI.create(base + "/static/app.js")).build(), HttpResponse.BodyHandlers.ofString());
            assertEquals(200, js.statusCode());
            assertTrue(js.headers().firstValue("Content-Type").orElse("").startsWith("text/javascript"));
            HttpResponse<String> css = http.send(HttpRequest.newBuilder(URI.create(base + "/static/app.css")).build(), HttpResponse.BodyHandlers.ofString());
            assertEquals(200, css.statusCode());
            assertEquals(404, http.send(HttpRequest.newBuilder(URI.create(base + "/static/other.js")).build(), HttpResponse.BodyHandlers.ofString()).statusCode());
        } finally {
            app.stop();
        }
    }
}
