<?php

namespace App\Models;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document — private by default, never a public URL (project rule).
 * Only DocumentSecurityService/DocumentUploadPolicyService/
 * DocumentReplacementService may transition status/scan_status; this
 * model never writes to itself. isUsable() is the single check every
 * other part of the system must call before treating a document as
 * safe to expose or attach anywhere.
 */
class Document extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'client_id',
        'document_request_item_id',
        'status',
        'scan_status',
        'scan_result_detail',
        'scanned_at',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'file_hash',
        'encryption_key_id',
        'uploaded_by',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'replaces_document_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'scan_status' => DocumentScanStatus::class,
            'scanned_at' => 'datetime',
            'size_bytes' => 'integer',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documentRequestItem(): BelongsTo
    {
        return $this->belongsTo(DocumentRequestItem::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function replacedBy(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_document_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    /**
     * A document is only ever "usable" (attachable, approvable,
     * downloadable) once it has been scanned clean. Infected/Failed/
     * Pending documents must never be treated as usable anywhere.
     */
    public function isUsable(): bool
    {
        return $this->scan_status === DocumentScanStatus::Clean
            && $this->status !== DocumentStatus::Rejected;
    }
}
