<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;

/**
 * ExpenseReceiptService — the only writer of expense_receipts. Mirrors
 * DocumentSecurityService::upload() exactly: private by default, never
 * a public URL, file_hash is caller-supplied (no real file-storage
 * pipeline exists anywhere in this codebase — same honesty finding
 * carried over from Phase 4/11).
 *
 * expense_receipts now has permanent FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_27_950021_prepare_row_level_security_and_
 * force_rls_on_expense_receipts_table.php), so the duplicate-guard read
 * (`$expense->receipt()->exists()`) and the ExpenseReceipt::create()
 * write below must both run under the target firm's context — both are
 * wrapped together in ONE shared runWithFirmContext() call, identical
 * in shape to MatterExpenseService::link()'s duplicate-check-plus-create
 * pattern (a duplicate check that ran under one context and a write
 * that ran under another could race or mask the FORCE RLS-driven
 * zero-rows-visible failure mode as a false "no receipt yet" result).
 * assertExpensesEnabled()/assertExpenseBelongsToFirm() stay OUTSIDE the
 * wrap, unchanged — see ExpenseService's own docblock for the full
 * decoy-wrap rationale.
 */
class ExpenseReceiptService
{
    public function __construct(
        private readonly AccountingEntitlementPolicyService $entitlementPolicy,
        private readonly TenantSafeAccountingPolicyService $tenantSafePolicy,
    ) {
    }

    public function upload(
        Firm $firm,
        Expense $expense,
        string $originalFilename,
        string $mimeType,
        int $sizeBytes,
        string $storageDisk,
        string $storagePath,
        string $fileHash,
        ?FirmUser $uploadedBy = null,
        ?TenantEncryptionKey $encryptionKey = null,
    ): ExpenseReceipt {
        $this->entitlementPolicy->assertExpensesEnabled($firm);
        $this->tenantSafePolicy->assertExpenseBelongsToFirm($expense, $firm);

        return (new TenantContextService())->runWithFirmContext($firm, function () use (
            $firm, $expense, $originalFilename, $mimeType, $sizeBytes, $storageDisk, $storagePath, $fileHash, $uploadedBy, $encryptionKey,
        ) {
            if ($expense->receipt()->exists()) {
                throw new \RuntimeException('This expense already has a receipt.');
            }

            return ExpenseReceipt::create([
                'firm_id' => $firm->id,
                'expense_id' => $expense->id,
                'storage_disk' => $storageDisk,
                'storage_path' => $storagePath,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'file_hash' => $fileHash,
                'encryption_key_id' => $encryptionKey?->id,
                'uploaded_by_firm_user_id' => $uploadedBy?->id,
            ]);
        });
    }

    /**
     * The explicit private-access check, mirrors
     * DocumentSecurityService::canAccess().
     */
    public function canAccess(ExpenseReceipt $receipt, Firm $contextFirm): bool
    {
        return $receipt->canAccess($contextFirm);
    }
}
