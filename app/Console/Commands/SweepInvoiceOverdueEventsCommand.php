<?php

namespace App\Console\Commands;

use App\Enums\DomainEventType;
use App\Enums\FirmActivationStatus;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\Invoice;
use App\Services\AccountingReportingService;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * automation:sweep:invoice-overdue — Event-Driven Automation Engine,
 * item 3/13. "Overdue" is a derived, time-crossing state (today minus
 * due_at), not a service-level mutation — there is no real write call
 * site to hook a domain event into (confirmed by this pass's own
 * audit). This sweep walks each firm's own already-existing overdue
 * calculation (AccountingReportingService::accountsReceivableAging(),
 * unmodified, never re-derived here) and emits
 * DomainEventType::InvoiceOverdue for any invoice with days_overdue > 0
 * that hasn't already had one emitted — a plain existence check against
 * domain_events keyed on (subject_type=Invoice, subject_id, event_type),
 * so an invoice that's been overdue for 40 days only ever gets ONE
 * event, not one per sweep run. The specific "at least 7 days overdue"
 * threshold this pass's starter automation cares about lives entirely
 * in that AutomationRule's own condition — this sweep's job is only to
 * report the fact "this invoice is overdue," never to bake in any
 * specific rule's own threshold.
 */
final class SweepInvoiceOverdueEventsCommand extends Command
{
    protected $signature = 'automation:sweep:invoice-overdue';

    protected $description = 'Emits an InvoiceOverdue domain event for each invoice newly found overdue, for every activated firm.';

    public function __construct(
        private readonly AccountingReportingService $reporting,
        private readonly DomainEventRecorderService $domainEvents,
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
        $report = $this->reporting->accountsReceivableAging($firm);

        foreach ($report->data as $row) {
            /** @var Invoice $invoice */
            $invoice = $row['invoice'];

            if ($row['days_overdue'] <= 0) {
                continue;
            }

            (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $invoice, $row) {
                $alreadyEmitted = DomainEvent::query()
                    ->where('subject_type', $invoice->getMorphClass())
                    ->where('subject_id', $invoice->id)
                    ->where('event_type', DomainEventType::InvoiceOverdue->value)
                    ->exists();

                if ($alreadyEmitted) {
                    return;
                }

                $this->domainEvents->record($firm, DomainEventType::InvoiceOverdue, [
                    'invoice' => [
                        'id' => $invoice->id,
                        'status' => $invoice->status->value,
                        'balance_due_cents' => $row['remaining_cents'],
                        'total_cents' => $invoice->total_cents,
                        'days_overdue' => $row['days_overdue'],
                        'bucket' => $row['bucket'],
                    ],
                    'client' => ['id' => $invoice->client_id],
                    'matter' => ['id' => $invoice->matter_id],
                ], subject: $invoice);
            });
        }
    }
}
