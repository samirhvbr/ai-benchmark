package com.condodesk.docs;

import com.condodesk.domain.AppException;

import java.io.IOException;
import java.io.InputStream;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.net.http.HttpTimeoutException;
import java.time.Duration;
import java.util.Locale;
import java.util.Set;

/**
 * Downloads a document a manager points to. Only hosts on the configured allowlist are contacted (an entry is a host, or
 * host:port), redirects are not followed, and the body is cut at the upload limit.
 */
public final class UrlFetcher {

    public record Fetched(String fileName, byte[] body) {}

    private final Set<String> allowedHosts;
    private final int maxBytes;
    private final HttpClient client = HttpClient.newBuilder()
            .followRedirects(HttpClient.Redirect.NEVER)
            .connectTimeout(Duration.ofSeconds(5))
            .build();

    public UrlFetcher(Set<String> allowedHosts, int maxBytes) {
        this.allowedHosts = allowedHosts;
        this.maxBytes = maxBytes;
    }

    public Fetched fetch(String url) {
        URI uri = allowedUri(url);
        HttpRequest req = HttpRequest.newBuilder(uri).timeout(Duration.ofSeconds(10)).GET().build();
        try {
            HttpResponse<InputStream> resp = client.send(req, HttpResponse.BodyHandlers.ofInputStream());
            try (InputStream in = resp.body()) {
                if (resp.statusCode() != 200) throw AppException.invalid("The server answered " + resp.statusCode() + ".");
                byte[] body = in.readNBytes(maxBytes + 1);
                if (body.length > maxBytes) throw AppException.invalid("The document is larger than the limit.");
                String path = uri.getPath() == null ? "" : uri.getPath();
                String name = path.substring(path.lastIndexOf('/') + 1);
                return new Fetched(name.isEmpty() ? "document" : name, body);
            }
        } catch (HttpTimeoutException e) {
            throw AppException.invalid("The server did not answer in time.");
        } catch (IOException e) {
            throw AppException.invalid("The document could not be downloaded.");
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            throw AppException.unavailable("Interrupted.");
        }
    }

    URI allowedUri(String url) {
        URI uri;
        try {
            uri = URI.create(url == null ? "" : url.trim());
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("That is not a valid address.");
        }
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
        if (!(scheme.equals("https") || scheme.equals("http")) || uri.getHost() == null || uri.getRawUserInfo() != null) {
            throw AppException.invalid("Only http and https addresses of a host are accepted.");
        }
        String host = uri.getHost().toLowerCase(Locale.ROOT);
        String authority = uri.getPort() == -1 ? host : host + ":" + uri.getPort();
        if (!allowedHosts.contains(authority)) throw AppException.invalid("Documents can only be fetched from the approved sites.");
        return uri;
    }
}
