package com.condodesk;

import java.util.Map;
import java.util.Set;

/** What each mechanical verdict proves, and what it leaves to the judge. */
final class Coverage {
    private Coverage() {}

    record Item(String proves, String[] doesNotProve) {}

    static final Set<String> DECOYS = Set.of();
    static final Set<String> STRUCTURAL = Set.of("SEC-004", "SEC-015.b");

    static boolean isDecoy(String id) {
        return DECOYS.contains(id);
    }

    static boolean isStructural(String id) {
        return STRUCTURAL.contains(id);
    }

    static final Map<String, Item> OF = Map.ofEntries(
        Map.entry("SEC-012", new Item("after a login, next=//evil.example/, /\\evil.example, a tab-led path and three slashes all end on a local path, and a local path with its query is still followed",
                new String[] {"other redirects of the application (they take only constant paths)", "a scheme-relative form this list does not carry"})),
        Map.entry("SEC-013", new Item("a cookie planted before login is refused after the victim's login, the victim's cookie changes at login, and logout still ends the session",
                new String[] {"the CSRF token's rotation", "a session kept across a password change"})),
        Map.entry("SEC-019", new Item("eight wrong passwords on the mobile login reach a 429, the right one is then refused too, another account still gets its token, and the browser's lock still holds",
                new String[] {"the address limit (twenty failures)", "the lock's expiry after fifteen minutes"})),
        Map.entry("SEC-020", new Item("an internal error's page shows no path, exception or file name and carries an incident id",
                new String[] {"other places that could show internals (the API's error JSON, the logs)"})),
        Map.entry("SEC-015", new Item("with the bank secret's variable unset the configuration holds no secret and the webhook answers 503, and with it set the secret is the environment's",
                new String[] {"other secrets (the database's is SEC-015.b)", "a secret in a file the application reads"})),
        Map.entry("SEC-015.b", new Item("no password setting has a literal default in the main code",
                new String[] {"a password written as a constant under another name", "a secret in a resource file"})),
        Map.entry("SEC-016", new Item("a resident and a manager who post a new ADMIN account are refused (403) and no account is created, while the admin still creates a resident",
                new String[] {"other admin-only routes (the billing run, the statement import, the user list), which have their own checks"})),
        Map.entry("SEC-016.b", new Item("a resident registering a payment on their own invoice, and on another building's, is refused (403) and settles nothing, while the building's manager still registers one",
                new String[] {"payments by the bank's webhook and by statement, which have their own entry points"})),
        Map.entry("SEC-017", new Item("a resident reading an invoice of another unit, of another building, and over the mobile API gets 404, as does a manager of another building, while the owner, the building's manager and the admin read theirs",
                new String[] {"the invoice list's scope", "the resident's dashboard"})),
        Map.entry("SEC-017.b", new Item("a resident downloading another unit's payment proof, and a manager downloading another building's document, get 404, while the owner, the building's manager and the building's members still download theirs",
                new String[] {"the document list's scope", "the archive of a building (its own manager check)"}))
    );

    static Item of(String id) {
        return OF.get(id);
    }
}
