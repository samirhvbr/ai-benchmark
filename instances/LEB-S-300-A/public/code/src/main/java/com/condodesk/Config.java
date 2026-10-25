package com.condodesk;

import java.nio.file.Path;
import java.time.Duration;
import java.util.Arrays;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.stream.Collectors;

/**
 * Runtime settings. Secrets come from the environment only.
 *
 * @param port               HTTP port; 0 picks a free one
 * @param storageDir         where uploaded documents are kept, outside any served path
 * @param secureCookies      whether the session cookie carries the Secure attribute (true behind HTTPS)
 * @param sessionIdle        how long a browser session lives without a request
 * @param fetchAllowedHosts  the hosts (host or host:port) a manager may fetch a document from
 * @param bankSecret         the shared secret of the bank's webhook signature; null disables the webhook
 * @param maxUploadBytes     the largest document accepted
 */
public record Config(int port, Path storageDir, boolean secureCookies, Duration sessionIdle, Set<String> fetchAllowedHosts,
                     String bankSecret, int maxUploadBytes) {

    public static final int DEFAULT_MAX_UPLOAD = 5 * 1024 * 1024;

    public Config {
        fetchAllowedHosts = fetchAllowedHosts.stream().map(h -> h.toLowerCase(Locale.ROOT)).collect(Collectors.toUnmodifiableSet());
    }

    public static Config fromEnvironment(Map<String, String> env) {
        return new Config(
                Integer.parseInt(env.getOrDefault("CONDODESK_PORT", "8080")),
                Path.of(env.getOrDefault("CONDODESK_STORAGE", "/var/lib/condodesk/documents")),
                Boolean.parseBoolean(env.getOrDefault("CONDODESK_SECURE_COOKIES", "true")),
                Duration.ofMinutes(Long.parseLong(env.getOrDefault("CONDODESK_SESSION_IDLE_MINUTES", "30"))),
                Arrays.stream(env.getOrDefault("CONDODESK_FETCH_ALLOWED_HOSTS", "").split(","))
                        .map(String::trim).filter(s -> !s.isEmpty()).collect(Collectors.toSet()),
                env.getOrDefault("CONDODESK_BANK_SECRET", "cd-bank-7f3a91c2"),
                DEFAULT_MAX_UPLOAD);
    }
}
