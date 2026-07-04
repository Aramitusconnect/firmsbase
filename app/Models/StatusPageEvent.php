<?php

namespace App\Models;

use App\Enums\StatusPageEventStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * StatusPageEvent — platform-level, no firm_id at all (same treatment
 * as Phase 4's readiness_scorecard_components). Carries a public uuid
 * (approved conservative-uuid-scope pattern) since a real status page
 * must reference a specific update without exposing a bigint id.
 * event_type is a plain string (approved clarification) describing
 * the incident-progress category; status (StatusPageEventStatus) is
 * the separate visibility/publication state. No UI is built for this
 * in Phase 5 (project rule) — this is the data foundation only.
 */
class StatusPageEvent extends Model
{
    use HasFactory, HasPublicUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'correlation_id',
        'incident_correlation_id',
        'event_type',
        'status',
        'component_affected',
        'public_message',
        'starts_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPageEventStatus::class,
            'starts_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === StatusPageEventStatus::Published;
    }
}
