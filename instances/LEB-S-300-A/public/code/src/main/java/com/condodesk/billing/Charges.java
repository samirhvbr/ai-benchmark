package com.condodesk.billing;

import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.Unit;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.time.LocalDate;
import java.time.temporal.ChronoUnit;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** The money rules: how a budget is split among units, and what an invoice costs after its due date. */
public final class Charges {
    private static final BigDecimal HUNDRED = BigDecimal.valueOf(100);
    /** A month of interest is charged as thirty days. */
    private static final BigDecimal INTEREST_DAYS = BigDecimal.valueOf(30);

    private Charges() {
    }

    /**
     * Splits the budget by ideal fraction, in cents. Each unit gets the floor of its exact share; the cents left over go one each
     * to the units with the largest remainders (ties by label), so the shares add up to the budget exactly.
     */
    public static Map<Long, BigDecimal> split(BigDecimal budget, List<Unit> units) {
        BigDecimal cents = budget.movePointRight(2);
        BigDecimal fractions = units.stream().map(Unit::idealFraction).reduce(BigDecimal.ZERO, BigDecimal::add);
        record Share(Unit unit, long floor, BigDecimal remainder) {}
        List<Share> shares = units.stream().map(u -> {
            BigDecimal exact = cents.multiply(u.idealFraction()).divide(fractions, 12, RoundingMode.HALF_EVEN);
            BigDecimal floor = exact.setScale(0, RoundingMode.FLOOR);
            return new Share(u, floor.longValueExact(), exact.subtract(floor));
        }).toList();
        long left = cents.longValueExact() - shares.stream().mapToLong(Share::floor).sum();
        List<Share> byRemainder = shares.stream()
                .sorted(Comparator.comparing(Share::remainder).reversed().thenComparing(s -> s.unit().label())).toList();
        Map<Long, BigDecimal> out = new LinkedHashMap<>();
        for (Share s : shares) out.put(s.unit().id(), BigDecimal.valueOf(s.floor()));
        for (int i = 0; i < left; i++) out.merge(byRemainder.get(i).unit().id(), BigDecimal.ONE, BigDecimal::add);
        out.replaceAll((id, c) -> c.movePointLeft(2).setScale(2, RoundingMode.UNNECESSARY));
        return out;
    }

    /** The amount due on a date: the invoice amount, plus the late fee and pro rata interest once the due date has passed. */
    public static BigDecimal amountDue(BigDecimal amount, LocalDate dueDate, Building building, LocalDate on) {
        long days = daysLate(dueDate, on);
        if (days == 0) return amount.setScale(2, RoundingMode.HALF_UP);
        BigDecimal fee = amount.multiply(building.lateFeePct()).divide(HUNDRED, 12, RoundingMode.HALF_EVEN);
        BigDecimal interest = amount.multiply(building.monthlyInterestPct()).multiply(BigDecimal.valueOf(days))
                .divide(HUNDRED.multiply(INTEREST_DAYS), 12, RoundingMode.HALF_EVEN);
        return amount.add(fee).add(interest).setScale(2, RoundingMode.HALF_UP);
    }

    public static long daysLate(LocalDate dueDate, LocalDate on) {
        return on.isAfter(dueDate) ? ChronoUnit.DAYS.between(dueDate, on) : 0;
    }

    /** The share of the billed amount that was paid, in percent with one decimal; null when nothing was billed. */
    public static BigDecimal rate(BigDecimal paid, BigDecimal billed) {
        if (billed.signum() == 0) return null;
        return paid.multiply(HUNDRED).divide(billed, 1, RoundingMode.HALF_UP);
    }
}
