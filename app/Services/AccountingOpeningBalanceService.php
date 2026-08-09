<?php

namespace App\Services;

use App\Enums\AccountingJournalSourceType;
use App\Enums\ChartOfAccountPurpose;
use App\Exceptions\AccountingSetupIncompleteException;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Models\FirmUser;
use App\ValueObjects\AccountingOpeningBalanceValidationResult;

/**
 * AccountingOpeningBalanceService — Accounting Integrity Hardening
 * Pass, item 8: the ONE governed path a firm adopting native
 * accounting mid-flight may use to establish its starting position on
 * the operating books. Deliberately reuses the canonical journal
 * (AccountingJournalEntry/AccountingJournalPostingService) rather than
 * inventing a second ledger or a special-cased table — an opening
 * balance is a real, ordinary double-entry journal entry whose only
 * distinguishing feature is its source_type
 * (AccountingJournalSourceType::OpeningBalanceCutover) and that it may
 * be recorded AT MOST ONCE per firm (checked explicitly below, not
 * merely left to the idempotency-key short-circuit — a second genuine
 * attempt with different lines must be rejected outright, never
 * silently return the first entry as if it matched).
 *
 * Scope, stated explicitly: OPERATING BOOKS ONLY. Trust opening/
 * reconciled positions are deliberately out of scope — Trust already
 * has its own complete, carefully access-controlled deposit/approval
 * workflow (TrustDepositService, TrustAccessPolicyService, etc.); a
 * "trust opening balance" belongs there, as an ordinary (migration-
 * sourced) deposit through that existing workflow, never through a
 * second, parallel entry point this service would otherwise become.
 *
 * Does NOT backfill any prior transaction history and never pretends
 * one occurred — every opening entry is dated on the real cutover date
 * the caller supplies and is labeled as an opening balance in its own
 * description, never disguised as an ordinary business event. Never
 * invoked automatically by any other service, job, or migration; a
 * firm's opening balances are recorded exactly once, by deliberate
 * human action, after validate() has been used to check the lines.
 */
class AccountingOpeningBalanceService
{
    public function __construct(
        private readonly ChartOfAccountsService $chartOfAccounts,
        private readonly AccountingJournalPostingService $posting,
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
    ) {}

    /**
     * Read-only. Resolves every line's purpose against the firm's
     * Chart of Accounts, checks the lines balance, and checks an
     * opening balance has not already been recorded — all WITHOUT
     * persisting anything. Collects every error found rather than
     * stopping at the first, so a firm can fix its whole cutover sheet
     * in one pass.
     *
     * @param  array<int, array{purpose: ChartOfAccountPurpose, debit_cents?: int, credit_cents?: int, memo?: string|null}>  $lines
     */
    public function validate(Firm $firm, array $lines): AccountingOpeningBalanceValidationResult
    {
        $errors = [];

        if (count($lines) < 2) {
            $errors[] = 'An opening balance requires at least two lines.';
        }

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $line) {
            $debit = $line['debit_cents'] ?? 0;
            $credit = $line['credit_cents'] ?? 0;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $purpose = $line['purpose'] ?? null;

            if (! $purpose instanceof ChartOfAccountPurpose) {
                $errors[] = 'Every line must specify a ChartOfAccountPurpose.';

                continue;
            }

            if ($this->chartOfAccounts->resolveByPurpose($firm, $purpose) === null) {
                $errors[] = "No active account configured for purpose [{$purpose->value}].";
            }
        }

        if ($totalDebit !== $totalCredit) {
            $errors[] = "Lines do not balance: total debits ({$totalDebit}) must equal total credits ({$totalCredit}).";
        }

        $alreadyRecorded = $this->alreadyRecorded($firm);

        if ($alreadyRecorded) {
            $errors[] = 'This firm already has an opening balance cutover entry recorded — it may only be recorded once.';
        }

        return new AccountingOpeningBalanceValidationResult(
            valid: empty($errors),
            errors: $errors,
            totalDebitCents: $totalDebit,
            totalCreditCents: $totalCredit,
            alreadyRecorded: $alreadyRecorded,
        );
    }

    /**
     * The one real, irreversible write. Every line's purpose must
     * resolve (AccountingSetupIncompleteException, atomically rolling
     * back the whole attempt, if any does not — the same policy every
     * other money-changing call site in this codebase now follows) and
     * the whole set must balance (enforced again by
     * AccountingJournalPostingService::post() itself, defense in
     * depth). $source is a short, human-authored description of where
     * these figures came from (e.g. "Migrated from Clio, 2026-11-01
     * export") — required, never blank, since "auditable" means a
     * reader can tell WHERE a number came from, not merely that one
     * exists.
     *
     * @param  array<int, array{purpose: ChartOfAccountPurpose, debit_cents?: int, credit_cents?: int, memo?: string|null}>  $lines
     */
    public function record(Firm $firm, \DateTimeInterface $cutoverDate, array $lines, string $source, FirmUser $recordedBy): AccountingJournalEntry
    {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->entitlementPolicy->assertCanApprove($recordedBy);

        if (trim($source) === '') {
            throw new \InvalidArgumentException('An opening balance record requires a non-blank source description.');
        }

        if ((int) $recordedBy->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('The recording user does not belong to this firm.');
        }

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $cutoverDate, $lines, $source, $recordedBy) {
            if ($this->alreadyRecorded($firm)) {
                throw new \RuntimeException('This firm already has an opening balance cutover entry recorded — it may only be recorded once.');
            }

            $postings = array_map(function (array $line) use ($firm) {
                $purpose = $line['purpose'];

                if (! $purpose instanceof ChartOfAccountPurpose) {
                    throw new \InvalidArgumentException('Every line must specify a ChartOfAccountPurpose.');
                }

                $account = $this->chartOfAccounts->requireByPurpose($firm, $purpose);

                return [
                    'chart_of_account_id' => $account->id,
                    'debit_cents' => $line['debit_cents'] ?? 0,
                    'credit_cents' => $line['credit_cents'] ?? 0,
                    'memo' => $line['memo'] ?? null,
                ];
            }, $lines);

            return $this->posting->post(
                $firm,
                AccountingJournalSourceType::OpeningBalanceCutover,
                "Opening balance cutover — {$source}",
                $cutoverDate,
                $postings,
                [],
                $recordedBy,
            );
        });
    }

    private function alreadyRecorded(Firm $firm): bool
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => AccountingJournalEntry::query()
            ->where('firm_id', $firm->id)
            ->where('source_type', AccountingJournalSourceType::OpeningBalanceCutover)
            ->exists());
    }
}
