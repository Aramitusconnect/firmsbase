<?php

namespace App\Models;

use App\Enums\ReleaseNoteStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ReleaseNote — platform-level only. No organization/firm/plan
 * relation exists on this model at all, per the approved scope
 * ("release notes are platform-level; do not tie release notes to
 * firm legal data").
 */
class ReleaseNote extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'version',
        'title',
        'body',
        'status',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReleaseNoteStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by');
    }
}
