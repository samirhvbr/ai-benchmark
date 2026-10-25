package com.condodesk;

/** Base of the security verifiers: scenario steps that the system under evaluation refuses fail as "X answered NNN: …". */
abstract class VBase extends TestBase {

    /** Fails the scenario, in the form VerifiesExtension reads as "the system refused a step". */
    static IllegalStateException refused(String step, R r) {
        return new IllegalStateException(step + " answered " + r.status() + ": " + abbreviate(r.body()));
    }

    static String abbreviate(String s) {
        return s == null ? "" : s.length() > 300 ? s.substring(0, 300) + "…" : s;
    }

    /** GET as the browser, requiring 200. */
    R ok(Browser b, String path) throws Exception {
        R r = b.get(path);
        if (r.status() != 200) throw refused("GET " + path, r);
        return r;
    }
}
