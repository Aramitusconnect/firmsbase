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
