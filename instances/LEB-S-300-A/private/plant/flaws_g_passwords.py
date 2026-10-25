r"""LEB-S-300-A: the password storage row (../CATALOG.md section 2.3)."""

from paths import SRC

FLAWS = [
    # A new or changed password is stored as unsalted SHA-256 (the old system's format), and the old system's hashes are never upgraded.
    ("SEC-014", [
        (SRC + "auth/Passwords.java",
         r"""    public static String hash(String password) {
        byte[] salt = new byte[SALT_BYTES];
        RANDOM.nextBytes(salt);
        return format(ITERATIONS, salt, derive(password, salt, ITERATIONS));
    }
""",
         r"""    public static String hash(String password) {
        return IMPORTED + sha256Hex(password);
    }
"""),
        (SRC + "auth/Passwords.java",
         r"""        return parts.length != 4 || !parts[0].equals("pbkdf2-sha256") || Integer.parseInt(parts[1]) < ITERATIONS;
""",
         r"""        return parts.length == 4 && parts[0].equals("pbkdf2-sha256") && Integer.parseInt(parts[1]) < ITERATIONS;
"""),
    ]),
]
