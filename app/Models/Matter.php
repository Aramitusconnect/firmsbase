<?php

namespace App\Models;

use App\Enums\MatterStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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
 * Phase 5 addition: pilot feedback items linked to this matter.
 */
class Matter extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

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

    /**
     * Firm/CRM cluster addition: flattens every ConflictCheckResult
     * across all of this matter's ConflictCheckRuns into one list, for
     * a single "Conflict Check Results" tab rather than nesting a
     * second table inside each run row. `conflict_check_results` has no
     * `firm_id`/RLS of its own (isolation is transitive through
     * `conflict_check_run_id` -> `conflict_check_runs.firm_id`, see that
     * model's own docblock) — the actual tenant boundary for this
     * relation is `conflict_check_runs`' own FORCE ROW LEVEL SECURITY,
     * which the join below still passes through at the database level
     * regardless of how Eloquent constructs the join.
     */
    public function conflictCheckResults(): HasManyThrough
    {
        return $this->hasManyThrough(
            ConflictCheckResult::class,
            ConflictCheckRun::class,
            'matter_id',
            'conflict_check_run_id',
        );
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

    /**
     * Phase 5 addition.
     */
    public function pilotFeedbackItems(): HasMany
    {
        return $this->hasMany(PilotFeedbackItem::class);
    }

    /**
     * Tier1-G (Firm Feature Manifest "Relationships" wiring) additions
     * below. TimeEntry, Expense, and Payment all carry a direct
     * `matter_id` column of their own — plain, direct HasMany relations,
     * the same shape as documentRequests()/documents()/tasks()/
     * deadlines() above.
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Contacts sharing this matter's client. Contact has no `matter_id`
     * column of its own (Contact belongs to Client only) — and this is
     * deliberately NOT expressed as a HasManyThrough: that relation
     * shape requires the local model to `hasMany` the "through" model
     * (e.g. Client hasMany Matter above), but here Matter `belongsTo`
     * Client, the reverse direction. A plain HasMany with a shared
     * foreign/local key (`contacts.client_id` = `matters.client_id`,
     * evaluated off this Matter's own client_id attribute) is the
     * correct, simple Eloquent primitive for this "same-parent"
     * scoping — matching this mission's own guidance to prefer a plain
     * relation/scoped query over forcing a fragile HasManyThrough where
     * the FK structure doesn't actually support one.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'client_id', 'client_id');
    }

    /**
     * Predictive Matter Budget Alerts addition. Append-only history —
     * the CURRENT budget is the highest-version row, never this
     * relation's own "first" (see MatterBudgetService::current()).
     */
    public function matterBudgets(): HasMany
    {
        return $this->hasMany(MatterBudget::class);
    }

    /**
     * One current row, recomputed in place by
     * MatterBudgetAnalysisService — same shape as readinessScore()
     * above.
     */
    public function budgetAnalysis(): HasOne
    {
        return $this->hasOne(MatterBudgetAnalysis::class);
    }

    public function budgetAlerts(): HasMany
    {
        return $this->hasMany(MatterBudgetAlert::class);
    }
}
