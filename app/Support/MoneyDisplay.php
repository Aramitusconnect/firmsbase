<?php

declare(strict_types=1);

namespace App\Support;

/**
 * MoneyDisplay — Phase 3 (FirmsVault Platform Admin Control Center,
 * "Billing and Commercial Administration") addition. No Money value
 * object, cast, or formatting helper exists anywhere in this repo (the
 * Phase 3 architecture investigation confirmed every platform-billing
 * amount is a plain integer `_cents` column) — this is the single
 * shared formatter every Phase 3 Filament Resource/Page uses, so every
 * module renders cents identically instead of each inventing its own
 * `$/100` string.
 *
 * Deliberately display-only: never used for computation. Every
 * production service in this codebase computes exclusively in integer
 * cents; this class is not, and must never become, a second source of
 * truth for arithmetic.
 */
final class MoneyDisplay
{
    /**
     * $currency is a fixed ISO 4217 3-letter tag, not a live currency
     * lookup — the platform-billing schema has no per-row currency
     * column anywhere (confirmed by the Phase 3 architecture
     * investigation), so every amount in this domain is USD today.
     */
    public static function fromCents(?int $cents, string $currency = 'USD'): string
    {
        if ($cents === null) {
            return '—';
        }

        $sign = $cents < 0 ? '-' : '';
        $absCents = abs($cents);

        return sprintf('%s%s %s', $sign, number_format($absCents / 100, 2), $currency);
    }
}
