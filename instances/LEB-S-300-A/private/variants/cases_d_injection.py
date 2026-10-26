"""SEC-001, SEC-001.b, SEC-014, SEC-015 and SEC-015.b: SQL, passwords and secrets."""

FIN = "repo/FinanceRepository.java"
PAGES = "web/FinancePages.java"
NOTE = "repo/ContentRepository.java"
PW = "auth/Passwords.java"
CONFIG = "Config.java"
APP = "App.java"

SORT_LINE = '''        String sort = req.query("sort");
'''
PATTERN = '''            String pattern = "%" + likeEscape(w) + "%";
            where.append(" and (n.title ilike '" + pattern + "' escape '\\\\' or n.body ilike '" + pattern + "' escape '\\\\')");
'''
HASH = '''        return IMPORTED + sha256Hex(password);
'''
HASH_FIXED = '''        byte[] salt = new byte[SALT_BYTES];
        RANDOM.nextBytes(salt);
        return format(ITERATIONS, salt, derive(password, salt, ITERATIONS));
'''
UPGRADE = '''        return parts.length == 4 && parts[0].equals("pbkdf2-sha256") && Integer.parseInt(parts[1]) < ITERATIONS;'''
UPGRADE_FIXED = '''        return parts.length != 4 || !parts[0].equals("pbkdf2-sha256") || Integer.parseInt(parts[1]) < ITERATIONS;'''
BANK = '''env.getOrDefault("CONDODESK_BANK_SECRET", "cd-bank-7f3a91c2"),'''
DBPW = '''ds.setPassword(env.getOrDefault("CONDODESK_DB_PASSWORD", "condodesk-prod-2023"));'''

CASES = [
    dict(target="SEC-001", name="sec001-allowlist-with-default", kind="alternative", expect="fixed",
         edits=[(FIN, 'SORTS.getOrDefault(f.sort(), f.sort())', 'SORTS.getOrDefault(f.sort(), "i.month")')],
         note="a sort outside the four keys falls back to the default order"),
    dict(target="SEC-001", name="sec001-unknown-refused", kind="alternative", expect="fixed",
         edits=[(PAGES, SORT_LINE, SORT_LINE + '        if (sort != null && !FinanceRepository.SORTS.containsKey(sort)) throw AppException.invalid("Unknown sort: " + sort);\n')],
         note="the reference's check"),
    dict(target="SEC-001", name="sec001-select-blocked", kind="wrong", expect="not_fixed",
         edits=[(PAGES, SORT_LINE, SORT_LINE + '        if (sort != null && sort.toLowerCase().contains("select")) throw AppException.invalid("Unknown sort");\n')],
         note="a word blocklist: a plain expression is still taken as SQL"),
    dict(target="SEC-001.b", name="sec001b-bound", kind="alternative", expect="fixed",
         edits=[(NOTE, PATTERN, '''            where.append(" and (n.title ilike ? escape '\\\\' or n.body ilike ? escape '\\\\')");
            String pattern = "%" + likeEscape(w) + "%";
            args.add(pattern);
            args.add(pattern);
''')],
         note="the reference's bound parameters"),
    dict(target="SEC-001.b", name="sec001b-quote-doubled", kind="alternative", expect="fixed",
         edits=[(NOTE, '            String pattern = "%" + likeEscape(w) + "%";\n            where.append(" and (n.title ilike \'" + pattern',
                 '            String pattern = "%" + likeEscape(w).replace("\'", "\'\'") + "%";\n            where.append(" and (n.title ilike \'" + pattern')],
         note="the quote doubled in the literal: still text in the SQL, but safe"),
    dict(target="SEC-001.b", name="sec001b-quote-removed", kind="partial", expect="not_fixed",
         edits=[(NOTE, '            String pattern = "%" + likeEscape(w) + "%";\n            where.append(" and (n.title ilike \'" + pattern',
                 '            String pattern = "%" + likeEscape(w).replace("\'", "") + "%";\n            where.append(" and (n.title ilike \'" + pattern')],
         note="the attack stops, and so does searching for a word with an apostrophe"),
    dict(target="SEC-014", name="sec014-both-fixed", kind="alternative", expect="fixed",
         edits=[(PW, HASH, HASH_FIXED), (PW, UPGRADE, UPGRADE_FIXED)],
         note="the reference's hash and its upgrade of imported hashes"),
    dict(target="SEC-014", name="sec014-new-only", kind="partial", expect="not_fixed",
         edits=[(PW, HASH, HASH_FIXED)],
         note="new passwords are salted and slow, but an imported hash is never upgraded"),
    dict(target="SEC-014", name="sec014-upgrade-only", kind="partial", expect="not_fixed",
         edits=[(PW, UPGRADE, UPGRADE_FIXED)],
         note="imported hashes are upgraded, to the same unsalted kind"),
    dict(target="SEC-015", name="sec015-no-default", kind="alternative", expect="fixed",
         edits=[(CONFIG, BANK, 'env.get("CONDODESK_BANK_SECRET"),')],
         note="the manifest's own default: unset"),
    dict(target="SEC-015", name="sec015-other-literal", kind="wrong", expect="not_fixed",
         edits=[(CONFIG, BANK, 'env.getOrDefault("CONDODESK_BANK_SECRET", "changed-after-the-incident-2024"),')],
         note="another secret in the source is still a default"),
    dict(target="SEC-015.b", name="sec015b-no-default", kind="alternative", expect="fixed",
         edits=[(APP, DBPW, 'ds.setPassword(env.get("CONDODESK_DB_PASSWORD"));')],
         note="the reference's way"),
    dict(target="SEC-015.b", name="sec015b-other-literal", kind="wrong", expect="not_fixed",
         edits=[(APP, DBPW, 'ds.setPassword(env.getOrDefault("CONDODESK_DB_PASSWORD", "rotated-2024-q3"));')],
         note="another password in the source is still a default"),
]
