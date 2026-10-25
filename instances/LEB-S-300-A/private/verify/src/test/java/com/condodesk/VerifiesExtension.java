package com.condodesk;

import org.junit.jupiter.api.extension.ExtensionContext;
import org.junit.jupiter.api.extension.TestExecutionExceptionHandler;

/**
 * When a verification method ends with an exception, whose fault is it? One situation only becomes a verdict: the system under
 * evaluation answered a step of the scenario with an error (the login the verifier does fails, a billing run is refused). That is
 * the system broken, and the method's verdicts become "not fixed". Any other exception (database, network, the HTTP client, the
 * verifier's own code, a body that is not JSON) has an origin this code cannot establish: it propagates, no verdict is written,
 * and the report says "no mechanical evidence".
 */
final class VerifiesExtension implements TestExecutionExceptionHandler {
    @Override
    public void handleTestExecutionException(ExtensionContext context, Throwable t) throws Throwable {
        Verifies v = context.getRequiredTestMethod().getAnnotation(Verifies.class);
        if (v == null || v.value().length == 0 || !theSystemRefusedTheScenario(t)) throw t;
        for (String id : v.value()) {
            if (!Verdict.exists(id)) Verdict.failed(id, t);
        }
    }

    /** The scenario helpers (TestBase's and VBase's) report a defined error answer to a preparation step this way. */
    static boolean theSystemRefusedTheScenario(Throwable t) {
        if (!(t instanceof IllegalStateException) || t.getCause() != null) return false;
        String m = String.valueOf(t.getMessage());
        return m.matches("(?s)^(login of .* failed: .*|token for .* failed: .*|\\S+ answered \\d{3}: .*)$");
    }
}
