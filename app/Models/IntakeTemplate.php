<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IntakeTemplate — GLOBAL. No BelongsToTenant, no uuid — this is
 * template/reference data, not a firm-owned record. IntakeSubmission
 * is the firm/client-scoped record that actually uses this template.
 *
 * template_pack_version_id is nullable (Mission 3, checkpoint 3) — a
 * row backing a Firm's installed template pack always has one; a
 * platform-wide MyAttorney marketplace intake template (selected by
 * practice_area_id, before any Firm relationship exists) has none.
 * See that migration's own docblock for the full rationale.
 *
 * questions() is the real, deterministic question structure
 * (intake_template_questions) — schema_json remains untouched,
 * reserved for a later checkpoint's own use.
 */
class IntakeTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_pack_version_id',
        'matter_type_id',
        'practice_area_id',
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

    public function practiceArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(IntakeTemplateQuestion::class)->orderBy('sort_order');
    }
}
