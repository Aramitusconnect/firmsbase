<?php

namespace App\Console\Commands;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Enums\FirmUserRole;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentChaseSchedulerService;
use App\Services\DocumentChaseService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:document-request-reminders — Zero-Click Core
 * Workflow Automation, item 4B/12. Wraps the EXISTING
 * DocumentChaseSchedulerService (schedule/policy math) and
 * DocumentChaseService::checkAndLog()/escalate() unmodified — neither
 * previously had a scheduled caller (confirmed by this mission's own
 * audit: "no scheduled trigger wires this automatically"). This
 * command is exactly that missing trigger, nothing more — every
 * eligibility/consent check remains inside checkAndLog() itself.
 *
 * Idempotency: before evaluating an item, checks whether a
 * DocumentChaseEvent of type reminder_queued/reminder_skipped/escalated
 * already exists for it TODAY — running this sweep twice on the same
 * day never double-logs or double-emits for the same item (item 26's
 * own explicit "sweep runs twice -> only one reminder" requirement).
 * DomainEventType::DocumentRequestReminderDue is only emitted
 * alongside a genuinely NEW DocumentChaseEvent row created in this
 * same run.
 */
final class SweepDocumentRequestRemindersCommand extends Command
{
    protected $signature = 'automation:sweep:document-request-reminders';

    protected $description = 'Evaluates document-request reminder/escalation checkpoints and emits DocumentRequestReminderDue, for every activated firm.';

    public function __construct(
        private readonly DocumentChaseSchedulerService $scheduler,
        private readonly DocumentChaseService $chase,
        private readonly DomainEventRecorderService $domainEvents,
        private readonly AutomationRecipientResolverService $recipients,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->cursor()
            ->each(fn (Firm $firm) => $this->sweepFirm($firm));

        return self::SUCCESS;
    }

    private function sweepFirm(Firm $firm): void
    {
        (new TenantContextService)->runWithFirmContext($firm, function () use ($firm) {
            $items = DocumentRequestItem::query()
                ->whereIn('status', [
                    DocumentRequestItemStatus::Requested->value,
                    DocumentRequestItemStatus::Viewed->value,
                    DocumentRequestItemStatus::NeedsReplacement->value,
                ])
                ->whereHas('documentRequest', fn ($q) => $q->where('firm_id', $firm->id))
                ->with('documentRequest')
                ->get();

            foreach ($items as $item) {
                $this->sweepItem($firm, $item);
            }
        });
    }

    private function sweepItem(Firm $firm, DocumentRequestItem $item): void
    {
        $rule = $this->scheduler->applicableRule($firm, $item);

        if ($rule === null) {
            return;
        }

        $daysSinceRequested = (int) $item->created_at->diffInDays(now());

        if ($this->scheduler->isReminderDue($rule, $item, $daysSinceRequested) && ! $this->alreadyEvaluatedToday($item, ['reminder_queued', 'reminder_skipped'])) {
            $result = $this->chase->checkAndLog($firm, $item, $rule);

            if ($result->eligible) {
                $this->emit($firm, $item, $daysSinceRequested, isEscalation: false);
            }
        }

        if ($this->scheduler->isEscalationDue($rule, $daysSinceRequested) && ! $this->alreadyEvaluatedToday($item, ['escalated'])) {
            $escalationActor = $this->recipients->usersWithRole($firm, FirmUserRole::FirmOwner)->first();

            if ($escalationActor !== null) {
                $this->chase->escalate($firm, $item, $rule, $escalationActor);
                $this->emit($firm, $item, $daysSinceRequested, isEscalation: true);
            }
        }
    }

    /**
     * @param  array<int, string>  $eventTypes
     */
    private function alreadyEvaluatedToday(DocumentRequestItem $item, array $eventTypes): bool
    {
        return $item->chaseEvents()
            ->whereIn('event_type', $eventTypes)
            ->whereDate('created_at', now()->toDateString())
            ->exists();
    }

    private function emit(Firm $firm, DocumentRequestItem $item, int $daysSinceRequested, bool $isEscalation): void
    {
        $matter = $item->documentRequest->matter;

        $this->domainEvents->record($firm, DomainEventType::DocumentRequestReminderDue, [
            'document_request_item' => [
                'id' => $item->id,
                'days_since_requested' => $daysSinceRequested,
                'is_escalation' => $isEscalation,
            ],
            'document_request' => ['id' => $item->documentRequest->id],
            'matter' => [
                'id' => $item->documentRequest->matter_id,
                'assigned_attorney_id' => $matter?->assigned_attorney_id,
            ],
            'client' => ['id' => $item->documentRequest->client_id],
        ], subject: $item);
    }
}
