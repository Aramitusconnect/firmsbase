<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ImportMapping — no firm_id of its own, scoped transitively through
 * import_batch_id.
 */
class ImportMapping extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'import_batch_id',
        'source_field',
        'target_field',
        'transform_rule',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }
}
