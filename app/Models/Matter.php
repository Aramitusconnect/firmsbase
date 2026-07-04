<?php

namespace App\Models;

use App\Enums\MatterStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Matter — status transitions to Open are gated by MatterOpeningService
 * (a completed, clear conflict check is required — never set directly).
 * pinned_template_pack_version_id is set once at creation and never
 * changed afterward by a pack upgrade. stage is a freeform,
 * template-driven string, not a rigid state machine.
 *
 * Phase 4 addition: document requests, tasks, deadlines, calendar
 * events, and a single current readiness score.
 */
class Matter extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'primary_practice_area_id',
        'matter_type_id',
        'pinned_template_pack_version_id',
        'status',
        'stage',
        'assigned_attorney_id',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatterStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function primaryPracticeArea(): BelongsTo
    {
        return $this->belongsTo(PracticeArea::class, 'primary_practice_area_id');
    }

    public function matterType(): BelongsTo
    {
        return $this->belongsTo(MatterType::class);
    }

    public function pinnedTemplatePackVersion(): BelongsTo
    {
        return $this->belongsTo(TemplatePackVersion::class, 'pinned_template_pack_version_id');
    }

    public function assignedAttorney(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_attorney_id');
    }

    public function matterParties(): HasMany
    {
        return $this->hasMany(MatterParty::class);
    }

    public function matterAssignments(): HasMany
    {
        return $this->hasMany(MatterAssignment::class);
    }

    public function conflictCheckRuns(): HasMany
    {
        return $this->hasMany(ConflictCheckRun::class);
    }

    public function intakeSubmissions(): HasMany
    {
        return $this->hasMany(IntakeSubmission::class);
    }

    public function isOpenOrBeyond(): bool
    {
        return in_array($this->status, [
            MatterStatus::Open,
            MatterStatus::Active,
            MatterStatus::WaitingOnClient,
            MatterStatus::ReadyForReview,
            MatterStatus::FiledSubmitted,
            MatterStatus::Closed,
            MatterStatus::Archived,
        ], true);
    }

    /**
     * Phase 4 additions below.
     */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * One current row, recomputed in place by MatterReadinessService —
     * never a history of past scores.
     */
    public function readinessScore(): HasOne
    {
        return $this->hasOne(MatterReadinessScore::class);
    }

    public function readinessScoreEvents(): HasMany
    {
        return $this->hasMany(ReadinessScoreEvent::class);
    }
}
