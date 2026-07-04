<?php

namespace App\Models;

use App\Enums\DocumentVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentVersion — a detail record of its parent Document, no own
 * firm_id. Only DocumentReplacementService transitions status.
 */
class DocumentVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'version_number',
        'status',
        'storage_disk',
        'storage_path',
        'file_hash',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentVersionStatus::class,
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
