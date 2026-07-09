<?php

namespace App\Services;

use App\Enums\DeadlineStatus;
use App\Enums\DocumentStatus;
use App\Enums\FirmLeadStatus;
use App\Enums\FormDraftStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MatterStatus;
use App\Enums\PaymentPlanInstallmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Deadline;
use App\Models\Document;
use App\Models\DocumentChaseEvent;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FormDraft;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\PaymentPlanInstallment;
use App\Models\Task;
use App\ValueObjects\CommandCenterSnapshot;
use Carbon\CarbonInterface;

/**
 * FirmCommandCenterAggregationService — a read-only, backend-only
 * aggregation of existing firm-scoped data into a single
 * CommandCenterSnapshot (approved decision: no UI, no cache table, no
 * persisted snapshot, no new state in this section). Every widget is
 * built exclusively from real, existing models/enums confirmed by
 * direct repository inspection; nothing is invented. This class never
 * writes anything.
 */
class FirmCommandCenterAggregationService
{
    public function snapshot(Firm $firm, ?CarbonInterface $asOf = null, int $inactiveClientDays = 30): CommandCenterSnapshot
    {
        $asOf = $asOf ?? now();

        return new CommandCenterSnapshot(
            newLeadsCount: FirmLead::query()
                ->where('firm_id', $firm->id)
                ->where('status', FirmLeadStatus::New)
                ->count(),
            consultationsCount: Consultation::query()
                ->where('firm_id', $firm->id)
                ->whereNull('held_at')
                ->where('scheduled_at', '>=', $asOf)
                ->count(),
            mattersWaitingOnClientCount: Matter::query()
                ->where('firm_id', $firm->id)
                ->where('status', MatterStatus::WaitingOnClient)
                ->count(),
            mattersReadyForReviewCount: Matter::query()
                ->where('firm_id', $firm->id)
                ->where('status', MatterStatus::ReadyForReview)
                ->count(),
            documentsNeedingApprovalCount: Document::query()
                ->where('firm_id', $firm->id)
                ->where('status', DocumentStatus::PendingReview)
                ->count(),
            deadlinesThisWeekCount: Deadline::query()
                ->where('firm_id', $firm->id)
                ->whereNotIn('status', [DeadlineStatus::Completed, DeadlineStatus::Cancelled])
                ->whereBetween('due_at', [$asOf->copy()->startOfDay(), $asOf->copy()->addDays(7)->endOfDay()])
                ->count(),
            unpaidInvoicesCount: Invoice::query()
                ->where('firm_id', $firm->id)
                ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid])
                ->count(),
            installmentsDueCount: PaymentPlanInstallment::query()
                ->whereHas('paymentPlan', fn ($query) => $query->where('firm_id', $firm->id))
                ->where('status', PaymentPlanInstallmentStatus::Due)
                ->count(),
            installmentsMissedCount: PaymentPlanInstallment::query()
                ->whereHas('paymentPlan', fn ($query) => $query->where('firm_id', $firm->id))
                ->where('status', PaymentPlanInstallmentStatus::Missed)
                ->count(),
            failedPaymentsCount: Payment::query()
                ->where('firm_id', $firm->id)
                ->where('status', PaymentStatus::Failed)
                ->count(),
            inactiveClientsCount: (new TenantContextService())->runWithFirmContext($firm, fn () => Client::query()
                ->where('firm_id', $firm->id)
                ->where('updated_at', '<=', $asOf->copy()->subDays($inactiveClientDays))
                ->count()),
            overdueTasksCount: Task::query()
                ->where('firm_id', $firm->id)
                ->where('status', TaskStatus::Overdue)
                ->count(),
            blockedTasksCount: Task::query()
                ->where('firm_id', $firm->id)
                ->where('status', TaskStatus::Blocked)
                ->count(),
            formsReadyForReviewCount: FormDraft::query()
                ->where('firm_id', $firm->id)
                ->where('status', FormDraftStatus::ReadyForReview)
                ->count(),
            documentChaseEscalationsCount: DocumentChaseEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', 'escalated')
                ->count(),
            generatedAt: $asOf,
        );
    }
}
