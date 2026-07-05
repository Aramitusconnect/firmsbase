<?php

namespace App\Models;

use App\Enums\ImportErrorSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ImportError — no uuid (pure validation-failure log, scoped
 * transitively through import_row_id). Written exclusively by
 * ImportRowValidationService.
 */
class ImportError extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'import_row_id',
        'field',
        'severity',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'severity' => ImportErrorSeverity::class,
            'created_at' => 'datetime',
        ];
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class);
    }
}
