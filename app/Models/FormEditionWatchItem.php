<?php

namespace App\Models;

use App\Enums\FormEditionWatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FormEditionWatchItem — no firm_id at all. Platform content-ops
 * tracking only; no firm ever sees or sets this.
 */
class FormEditionWatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_template_id',
        'watch_status',
        'detected_edition_date',
        'notes',
        'created_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'watch_status' => FormEditionWatchStatus::class,
        ];
    }

    public function formTemplate(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class);
    }

    public function createdByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_platform_admin_id');
    }
}
