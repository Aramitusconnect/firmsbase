<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmLeadStatus;
use App\Models\Consultation;
use App\Models\FirmLead;
use RuntimeException;

/**
 * FirmLeadWorkflowService — the only place FirmLead.status is written.
 * Extracted from four Filament call sites
 * (FirmLeadResource\Actions\{MarkLeadContactedAction,ScheduleConsultationAction,MarkLeadLostAction},
 * FirmLeadResource\RelationManagers\ConsultationsRelationManager) that
 * each originally performed a plain `$lead->update(['status' => ...])`
 * directly — Governance Section 25+ WorkflowTransitionEnforcementSearchTest
 * requires every direct write of a catalog workflow status enum
 * (FirmLeadStatus included) to live in app/Services, never a UI layer.
 *
 * Every method re-fetches fresh and re-checks its own real precondition
 * (never trusting a caller's own UX-layer visible() pre-filter alone),
 * mirroring SignatureRequestWorkflowService::addRecipient()'s
 * established shape. Callers still own role/firm-membership
 * authorization (ClientCrmAccessPolicyService::canManageLead()) and
 * tenant-context establishment (TenantContextService::runWithFirmContext())
 * exactly as before -- this service assumes it is always invoked from
 * inside an already-active firm context, matching how
 * SignatureRecipientWorkflowService's own methods work.
 */
class FirmLeadWorkflowService
{
    private const SCHEDULABLE_STATUSES = [
        FirmLeadStatus::New,
        FirmLeadStatus::Contacted,
    ];

    private const NON_TERMINAL_STATUSES = [
        FirmLeadStatus::New,
        FirmLeadStatus::Contacted,
        FirmLeadStatus::ConsultationScheduled,
        FirmLeadStatus::ConsultationHeld,
    ];

    private const LEAD_TERMINAL_STATUSES_FOR_CONSULTATION_HELD = [
        FirmLeadStatus::Converted,
        FirmLeadStatus::Lost,
        FirmLeadStatus::Archived,
    ];

    /**
     * Only ever fires from New — mirrors MarkLeadContactedAction's own
     * original guard exactly.
     */
    public function markContacted(FirmLead $lead): FirmLead
    {
        $fresh = FirmLead::query()->where('id', $lead->id)->firstOrFail();

        if ($fresh->status !== FirmLeadStatus::New) {
            throw new RuntimeException('This lead is no longer New.');
        }

        $fresh->update(['status' => FirmLeadStatus::Contacted]);

        return $fresh->fresh();
    }

    /**
     * Never available once a lead is Converted (a real client
     * relationship cannot retroactively become "lost") or already
     * Lost/Archived — mirrors MarkLeadLostAction's own original guard
     * exactly.
     */
    public function markLost(FirmLead $lead): FirmLead
    {
        $fresh = FirmLead::query()->where('id', $lead->id)->firstOrFail();

        if (! in_array($fresh->status, self::NON_TERMINAL_STATUSES, true)) {
            throw new RuntimeException('This lead can no longer be marked Lost.');
        }

        $fresh->update(['status' => FirmLeadStatus::Lost]);

        return $fresh->fresh();
    }

    /**
     * Creates the real Consultation row a scheduled meeting represents
     * and, only when the lead is still New/Contacted, advances it to
     * ConsultationScheduled (a lead already past that point gets an
     * additional Consultation without regressing/re-advancing its own
     * status — ConsultationsRelationManager's own original behavior).
     * The broader "not converted" precondition (rather than
     * ScheduleConsultationAction's own narrower SCHEDULABLE_STATUSES
     * UX pre-filter) is this method's real boundary, matching
     * ConsultationsRelationManager's own original, broader allowance —
     * "list is UX filter, resolve step is the boundary."
     */
    public function scheduleConsultation(FirmLead $lead, string|\DateTimeInterface $scheduledAt, ?string $notes): Consultation
    {
        $fresh = FirmLead::query()->where('id', $lead->id)->firstOrFail();

        if ($fresh->isConverted()) {
            throw new RuntimeException('This lead has already been converted.');
        }

        $consultation = Consultation::create([
            'firm_id' => $fresh->firm_id,
            'firm_lead_id' => $fresh->id,
            'scheduled_at' => $scheduledAt,
            'notes' => $notes,
        ]);

        if (in_array($fresh->status, self::SCHEDULABLE_STATUSES, true)) {
            $fresh->update(['status' => FirmLeadStatus::ConsultationScheduled]);
        }

        return $consultation;
    }

    /**
     * Records that a specific consultation actually happened and, only
     * when the parent lead has not already reached a terminal status
     * (Converted/Lost/Archived), advances it to ConsultationHeld —
     * never regresses a lead that has already moved past this point.
     * Mirrors ConsultationsRelationManager's own original behavior
     * exactly.
     */
    public function markConsultationHeld(
        Consultation $consultation,
        string|\DateTimeInterface $heldAt,
        ?int $outcomeId,
        bool $converted,
    ): Consultation {
        $fresh = Consultation::query()->where('id', $consultation->id)->firstOrFail();

        if ($fresh->held_at !== null) {
            throw new RuntimeException('This consultation was already marked held.');
        }

        $fresh->update([
            'held_at' => $heldAt,
            'consultation_outcome_id' => $outcomeId,
            'converted' => $converted,
        ]);

        $lead = FirmLead::query()->where('id', $fresh->firm_lead_id)->first();

        if ($lead !== null && ! in_array($lead->status, self::LEAD_TERMINAL_STATUSES_FOR_CONSULTATION_HELD, true)) {
            $lead->update(['status' => FirmLeadStatus::ConsultationHeld]);
        }

        return $fresh->fresh();
    }
}
