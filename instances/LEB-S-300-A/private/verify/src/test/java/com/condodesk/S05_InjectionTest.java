package com.condodesk;

import org.junit.jupiter.api.Test;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Request text that reaches SQL: the invoice list's sort and the notice search. Rows SEC-001 and SEC-001.b of CATALOG.md section 2.1. */
class S05_InjectionTest extends VBase {

    private static List<String> order(R r) {
        List<String> ids = new ArrayList<>();
        Matcher m = Pattern.compile("href=\"/invoices/(\\d+)\"").matcher(r.body());
        while (m.find()) ids.add(m.group(1));
        return ids;
    }

    private static int total(R r) {
        Matcher m = Pattern.compile("(\\d+) notices for").matcher(r.body());
        return m.find() ? Integer.parseInt(m.group(1)) : -1;
    }

    @Test
    @Verifies({"SEC-001"})
    void sec001_theSortIsNeverSql() throws Exception {
        long jan = invoice(unitA101, "2026-01", LocalDate.parse("2026-02-10"), "300.00");
        long feb = invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "100.00");
        long mar = invoice(unitA102, "2026-03", LocalDate.parse("2026-04-10"), "200.00");
        Browser m = loggedIn(MANAGER_A);
        String admins = "(select count(*) from app_user where role = 'ADMIN')";
        R yes = m.get("/invoices?sort=" + enc("(case when " + admins + " > 0 then i.amount::text else i.month end)"));
        R no = m.get("/invoices?sort=" + enc("(case when " + admins + " > 99 then i.amount::text else i.month end)"));
        boolean orderLeak = yes.status() == 200 && no.status() == 200 && !order(yes).equals(order(no));
        R errYes = m.get("/invoices?sort=" + enc("(case when " + admins + " > 0 then i.id/0 else 1 end)"));
        R errNo = m.get("/invoices?sort=" + enc("(case when " + admins + " > 99 then i.id/0 else 1 end)"));
        boolean errorLeak = errYes.status() != errNo.status();
        R expr = m.get("/invoices?sort=" + enc("i.amount::text"));                    // an expression with no SELECT in it: a fix that blocks words is not a fix
        R plain = m.get("/invoices");
        boolean exprLeak = expr.status() == 500 || expr.status() == 200 && !order(expr).equals(order(plain));
        List<String> byAmount = order(ok(m, "/invoices?sort=amount"));
        List<String> byAmountDesc = order(ok(m, "/invoices?sort=amount&dir=desc"));
        boolean keys = byAmount.equals(List.of(String.valueOf(feb), String.valueOf(mar), String.valueOf(jan)))
                && byAmountDesc.equals(List.of(String.valueOf(jan), String.valueOf(mar), String.valueOf(feb)))
                && order(ok(m, "/invoices?sort=month")).equals(List.of(String.valueOf(jan), String.valueOf(feb), String.valueOf(mar)))
                && ok(m, "/invoices?sort=due").status() == 200 && ok(m, "/invoices?sort=unit").status() == 200;
        int unknown = m.get("/invoices?sort=password_hash").status();
        Verdict.fixed("SEC-001", !orderLeak && !errorLeak && !exprLeak && keys && unknown != 500, "a sort that is a condition on another table: the order depends on the condition: " + orderLeak + ", the status depends on it: "
                + errorLeak + " (" + errYes.status() + " against " + errNo.status() + "), a plain expression is taken as SQL: " + exprLeak + " (expected false, false, false); the four keys still sort, ascending and descending: " + keys + " (expected true); an unknown key answered "
                + unknown + " (expected anything but a server error)");
    }

    @Test
    @Verifies({"SEC-001.b"})
    void sec001b_aSearchWordIsNeverSql() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/notices");
        m.post("/notices", "title", "Water shutdown on Friday", "body", "The water is off from 9 to 11.");
        m.post("/notices", "title", "Garden meeting", "body", "Bring chairs. Discount 100% off for early birds.");
        m.post("/notices", "title", "Visit of Mr O'Brien", "body", "He arrives on Monday.");
        String cond = "x'/**/or/**/(select/**/count(*)/**/from/**/unit)>%d/**/or/**/n.title/**/ilike/**/'zzz";   // no underscore: likeEscape would change it
        R yes = m.get("/notices?q=" + enc(String.format(cond, 0)));
        R no = m.get("/notices?q=" + enc(String.format(cond, 99)));
        boolean leak = yes.status() != no.status() || total(yes) != total(no);
        boolean plain = total(ok(m, "/notices?q=water")) == 1 && total(ok(m, "/notices?q=" + enc("garden meeting"))) == 1 && total(ok(m, "/notices?q=" + enc("100%"))) == 1
                && total(ok(m, "/notices?q=" + enc("friday water"))) == 1 && total(m.get("/notices?q=" + enc("o'brien"))) == 1 && m.get("/notices?q=" + enc("a b c d e f")).status() == 400;
        Verdict.fixed("SEC-001.b", !leak && plain, "a search word that closes the string and adds a condition: the results depend on the condition: " + leak + " (" + yes.status() + "/" + total(yes) + " against "
                + no.status() + "/" + total(no) + ") (expected false); plain searches (one word, two words, a literal percent sign, a word with an apostrophe) still find their notice and six words are refused: " + plain + " (expected true)");
    }
}
