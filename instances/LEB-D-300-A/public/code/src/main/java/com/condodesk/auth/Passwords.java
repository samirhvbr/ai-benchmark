package com.condodesk.auth;

import com.condodesk.domain.AppException;

import javax.crypto.SecretKeyFactory;
import javax.crypto.spec.PBEKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.util.Base64;
import java.util.HexFormat;
import java.util.Locale;
import java.util.Set;

/**
 * Password storage (PBKDF2-HMAC-SHA256 with a per-user salt) and the password policy. Accounts imported from the previous
 * system carry its unsalted SHA-256 hash ({@code sha256:<hex>}); they are verified as such and upgraded at their next login.
 */
public final class Passwords {
    private static final String IMPORTED = "sha256:";
    static final int ITERATIONS = 100_000;
    static final int MIN_LENGTH = 10;
    private static final int SALT_BYTES = 16;
    private static final int KEY_BITS = 256;
    private static final SecureRandom RANDOM = new SecureRandom();
    private static final Set<String> COMMON = Set.of(
            "1234567890", "12345678910", "123456789a", "password1234", "password123", "qwertyuiop", "qwerty12345",
            "iloveyou123", "admin12345", "letmein1234", "welcome1234", "condominium", "condodesk123", "0987654321");
    /** Verified against when the account does not exist, so that a missing account takes as long as a wrong password. */
    private static final String DUMMY = hash("dummy-password-never-used");

    private Passwords() {
    }

    public static String hash(String password) {
        byte[] salt = new byte[SALT_BYTES];
        RANDOM.nextBytes(salt);
        return format(ITERATIONS, salt, derive(password, salt, ITERATIONS));
    }

    public static boolean verify(String password, String stored) {
        if (stored != null && stored.startsWith(IMPORTED)) {
            byte[] expected = stored.substring(IMPORTED.length()).getBytes(StandardCharsets.US_ASCII);
            return MessageDigest.isEqual(expected, sha256Hex(password).getBytes(StandardCharsets.US_ASCII));
        }
        String[] parts = stored == null ? new String[0] : stored.split("\\$");
        if (parts.length != 4 || !parts[0].equals("pbkdf2-sha256")) return false;
        try {
            int iterations = Integer.parseInt(parts[1]);
            byte[] salt = Base64.getDecoder().decode(parts[2]);
            byte[] expected = Base64.getDecoder().decode(parts[3]);
            return MessageDigest.isEqual(expected, derive(password, salt, iterations));
        } catch (IllegalArgumentException e) {
            return false;
        }
    }

    /** Whether a verified hash should be replaced by a fresh one: an imported hash, or one with fewer iterations than today's. */
    public static boolean needsUpgrade(String stored) {
        String[] parts = stored.split("\\$");
        return parts.length != 4 || !parts[0].equals("pbkdf2-sha256") || Integer.parseInt(parts[1]) < ITERATIONS;
    }

    /** Spends the time of one verification and fails. */
    public static boolean verifyNothing(String password) {
        verify(password, DUMMY);
        return false;
    }

    /** Rejects a password that is too short, too common, or built from the account's email. */
    public static void checkPolicy(String password, String email) {
        if (password == null || password.length() < MIN_LENGTH) {
            throw AppException.invalid("The password must have at least " + MIN_LENGTH + " characters.");
        }
        String lower = password.toLowerCase(Locale.ROOT);
        if (COMMON.contains(lower)) throw AppException.invalid("This password is too common.");
        String local = email.toLowerCase(Locale.ROOT).split("@")[0];
        if (local.length() >= 4 && lower.contains(local)) throw AppException.invalid("The password must not contain your email.");
    }

    private static byte[] derive(String password, byte[] salt, int iterations) {
        try {
            PBEKeySpec spec = new PBEKeySpec(password.toCharArray(), salt, iterations, KEY_BITS);
            return SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).getEncoded();
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    /** The hex SHA-256 of a text: the imported accounts' hash, and how session and API tokens are stored. */
    static String sha256Hex(String text) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(text.getBytes(StandardCharsets.UTF_8)));
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    private static String format(int iterations, byte[] salt, byte[] key) {
        Base64.Encoder b64 = Base64.getEncoder();
        return "pbkdf2-sha256$" + iterations + "$" + b64.encodeToString(salt) + "$" + b64.encodeToString(key);
    }
}
