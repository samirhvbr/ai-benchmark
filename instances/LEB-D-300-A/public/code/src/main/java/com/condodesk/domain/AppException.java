package com.condodesk.domain;

/** A failure the user is told about, with its HTTP status and a stable code. Its message is safe to show. */
public final class AppException extends RuntimeException {
    private final int status;
    private final String code;

    public AppException(int status, String code, String message) {
        super(message);
        this.status = status;
        this.code = code;
    }

    public int status() {
        return status;
    }

    public String code() {
        return code;
    }

    public static AppException invalid(String message) {
        return new AppException(400, "invalid", message);
    }

    public static AppException unauthorized() {
        return new AppException(401, "unauthorized", "Please log in.");
    }

    public static AppException forbidden() {
        return new AppException(403, "forbidden", "You are not allowed to do that.");
    }

    public static AppException notFound() {
        return new AppException(404, "not_found", "Not found.");
    }

    public static AppException conflict(String message) {
        return new AppException(409, "conflict", message);
    }

    public static AppException unsupported(String message) {
        return new AppException(415, "unsupported_media_type", message);
    }

    public static AppException tooMany() {
        return new AppException(429, "too_many_attempts", "Too many failed attempts. Try again later.");
    }

    public static AppException unavailable(String message) {
        return new AppException(503, "unavailable", message);
    }
}
