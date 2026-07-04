<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MatterType — GLOBAL platform catalog, scoped under a PracticeArea.
 * No BelongsToTenant, no uuid.
 */
class MatterType extends Model
{
    use HasFactory;

    protected $fillable = [
        'practice_area_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    public function intakeTemplates(): HasMany
    {
        return $this->hasMany(IntakeTemplate::class);
    }
}
