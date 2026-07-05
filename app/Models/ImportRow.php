<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ImportRow has no firm_id of its own and is scoped transitively through
 * import_batch_id, whose parent ImportBatch uses BelongsToTenant.
 * The duplicate_of and applied_record pointer columns are polymorphic,
 * deliberately not FK-constrained because they may point at several
 * entity tables depending on the batch entity type.
 */
class ImportRow extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_data',
        'mapped_data',
        'status',
        'is_duplicate',
        'duplicate_of_type',
        'duplicate_of_id',
        'applied_record_type',
        'applied_record_id',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'mapped_data' => 'array',
            'status' => ImportRowStatus::class,
            'is_duplicate' => 'boolean',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    public function rollbackRecords(): HasMany
    {
        return $this->hasMany(ImportRollbackRecord::class);
    }
}
