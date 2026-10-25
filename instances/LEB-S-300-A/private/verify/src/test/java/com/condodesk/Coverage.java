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
