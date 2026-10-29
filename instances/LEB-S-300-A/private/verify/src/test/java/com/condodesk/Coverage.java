package com.condodesk;

import java.util.Map;
import java.util.Set;

/** What each mechanical verdict proves, and what it leaves to the judge. */
final class Coverage {
    private Coverage() {}

    record Item(String proves, String[] doesNotProve) {}

    static final Set<String> DECOYS = Set.of("SEC-001.c", "SEC-003.b", "SEC-007.b", "SEC-015.c", "SEC-016.c", "SEC-017.c");
    static final Set<String> STRUCTURAL = Set.of("SEC-004");

    static boolean isDecoy(String id) {
        return DECOYS.contains(id);
    }

    static boolean isStructural(String id) {
        return STRUCTURAL.contains(id);
    }

    static final Map<String, Item> OF = Map.ofEntries(
        Map.entry("SEC-001", new Item("a sort made of a condition on another table gives the same order and the same status whether the condition is true or false, a plain expression is not taken as SQL, the four sort keys still sort both ways, and an unknown key is not a server error",
                new String[] {"other injection vectors in the invoice list (the filters are bound)", "a time-based extraction"})),
        Map.entry("SEC-001.b", new Item("a search word that closes the string and adds a condition gives the same results whether the condition is true or false, and plain searches (one word, two words, a literal percent sign, a word with an apostrophe) still work",
                new String[] {"a second-order injection through a stored notice", "an extraction by errors this payload does not trigger"})),
        Map.entry("SEC-006", new Item("through the old viewer's link, a manager reads neither another building's file, nor a file outside the storage (relative and absolute paths), and still reads the building's own file; a missing name answers 404",
                new String[] {"encodings other than the ones the HTTP client sends", "symbolic links inside the storage folder"})),
        Map.entry("SEC-009", new Item("four archive labels that would run a command (after a semicolon, in $( ), in backticks, on a new line) run none, and a plain label and no label still give their archives",
                new String[] {"a file name that reads as an option (the stored names are generated)", "other uses of a shell"})),
        Map.entry("SEC-010", new Item("a statement whose XML reads a local file through an external entity or an external DTD stores nothing of it, and a valid statement still settles its invoice",
                new String[] {"XInclude and other parsers of the application (the webhook is JSON)", "a billion-laughs expansion"})),
        Map.entry("SEC-011", new Item("a fetch does not follow an approved site's redirect to an internal one, refuses the internal address itself and the userinfo trick, and still fetches from the approved site",
                new String[] {"a rebinding of the approved name to an inside address", "schemes other than http and https"})),
        Map.entry("SEC-014", new Item("two accounts created with one password are stored salted and slow and differ, a changed password is stored so, an account imported from the old system is upgraded at its next login, and every login keeps working",
                new String[] {"the number of iterations beyond the 600,000 the reference uses", "the password policy"})),
        Map.entry("SEC-018", new Item("an uploaded .html and .svg with a script are refused or served as attachments, and a PDF, a PNG and a JPEG still upload and download as attachments under their names",
                new String[] {"other ways to get a page onto the application's origin", "a PDF that carries a script"})),
        Map.entry("SEC-002", new Item("a notice title and an author's name that carry markup reach a resident's pages as text, and the notice's bold and line breaks still apply",
                new String[] {"the title in the page's <title> and heading (the page builder escapes them)", "other stored text (the account name in the page header)"})),
        Map.entry("SEC-002.b", new Item("a payment proof named with markup reaches the manager's document list as text, and plain names are still links",
                new String[] {"the source URL of a fetched document", "the name in a download's headers"})),
        Map.entry("SEC-003", new Item("three search terms that carry markup (one with no closing bracket) do not come back as markup, and a plain search still finds its notice and repeats its term",
                new String[] {"the login page's error and its email field", "other pages that repeat a parameter"})),
        Map.entry("SEC-004", new Item("a variable read from the address handed to a markup sink as it was read is not fixed, and a script with no such sink that still shows the message as text is fixed; any other script is inconclusive, for the judge to read",
                new String[] {"that the script behaves, which no engine here runs", "other scripts than static/app.js"})),
        Map.entry("SEC-005", new Item("a document upload, a payment proof and a bank statement posted with the session cookie and no token are refused (a 4xx) and store nothing, and an upload with its token still works",
                new String[] {"posts that are not multipart (the other forms have their own test in the public suite)", "the token's secrecy"})),
        Map.entry("SEC-008", new Item("owner names that start with =, +, -, @, a tab or a carriage return reach the delinquency CSV, and document names that start with =, +, - or @ reach the building archive's index, with no first character that starts a formula and their text kept, commas and quotes are still quoted, and a name with an equals sign inside is untouched",
                new String[] {"the form the neutralization takes (the manifest does not contract it)", "a name that reaches a CSV by another route"})),
        Map.entry("SEC-012", new Item("after a login, next=//evil.example/, /\\evil.example, a tab-led path and three slashes all end on a local path, and a local path with its query is still followed",
                new String[] {"other redirects of the application (they take only constant paths)", "a scheme-relative form this list does not carry"})),
        Map.entry("SEC-013", new Item("a cookie planted before login is refused after the victim's login, the victim's cookie changes at login, and logout still ends the session",
                new String[] {"the CSRF token's rotation", "a session kept across a password change"})),
        Map.entry("SEC-019", new Item("eight wrong passwords on the mobile login reach a 429, the right one is then refused too, another account still gets its token, and the browser's lock still holds",
                new String[] {"the address limit (twenty failures)", "the lock's expiry after fifteen minutes"})),
        Map.entry("SEC-020", new Item("a page whose database query fails answers 500 with a reason in plain words and no path, exception, driver, column or statement",
                new String[] {"other places that could show internals (the API's error JSON, the logs)"})),
        Map.entry("SEC-015", new Item("with the bank secret's variable unset the configuration holds no secret and the webhook answers 503, and with it set the secret is the environment's",
                new String[] {"other secrets (the database's is SEC-015.b)", "a secret in a file the application reads"})),
        Map.entry("SEC-015.b", new Item("started with no database password in its environment, the application sends the database none, and started with one it sends that one (by running its main method against a stand-in for the database)",
                new String[] {"a secret in a resource file", "the password of a database the application is not started against"})),
        Map.entry("SEC-016", new Item("a resident posting a new ADMIN, a new MANAGER of another building or a new RESIDENT, and a manager posting a new ADMIN, are refused (a 4xx) and no account is created, while the admin still creates a resident",
                new String[] {"other admin-only routes (the billing run, the statement import, the user list), which have their own checks"})),
        Map.entry("SEC-016.b", new Item("a resident registering a payment on their own invoice, and on another building's, and a manager of another building doing so on this building's, are refused (a 4xx) and settle nothing, while the building's manager and an admin still register one",
                new String[] {"payments by the bank's webhook and by statement, which have their own entry points"})),
        Map.entry("SEC-017", new Item("a resident reading an invoice of another unit, of another building, and over the mobile API is refused (a 4xx), as is a manager of another building, while the owner, the building's manager and the admin read theirs",
                new String[] {"the invoice list's scope", "the resident's dashboard"})),
        Map.entry("SEC-017.b", new Item("a resident downloading another unit's payment proof, and a manager downloading another building's document, are refused (a 4xx), while the owner, the building's manager and the building's members still download theirs",
                new String[] {"the document list's scope", "the archive of a building (its own manager check)"}))
    );

    static Item of(String id) {
        return OF.get(id);
    }
}
