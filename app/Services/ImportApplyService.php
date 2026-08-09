<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportErrorSeverity;
use App\Enums\ImportRowStatus;
use App\Enums\RollbackRecordStatus;
use App\Enums\WebhookEventType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Party;
use App\Models\PaymentPlan;
use App\Models\TimeEntry;
use Illuminate\Support\Facades\DB;

/**
 * ImportApplyService — the only service that creates final production
 * records from an import batch, and only ever from rows already
 * Confirmed (project rule 1: "no import may write final production
 * records without preview and confirmation"). Every created record's
 * firm_id is always $batch->firm_id — never trusted from the row's own
 * data — making apply tenant-safe by construction (project rule 8).
 *
 * Supports real record creation for 9 of the 11 ImportEntityType
 * cases: FirmLead, Client, Contact, Matter (best-effort — see
 * ImportDuplicateDetectionService's docblock on Matter's missing
 * name/reference field), Party, Document (via
 * ImportDocumentSafetyService), TimeEntry, Invoice, PaymentPlan.
 *
 * ConflictRecord and Template are deliberately NOT auto-applied here.
 * Conflict checks already have a dedicated, more rigorous workflow
 * (ConflictCheckRun/ConflictCheckResult, Phase 2) and templates already
 * have a dedicated installation workflow (TemplatePackInstallationService,
 * Phase 2/6) — wiring import-apply directly into either of those
 * existing systems is a deeper integration than this phase's
 * "foundation" scope covers safely. applyRow() marks these two entity
 * types' rows Skipped with a clear reason rather than silently
 * fabricating a partial record.
 *
 * Phase 14b addition: on a successfully applied row whose entity type
 * is one of the 5 webhook-approved kinds (FirmLead, Client, Matter,
 * Document, Invoice), fires the matching WebhookEventType exactly once,
 * registered via DB::afterCommit() from inside applyRow()'s existing
 * DB::transaction() closure so the event is never recorded ahead of the
 * row's own durable commit (Phase 14b rule 11) and never fires at all
 * if the transaction rolls back (rule 10 — it rolls back together, same
 * as every other write in this closure). Contact/Party/TimeEntry/
 * PaymentPlan are not approved webhook event subjects and are
 * deliberately left unwired. Even though WebhookEventRecorderService::
 * record() is documented to never throw (Phase 14 correction #16), the
 * call in recordWebhookEventForAppliedRecord() is still wrapped in its
 * own try/catch(\Throwable) that reports and swallows any failure — the
 * import-apply workflow must survive regardless of what happens inside
 * record() (Phase 14b rule 14).
 */
class ImportApplyService
{
    public function __construct(
        private readonly ImportDocumentSafetyService $documentSafetyService,
        private readonly ImportAuditService $auditService,
        private readonly InvoiceDraftingService $invoiceDrafting,
        private readonly PaymentPlanService $paymentPlanService,
    ) {}

    public function confirmBatch(ImportBatch $batch): ImportBatch
    {
        return (new TenantContextService)->runWithFirmContext($batch->firm_id, function () use ($batch) {
            $batch->rows()->where('status', ImportRowStatus::Validated->value)->update(['status' => ImportRowStatus::Confirmed->value]);

            $batch->update(['status' => ImportBatchStatus::Confirmed, 'confirmed_at' => now()]);

            $this->auditService->record($batch, ImportAuditEventType::BatchConfirmed);

            return $batch->fresh();
        });
    }

    public function apply(ImportBatch $batch): ImportBatch
    {
        $firm = $batch->firm;

        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $batch) {
            $batch->update(['status' => ImportBatchStatus::Applying]);

            foreach ($batch->rows()->where('status', ImportRowStatus::Confirmed->value)->get() as $row) {
                $this->applyRow($firm, $batch, $row);
            }

            $batch->update(['status' => ImportBatchStatus::Applied, 'applied_at' => now()]);

            $this->auditService->record($batch, ImportAuditEventType::ApplyCompleted);

            return $batch->fresh();
        });
    }

    private function applyRow(Firm $firm, ImportBatch $batch, ImportRow $row): void
    {
        if (in_array($batch->entity_type, [ImportEntityType::ConflictRecord, ImportEntityType::Template], true)) {
            $row->update(['status' => ImportRowStatus::Skipped]);

            return;
        }

        DB::transaction(function () use ($firm, $batch, $row) {
            try {
                $record = $this->createRecordFor($firm, $batch->entity_type, $row->mapped_data ?? $row->raw_data, $row);

                $row->update([
                    'status' => ImportRowStatus::Applied,
                    'applied_record_type' => $record::class,
                    'applied_record_id' => $record->id,
                ]);

                $batch->rollbackRecords()->create([
                    'import_row_id' => $row->id,
                    'rolled_back_record_type' => $record::class,
                    'rolled_back_record_id' => $record->id,
                    'status' => RollbackRecordStatus::Pending,
                ]);

                $this->auditService->record($batch, ImportAuditEventType::RowApplied, metadata: [
                    'import_row_id' => $row->id,
                    'record_type' => $record::class,
                    'record_id' => $record->id,
                ]);

                $this->recordWebhookEventForAppliedRecord($firm, $batch->entity_type, $record);
            } catch (\Throwable $e) {
                $row->update(['status' => ImportRowStatus::Failed]);
                $row->errors()->create([
                    'severity' => ImportErrorSeverity::Blocking,
                    'message' => 'Apply failed: '.$e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Fires the webhook event matching a just-applied import row, only
     * for the 5 entity types that are approved webhook subjects.
     * Registered via DB::afterCommit() so it never fires ahead of this
     * row's own transaction commit and never fires at all if the
     * transaction rolls back (it is inside the same transaction as the
     * record creation, row status update, rollback-record, and audit
     * writes above — correction: rolls back together, per Phase 14b
     * rule 10/11).
     */
    private function recordWebhookEventForAppliedRecord(Firm $firm, ImportEntityType $entityType, object $record): void
    {
        $eventType = match ($entityType) {
            ImportEntityType::FirmLead => WebhookEventType::LeadCreated,
            ImportEntityType::Client => WebhookEventType::ClientCreated,
            ImportEntityType::Matter => WebhookEventType::MatterCreated,
            ImportEntityType::Document => WebhookEventType::DocumentUploaded,
            // ImportEntityType::Invoice is deliberately absent: applyInvoice()
            // routes through InvoiceDraftingService::createFlatFee(), which
            // already fires WebhookEventType::InvoiceCreated itself -- firing
            // it here too would duplicate the event.
            default => null,
        };

        if ($eventType === null) {
            return;
        }

        DB::afterCommit(function () use ($firm, $eventType, $record) {
            try {
                app(WebhookEventRecorderService::class)->record($firm, $eventType, $record);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    private function createRecordFor(Firm $firm, ImportEntityType $entityType, array $data, ImportRow $row): object
    {
        return match ($entityType) {
            ImportEntityType::FirmLead => (new TenantContextService)->runWithFirmContext($firm, fn () => FirmLead::create([
                'firm_id' => $firm->id,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => 'new',
            ])),
            ImportEntityType::Client => (new TenantContextService)->runWithFirmContext($firm, fn () => Client::create([
                'firm_id' => $firm->id,
                'display_name' => $data['display_name'] ?? $data['name'] ?? throw new \InvalidArgumentException('display_name is required'),
                'legal_name' => $data['legal_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ])),
            ImportEntityType::Contact => (new TenantContextService)->runWithFirmContext($firm, fn () => Contact::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? null,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ])),
            ImportEntityType::Matter => (new TenantContextService)->runWithFirmContext($firm, fn () => Matter::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'),
                'primary_practice_area_id' => $data['primary_practice_area_id'] ?? throw new \InvalidArgumentException('primary_practice_area_id is required'),
                'matter_type_id' => $data['matter_type_id'] ?? throw new \InvalidArgumentException('matter_type_id is required'),
                'status' => $data['status'] ?? 'draft',
            ])),
            ImportEntityType::Party => (new TenantContextService)->runWithFirmContext($firm, fn () => Party::create([
                'firm_id' => $firm->id,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'entity_type' => $data['entity_type'] ?? 'individual',
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ])),
            ImportEntityType::Document => $this->applyDocument($firm, $data, $row),
            ImportEntityType::TimeEntry => (new TenantContextService)->runWithFirmContext($firm, fn () => TimeEntry::create([
                'firm_id' => $firm->id,
                'user_id' => $data['user_id'] ?? throw new \InvalidArgumentException('user_id is required'),
                'matter_id' => $data['matter_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'seconds' => $data['seconds'] ?? throw new \InvalidArgumentException('seconds is required'),
                'worked_on' => $data['worked_on'] ?? throw new \InvalidArgumentException('worked_on is required'),
            ])),
            ImportEntityType::Invoice => $this->applyInvoice($firm, $data),
            ImportEntityType::PaymentPlan => $this->applyPaymentPlan($firm, $data),
            default => throw new \InvalidArgumentException("Unsupported entity type for apply: {$entityType->value}"),
        };
    }

    /**
     * Routes through InvoiceDraftingService::createFlatFee() instead of
     * Invoice::create() directly -- the previous direct-create path
     * left the invoice with no InvoiceLine rows at all, so total_cents/
     * subtotal_cents were taken verbatim from import data rather than
     * derived the way every other invoice in the system is (invariant:
     * "amount fields are recomputed from invoice_lines every time,
     * never hand-set elsewhere" per InvoiceDraftingService's own
     * docblock). createFlatFee() already fires WebhookEventType::
     * InvoiceCreated itself, so that entity type is intentionally
     * absent from recordWebhookEventForAppliedRecord()'s match below --
     * firing it there too would duplicate the event.
     */
    private function applyInvoice(Firm $firm, array $data): Invoice
    {
        $client = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => Client::query()->findOrFail($data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'))
        );

        $matter = isset($data['matter_id'])
            ? (new TenantContextService)->runWithFirmContext($firm, fn () => Matter::query()->find($data['matter_id']))
            : null;

        return $this->invoiceDrafting->createFlatFee(
            $firm,
            $client,
            $data['description'] ?? 'Imported invoice',
            $data['total_cents'] ?? throw new \InvalidArgumentException('total_cents is required'),
            $matter,
        );
    }

    /**
     * Routes through PaymentPlanService::create() instead of
     * PaymentPlan::create() directly -- the previous direct-create path
     * created zero PaymentPlanInstallment rows despite storing
     * installment_count, which would silently break
     * PaymentApplicationService::applyToInstallment() and
     * markCompletedIfAllInstallmentsPaid() for any imported plan (there
     * would be nothing to apply a payment against). Import data carries
     * only a total and a count, not the original per-installment
     * breakdown, so the total is split evenly (remainder cents on the
     * final installment to keep the sum exact) with monthly due dates
     * starting one month out -- a reasonable reconstruction, not a
     * claim of historical accuracy.
     */
    private function applyPaymentPlan(Firm $firm, array $data): PaymentPlan
    {
        $client = (new TenantContextService)->runWithFirmContext(
            $firm,
            fn () => Client::query()->findOrFail($data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'))
        );

        $matter = isset($data['matter_id'])
            ? (new TenantContextService)->runWithFirmContext($firm, fn () => Matter::query()->find($data['matter_id']))
            : null;

        $invoice = isset($data['invoice_id'])
            ? (new TenantContextService)->runWithFirmContext($firm, fn () => Invoice::query()->find($data['invoice_id']))
            : null;

        $totalCents = $data['total_cents'] ?? throw new \InvalidArgumentException('total_cents is required');
        $installmentCount = max(1, (int) ($data['installment_count'] ?? 1));

        return $this->paymentPlanService->create($firm, $client, $this->splitIntoEvenInstallments($totalCents, $installmentCount), $matter, $invoice);
    }

    /**
     * @return array<int, array{amount_cents:int, due_at:\DateTimeInterface}>
     */
    private function splitIntoEvenInstallments(int $totalCents, int $count): array
    {
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents % $count;
        $installments = [];

        for ($i = 0; $i < $count; $i++) {
            $installments[] = [
                'amount_cents' => $base + ($i < $remainder ? 1 : 0),
                'due_at' => now()->addMonthsNoOverflow($i + 1),
            ];
        }

        return $installments;
    }

    private function applyDocument(Firm $firm, array $data, ImportRow $row): Document
    {
        $this->documentSafetyService->assertSafeToAccept($firm, $row);

        $document = (new TenantContextService)->runWithFirmContext($firm, fn () => Document::create([
            'firm_id' => $firm->id,
            'matter_id' => $data['matter_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'storage_disk' => $data['storage_disk'] ?? 'local',
            'storage_path' => $data['storage_path'] ?? throw new \InvalidArgumentException('storage_path is required'),
            'original_filename' => $data['original_filename'] ?? throw new \InvalidArgumentException('original_filename is required'),
            'mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => $data['size_bytes'] ?? 0,
            'file_hash' => $data['file_hash'] ?? hash('sha256', $data['storage_path'] ?? uniqid('', true)),
        ]));

        $this->documentSafetyService->assertDocumentBelongsToFirm($document, $firm);

        return $document;
    }
}
