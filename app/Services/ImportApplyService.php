<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportEntityType;
use App\Enums\ImportRowStatus;
use App\Enums\RollbackRecordStatus;
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
 */
class ImportApplyService
{
    public function __construct(
        private readonly ImportDocumentSafetyService $documentSafetyService,
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function confirmBatch(ImportBatch $batch): ImportBatch
    {
        $batch->rows()->where('status', ImportRowStatus::Validated->value)->update(['status' => ImportRowStatus::Confirmed->value]);

        $batch->update(['status' => ImportBatchStatus::Confirmed, 'confirmed_at' => now()]);

        $this->auditService->record($batch, ImportAuditEventType::BatchConfirmed);

        return $batch->fresh();
    }

    public function apply(ImportBatch $batch): ImportBatch
    {
        $firm = $batch->firm;
        $batch->update(['status' => ImportBatchStatus::Applying]);

        foreach ($batch->rows()->where('status', ImportRowStatus::Confirmed->value)->get() as $row) {
            $this->applyRow($firm, $batch, $row);
        }

        $batch->update(['status' => ImportBatchStatus::Applied, 'applied_at' => now()]);

        $this->auditService->record($batch, ImportAuditEventType::ApplyCompleted);

        return $batch->fresh();
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
            } catch (\Throwable $e) {
                $row->update(['status' => ImportRowStatus::Failed]);
                $row->errors()->create([
                    'severity' => \App\Enums\ImportErrorSeverity::Blocking,
                    'message' => 'Apply failed: '.$e->getMessage(),
                ]);
            }
        });
    }

    private function createRecordFor(Firm $firm, ImportEntityType $entityType, array $data, ImportRow $row): object
    {
        return match ($entityType) {
            ImportEntityType::FirmLead => FirmLead::create([
                'firm_id' => $firm->id,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => 'new',
            ]),
            ImportEntityType::Client => Client::create([
                'firm_id' => $firm->id,
                'display_name' => $data['display_name'] ?? $data['name'] ?? throw new \InvalidArgumentException('display_name is required'),
                'legal_name' => $data['legal_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]),
            ImportEntityType::Contact => Contact::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? null,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]),
            ImportEntityType::Matter => Matter::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'),
                'primary_practice_area_id' => $data['primary_practice_area_id'] ?? throw new \InvalidArgumentException('primary_practice_area_id is required'),
                'matter_type_id' => $data['matter_type_id'] ?? throw new \InvalidArgumentException('matter_type_id is required'),
                'status' => $data['status'] ?? 'draft',
            ]),
            ImportEntityType::Party => Party::create([
                'firm_id' => $firm->id,
                'name' => $data['name'] ?? throw new \InvalidArgumentException('name is required'),
                'entity_type' => $data['entity_type'] ?? 'individual',
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]),
            ImportEntityType::Document => $this->applyDocument($firm, $data, $row),
            ImportEntityType::TimeEntry => TimeEntry::create([
                'firm_id' => $firm->id,
                'user_id' => $data['user_id'] ?? throw new \InvalidArgumentException('user_id is required'),
                'matter_id' => $data['matter_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'seconds' => $data['seconds'] ?? throw new \InvalidArgumentException('seconds is required'),
                'worked_on' => $data['worked_on'] ?? throw new \InvalidArgumentException('worked_on is required'),
            ]),
            ImportEntityType::Invoice => Invoice::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'),
                'matter_id' => $data['matter_id'] ?? null,
                'total_cents' => $data['total_cents'] ?? 0,
                'subtotal_cents' => $data['subtotal_cents'] ?? ($data['total_cents'] ?? 0),
            ]),
            ImportEntityType::PaymentPlan => PaymentPlan::create([
                'firm_id' => $firm->id,
                'client_id' => $data['client_id'] ?? throw new \InvalidArgumentException('client_id is required'),
                'matter_id' => $data['matter_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'total_cents' => $data['total_cents'] ?? throw new \InvalidArgumentException('total_cents is required'),
                'installment_count' => $data['installment_count'] ?? 1,
            ]),
            default => throw new \InvalidArgumentException("Unsupported entity type for apply: {$entityType->value}"),
        };
    }

    private function applyDocument(Firm $firm, array $data, ImportRow $row): Document
    {
        $this->documentSafetyService->assertSafeToAccept($firm, $row);

        $document = Document::create([
            'firm_id' => $firm->id,
            'matter_id' => $data['matter_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'storage_disk' => $data['storage_disk'] ?? 'local',
            'storage_path' => $data['storage_path'] ?? throw new \InvalidArgumentException('storage_path is required'),
            'original_filename' => $data['original_filename'] ?? throw new \InvalidArgumentException('original_filename is required'),
            'mime_type' => $data['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => $data['size_bytes'] ?? 0,
            'file_hash' => $data['file_hash'] ?? hash('sha256', $data['storage_path'] ?? uniqid('', true)),
        ]);

        $this->documentSafetyService->assertDocumentBelongsToFirm($document, $firm);

        return $document;
    }
}
