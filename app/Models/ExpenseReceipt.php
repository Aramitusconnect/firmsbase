<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ExpenseReceipt — private by default, never a public URL (mirrors
 * Document's exact convention). Only ExpenseReceiptService may create
 * a row; this model never writes to itself.
 */
class ExpenseReceipt extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'expense_id',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'file_hash',
        'encryption_key_id',
        'uploaded_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'uploaded_by_firm_user_id');
    }

    /**
     * The explicit private-access check, mirrors
     * DocumentSecurityService::canAccess() exactly. Any future signed-
     * URL/download endpoint must call this first.
     */
    public function canAccess(Firm $contextFirm): bool
    {
        return $this->firm_id === $contextFirm->id;
    }
}
