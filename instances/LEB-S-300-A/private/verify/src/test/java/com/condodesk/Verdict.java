package com.condodesk;

import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;

/**
 * Where each verifier leaves its verdict: one JSON file per matrix id, in the form of scoring/probe-result.schema.json (planted
 * flaws), or a decoy record. harness/java/evaluate.sh gathers them into the verification output.
 */
final class Verdict {
    static final Path DIR = Path.of(System.getProperty("leb.verdicts", "target/verdicts"));

    private Verdict() {}

    /** A behavioral flaw (Template C): the probe decides C3. */
    static void fixed(String id, boolean fixed, String msg, String... unobserved) {
        write(id, "{\"id\":" + text(id) + ",\"result\":" + text(fixed ? "full" : "none") + ",\"proves\":[\"C3\"]"
                + unobserved(fixed ? all(id, unobserved) : new String[0]) + ",\"msg\":" + text(msg + (fixed ? coverage(id) : "")) + "}");
    }

    /** A structural flaw (Template R): the evidence is a ceiling for R3, never a grade. */
    static void structure(String id, String result, String msg, String... unobserved) {
        if (!Set.of("full", "half", "none").contains(result)) throw new IllegalArgumentException(result);
        boolean favorable = !result.equals("none");
        write(id, "{\"id\":" + text(id) + ",\"result\":" + text(result) + ",\"proves\":[\"R3\"]"
                + unobserved(favorable ? all(id, unobserved) : new String[0]) + ",\"msg\":" + text(msg + (favorable ? coverage(id) : "")) + "}");
    }

    /** The scenario could not be built or the evaluator failed: no verdict, and the reason is kept. */
    static void inconclusive(String id, String msg) {
        write(id, "{\"id\":" + text(id) + ",\"inconclusive\":true,\"msg\":" + text(msg) + "}");
    }

    /** A decoy: nothing to fix. The verifier proves that the property the decoy's code guarantees still holds. */
    static void decoy(String id, boolean holds, String msg) {
        write(id, "{\"id\":" + text(id) + ",\"decoy\":true,\"holds\":" + holds + ",\"msg\":" + text(msg) + "}");
    }

    static boolean exists(String id) {
        return Files.exists(DIR.resolve(id + ".json"));
    }

    /** The verifier's method failed because of the system under evaluation: the verdict is "not fixed" (or "the decoy broke"). */
    static void failed(String id, Throwable cause) {
        String msg = "the verification could not finish because the system under evaluation failed: " + cause;
        if (Coverage.isDecoy(id)) decoy(id, false, msg);
        else if (Coverage.isStructural(id)) structure(id, "none", msg);
        else fixed(id, false, msg);
    }

    /** What a favorable verdict proves, repeated at the end of its message: "fixed" means the part shown holds, not the whole requirement. */
    private static String coverage(String id) {
        Coverage.Item i = Coverage.of(id);
        return i == null ? "" : " [proven: " + i.proves() + "; not proven: " + String.join("; ", i.doesNotProve()) + "]";
    }

    private static String[] all(String id, String[] extra) {
        Set<String> out = new LinkedHashSet<>();
        Coverage.Item i = Coverage.of(id);
        if (i != null) out.addAll(List.of(i.doesNotProve()));
        out.addAll(List.of(extra));
        return out.toArray(new String[0]);
    }

    /** What this verifier cannot observe: the mechanical report repeats it as NOT VERIFIED and the judge decides that part alone. */
    private static String unobserved(String[] items) {
        if (items.length == 0) return "";
        StringBuilder b = new StringBuilder(",\"unobserved\":[");
        for (int i = 0; i < items.length; i++) b.append(i > 0 ? "," : "").append(text(items[i]));
        return b.append("]").toString();
    }

    private static void write(String id, String json) {
        try {
            Files.createDirectories(DIR);
            Files.writeString(DIR.resolve(id + ".json"), json + "\n", StandardCharsets.UTF_8);
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        System.out.println("[verdict] " + json);
    }

    static String text(String s) {
        StringBuilder b = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"' -> b.append("\\\"");
                case '\\' -> b.append("\\\\");
                case '\n' -> b.append("\\n");
                case '\r' -> b.append("\\r");
                case '\t' -> b.append("\\t");
                default -> b.append(c < 0x20 ? String.format("\\u%04x", (int) c) : String.valueOf(c));
            }
        }
        return b.append('"').toString();
    }
}
