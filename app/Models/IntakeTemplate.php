<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IntakeTemplate — GLOBAL, belongs to a TemplatePackVersion. No
 * BelongsToTenant, no uuid — this is template/reference data, not a
 * firm-owned record. IntakeSubmission is the firm/client-scoped record
 * that actually uses this template.
 */
class IntakeTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_pack_version_id',
        'matter_type_id',
        'code',
        'name',
        'schema_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'schema_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function templatePackVersion(): BelongsTo
    {
        return $this->belongsTo(TemplatePackVersion::class);
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class);
    }
}
