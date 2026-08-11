<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Models\PlatformAdmin;
use Database\Factories\DirectoryImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DirectoryImportBatch — Mission 2 (MyAttorney Marketplace Core),
 * sections 53-55. See the migration's own docblock for why this is a
 * parallel batch table, not a reuse of the generic ImportBatch. A
 * "dumb" model — every transition lives in
 * MarketplaceCsvIngestionService/MarketplaceImportApplyService.
 */
class DirectoryImportBatch extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'created_by_platform_admin_id',
        'original_filename',
        'status',
        'source_rights_confirmed',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'applied_rows',
        'skipped_rows',
    ];

    protected function casts(): array
    {
        return [
            'status' => DirectoryImportBatchStatus::class,
            'source_rights_confirmed' => 'boolean',
        ];
    }

    protected static function newFactory(): DirectoryImportBatchFactory
    {
        return DirectoryImportBatchFactory::new();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DirectoryImportRow::class);
    }
}
