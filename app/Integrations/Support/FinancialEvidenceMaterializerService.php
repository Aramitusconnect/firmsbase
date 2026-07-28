<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Enums\ResourceType;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Models\FinancialEvidenceBankAccount;
use App\Models\FinancialEvidenceIdentityRecord;
use App\Models\FinancialEvidenceIncomeRecord;
use App\Models\FinancialEvidenceInvestmentRecord;
use App\Models\FinancialEvidenceLiability;
use App\Models\FinancialEvidenceStatement;
use App\Models\FinancialEvidenceTransaction;
use InvalidArgumentException;
use RuntimeException;

/**
 * FinancialEvidenceMaterializerService — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.2, schema authoritative
 * source; checkpoint4-combined-design.md §1.1.3/§7, implementation
 * ownership reassigned to the Plaid provider-core phase). Closes the
 * genuine materialization gap `App\Jobs\PullSyncJob` itself flags inline
 * ("no generic hook exists in this codebase for it yet") — Plaid's
 * seven new `ResourceType` cases are the first resource types this
 * codebase has ever needed a real local domain record for, materialized
 * from a pull rather than left `Skipped`.
 *
 * Invoked from `PullSyncJob::runBatchLoop()` immediately before its
 * existing `$mapping === null` -> `Skipped` branch, gated
 * `$connection->providerKey() === ProviderKey::Plaid` only — Microsoft/
 * Google's existing, deliberately-`Skipped` behavior for an unmapped
 * external item is completely unchanged by this class's existence.
 *
 * Every table this service writes to (`financial_evidence_*`) is an
 * IMMUTABLE, append-only evidentiary model (each copies
 * `App\Models\DocumentHash`'s `booted()`-guard idiom) — this service
 * therefore only ever INSERTs a brand-new row, never updates one. It is
 * the caller's responsibility (`PullSyncJob`) to only invoke
 * `materialize()` when no live local mapping already exists for this
 * external item — a version-token mismatch on an ALREADY-mapped item is
 * a distinct, existing conflict-detection code path
 * (`IntegrationConflictService::recordDetection()`), never routed
 * through this service.
 *
 * Every `raw` payload passed in is expected to be the genuine,
 * unmodified Plaid API object for that item (verbatim) — this service
 * never fabricates or infers a field Plaid did not actually return; a
 * required field that is genuinely absent from a real Plaid response
 * throws rather than silently persisting a wrong/default value.
 */
final class FinancialEvidenceMaterializerService
{
    /**
     * @param  array<string, mixed>  $externalItem  the full item array
     *                                              `PlaidProvider::pull()`
     *                                              produced for this
     *                                              external object —
     *                                              always carries
     *                                              'external_id' and
     *                                              'raw' (the genuine
     *                                              Plaid object), plus a
     *                                              resource-type-specific
     *                                              discriminator key
     *                                              where needed
     *                                              ('liability_type' for
     *                                              Liability,
     *                                              'record_type' for
     *                                              Investment).
     * @return array{local_type: string, local_id: int}
     */
    public function materialize(FirmIntegration $connection, ResourceType $type, array $externalItem): array
    {
        $raw = $externalItem['raw'] ?? null;

        if (! is_array($raw)) {
            throw new InvalidArgumentException(
                'FinancialEvidenceMaterializerService::materialize() requires a non-empty array \'raw\' payload.'
            );
        }

        return match ($type) {
            ResourceType::BankAccount => $this->materializeBankAccount($connection, $raw),
            ResourceType::Transaction => $this->materializeTransaction($connection, $raw),
            ResourceType::Income => $this->materializeIncomeRecord($connection, $externalItem, $raw),
            ResourceType::Liability => $this->materializeLiability($connection, $externalItem, $raw),
            ResourceType::Investment => $this->materializeInvestmentRecord($connection, $externalItem, $raw),
            ResourceType::Statement => $this->materializeStatement($connection, $raw),
            ResourceType::Identity => $this->materializeIdentityRecord($connection, $raw),
            default => throw new InvalidArgumentException(
                "FinancialEvidenceMaterializerService::materialize() does not support resource type \"{$type->value}\"."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeBankAccount(FirmIntegration $connection, array $raw): array
    {
        $accountId = $this->requireString($raw, 'account_id', 'BankAccount');

        $model = FinancialEvidenceBankAccount::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'plaid_account_id' => $accountId,
            'account_name' => $this->nullableString($raw['name'] ?? $raw['official_name'] ?? null),
            'account_subtype' => $this->nullableString($raw['subtype'] ?? null),
            'mask' => $this->nullableString($raw['mask'] ?? null),
            'classification' => null,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceBankAccount::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeTransaction(FirmIntegration $connection, array $raw): array
    {
        $transactionId = $this->requireString($raw, 'transaction_id', 'Transaction');
        $accountId = $this->requireString($raw, 'account_id', 'Transaction');
        $date = $this->nullableString($raw['date'] ?? null);

        if ($date === null) {
            throw new RuntimeException(
                "Plaid transaction {$transactionId} carried no 'date' field — cannot materialize a required transaction_date."
            );
        }

        $amount = $raw['amount'] ?? null;

        if (! is_int($amount) && ! is_float($amount)) {
            throw new RuntimeException("Plaid transaction {$transactionId} carried no numeric 'amount' field.");
        }

        $pending = (bool) ($raw['pending'] ?? false);

        $model = FinancialEvidenceTransaction::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'plaid_transaction_id' => $transactionId,
            'plaid_account_id' => $accountId,
            'bank_account_id' => $this->resolveLocalBankAccountId($connection, $accountId),
            'amount_cents' => (int) round(((float) $amount) * 100),
            'iso_currency_code' => $this->nullableString($raw['iso_currency_code'] ?? $raw['unofficial_currency_code'] ?? null),
            'transaction_date' => $date,
            'posted_date' => $pending ? null : $date,
            'merchant_name' => $this->nullableString($raw['merchant_name'] ?? null),
            'category_json' => is_array($raw['personal_finance_category'] ?? null) ? $raw['personal_finance_category'] : null,
            'pending' => $pending,
            'provider_retrieved_at' => now(),
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceTransaction::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $externalItem
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeIncomeRecord(FirmIntegration $connection, array $externalItem, array $raw): array
    {
        $incomeStreamHash = $this->requireString($externalItem, 'external_id', 'Income');

        $model = FinancialEvidenceIncomeRecord::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'income_stream_hash' => $incomeStreamHash,
            'category' => $this->nullableString($raw['income_category'] ?? $raw['category'] ?? null),
            'pay_frequency' => $this->nullableString($raw['pay_frequency'] ?? null),
            'summary_json' => $raw,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceIncomeRecord::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $externalItem
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeLiability(FirmIntegration $connection, array $externalItem, array $raw): array
    {
        $accountId = $this->requireString($raw, 'account_id', 'Liability');
        $liabilityType = $this->requireString($externalItem, 'liability_type', 'Liability');

        if (! in_array($liabilityType, ['credit', 'mortgage', 'student'], true)) {
            throw new InvalidArgumentException("Unrecognized Plaid liability_type \"{$liabilityType}\".");
        }

        $model = FinancialEvidenceLiability::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'plaid_account_id' => $accountId,
            'liability_type' => $liabilityType,
            'type_specific_json' => $raw,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceLiability::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $externalItem
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeInvestmentRecord(FirmIntegration $connection, array $externalItem, array $raw): array
    {
        $recordType = $this->requireString($externalItem, 'record_type', 'Investment');

        if (! in_array($recordType, ['holding', 'transaction'], true)) {
            throw new InvalidArgumentException("Unrecognized Plaid investment record_type \"{$recordType}\".");
        }

        $externalId = $this->requireString($externalItem, 'external_id', 'Investment');

        $model = FinancialEvidenceInvestmentRecord::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'record_type' => $recordType,
            'plaid_external_id' => $externalId,
            'plaid_security_id' => $recordType === 'holding' ? $this->nullableString($raw['security_id'] ?? null) : null,
            'plaid_investment_transaction_id' => $recordType === 'transaction'
                ? $this->nullableString($raw['investment_transaction_id'] ?? null)
                : null,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceInvestmentRecord::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeStatement(FirmIntegration $connection, array $raw): array
    {
        $statementId = $this->requireString($raw, 'statement_id', 'Statement');

        $month = $raw['month'] ?? null;
        $year = $raw['year'] ?? null;

        $model = FinancialEvidenceStatement::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'plaid_statement_id' => $statementId,
            'month' => is_int($month) ? $month : null,
            'year' => is_int($year) ? $year : null,
            'available_date' => $this->nullableString($raw['available_date'] ?? $raw['availableDate'] ?? null),
            'storage_disk' => null,
            'storage_path' => null,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceStatement::class, 'local_id' => $model->id];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{local_type: string, local_id: int}
     */
    private function materializeIdentityRecord(FirmIntegration $connection, array $raw): array
    {
        $accountId = $this->requireString($raw, 'account_id', 'Identity');
        $owners = is_array($raw['owners'] ?? null) ? $raw['owners'] : [];

        $names = [];
        $emails = [];
        $phones = [];
        $addresses = [];

        foreach ($owners as $owner) {
            $owner = is_array($owner) ? $owner : [];
            $names = array_merge($names, is_array($owner['names'] ?? null) ? $owner['names'] : []);
            $emails = array_merge($emails, is_array($owner['emails'] ?? null) ? $owner['emails'] : []);
            $phones = array_merge($phones, is_array($owner['phone_numbers'] ?? null) ? $owner['phone_numbers'] : []);
            $addresses = array_merge($addresses, is_array($owner['addresses'] ?? null) ? $owner['addresses'] : []);
        }

        $model = FinancialEvidenceIdentityRecord::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'plaid_account_id' => $accountId,
            'owner_names_json' => $names === [] ? null : $names,
            'owner_emails_json' => $emails === [] ? null : $emails,
            'owner_phones_json' => $phones === [] ? null : $phones,
            'owner_addresses_json' => $addresses === [] ? null : $addresses,
            'raw_json' => $raw,
        ]);

        return ['local_type' => FinancialEvidenceIdentityRecord::class, 'local_id' => $model->id];
    }

    /**
     * Best-effort, nullable local lookup — Plaid's Transactions and Auth
     * products are pulled independently (separate ResourceType cursors,
     * no ordering guarantee between them), so a Transaction may be
     * materialized before its owning account has ever been pulled. Never
     * throws; a miss simply leaves `bank_account_id` null.
     */
    private function resolveLocalBankAccountId(FirmIntegration $connection, string $plaidAccountId): ?int
    {
        $mapping = IntegrationExternalMapping::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', ResourceType::BankAccount->value)
            ->where('external_id', $plaidAccountId)
            ->whereNull('tombstoned_at')
            ->first();

        return $mapping?->local_id;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function requireString(array $source, string $key, string $resourceLabel): string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                "FinancialEvidenceMaterializerService cannot materialize a Plaid {$resourceLabel} item with no usable '{$key}'."
            );
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }
}
