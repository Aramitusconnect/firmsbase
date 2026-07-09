<?php

namespace App\Services;

use App\Enums\ImportAuditEventType;
use App\Enums\ImportEntityType;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Document;
use App\Models\ImportRow;
use App\Models\Matter;
use App\Models\Party;
use App\ValueObjects\DuplicateDetectionResult;

/**
 * ImportDuplicateDetectionService — implements duplicate detection
 * foundation exactly for the entity types the approved scope names:
 * clients (name/email/phone), contacts (email/phone), matters
 * (client/reference/name where possible), documents (filename/hash/
 * source path), parties (name/email/phone where available), and
 * invoices/payment_plans (external reference/idempotency source).
 *
 * Invoices and payment_plans (approved correction #4/#5): Phase 3's
 * `invoices`/`payment_plans` tables carry no external_reference/
 * idempotency column, and this phase must not add one. Duplicate
 * detection for these two entity types therefore compares ONLY against
 * other ImportRow rows already stored for the same firm (via
 * raw_data/mapped_data's own source-reference field, e.g.
 * 'external_reference' or 'source_id') — never against the live
 * invoices/payment_plans table.
 *
 * Matters have no natural "reference" or "name" column in Phase 2's
 * schema (only client_id/practice_area/matter_type/status/stage) — so
 * matter duplicate detection is a best-effort match on
 * (firm_id, client_id, primary_practice_area_id, matter_type_id),
 * documented here as a real limitation ("where possible", per the
 * approved scope's own qualifier), not a false claim of full support.
 *
 * FirmLead, TimeEntry, ConflictRecord, and Template are NOT in the
 * approved duplicate-detection list — detect() always returns
 * DuplicateDetectionResult::noMatch() for these, deliberately, rather
 * than silently guessing at a matching rule nobody approved.
 */
class ImportDuplicateDetectionService
{
    public function __construct(
        private readonly ImportAuditService $auditService,
    ) {
    }

    public function detect(ImportRow $row): DuplicateDetectionResult
    {
        $batch = $row->importBatch;
        $data = $row->mapped_data ?? $row->raw_data;

        $result = match ($batch->entity_type) {
            ImportEntityType::Client => $this->detectClient($batch->firm_id, $data),
            ImportEntityType::Contact => $this->detectContact($batch->firm_id, $data),
            ImportEntityType::Matter => $this->detectMatter($batch->firm_id, $data),
            ImportEntityType::Document => $this->detectDocument($batch->firm_id, $data),
            ImportEntityType::Party => $this->detectParty($batch->firm_id, $data),
            ImportEntityType::Invoice, ImportEntityType::PaymentPlan => $this->detectViaImportMetadataOnly($batch->firm_id, $batch->entity_type, $row),
            default => DuplicateDetectionResult::noMatch(),
        };

        if ($result->isDuplicate) {
            $row->update([
                'is_duplicate' => true,
                'duplicate_of_type' => $result->matchedType,
                'duplicate_of_id' => $result->matchedId,
            ]);

            $this->auditService->record($batch, ImportAuditEventType::DuplicateDetected, metadata: [
                'import_row_id' => $row->id,
                'matched_type' => $result->matchedType,
                'matched_id' => $result->matchedId,
                'reason' => $result->matchReason,
            ]);
        }

        return $result;
    }

    private function detectClient(int $firmId, array $data): DuplicateDetectionResult
    {
        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId, $data) {
            $query = Client::query()->where('firm_id', $firmId);

            if (! empty($data['email'])) {
                $match = (clone $query)->where('email', $data['email'])->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Client::class, $match->id, 'email match');
                }
            }

            if (! empty($data['phone'])) {
                $match = (clone $query)->where('phone', $data['phone'])->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Client::class, $match->id, 'phone match');
                }
            }

            if (! empty($data['display_name']) || ! empty($data['name'])) {
                $name = $data['display_name'] ?? $data['name'];
                $match = (clone $query)->where('display_name', $name)->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Client::class, $match->id, 'name match');
                }
            }

            return DuplicateDetectionResult::noMatch();
        });
    }

    private function detectContact(int $firmId, array $data): DuplicateDetectionResult
    {
        $query = Contact::query()->where('firm_id', $firmId);

        if (! empty($data['email'])) {
            $match = (clone $query)->where('email', $data['email'])->first();
            if ($match) {
                return DuplicateDetectionResult::match(Contact::class, $match->id, 'email match');
            }
        }

        if (! empty($data['phone'])) {
            $match = (clone $query)->where('phone', $data['phone'])->first();
            if ($match) {
                return DuplicateDetectionResult::match(Contact::class, $match->id, 'phone match');
            }
        }

        return DuplicateDetectionResult::noMatch();
    }

    /**
     * Best-effort only — matters have no natural reference/name field
     * in this schema (see class docblock).
     */
    private function detectMatter(int $firmId, array $data): DuplicateDetectionResult
    {
        if (empty($data['client_id'])) {
            return DuplicateDetectionResult::noMatch();
        }

        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId, $data) {
            $query = Matter::query()
                ->where('firm_id', $firmId)
                ->where('client_id', $data['client_id']);

            if (! empty($data['primary_practice_area_id'])) {
                $query->where('primary_practice_area_id', $data['primary_practice_area_id']);
            }

            if (! empty($data['matter_type_id'])) {
                $query->where('matter_type_id', $data['matter_type_id']);
            }

            $match = $query->first();

            if ($match) {
                return DuplicateDetectionResult::match(Matter::class, $match->id, 'client + practice area + matter type match (best effort)');
            }

            return DuplicateDetectionResult::noMatch();
        });
    }

    private function detectDocument(int $firmId, array $data): DuplicateDetectionResult
    {
        return (new TenantContextService())->runWithFirmContext($firmId, function () use ($firmId, $data) {
            $query = Document::query()->where('firm_id', $firmId);

            if (! empty($data['file_hash'])) {
                $match = (clone $query)->where('file_hash', $data['file_hash'])->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Document::class, $match->id, 'file hash match');
                }
            }

            if (! empty($data['original_filename'])) {
                $match = (clone $query)->where('original_filename', $data['original_filename'])->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Document::class, $match->id, 'filename match');
                }
            }

            if (! empty($data['storage_path'])) {
                $match = (clone $query)->where('storage_path', $data['storage_path'])->first();
                if ($match) {
                    return DuplicateDetectionResult::match(Document::class, $match->id, 'source path match');
                }
            }

            return DuplicateDetectionResult::noMatch();
        });
    }

    private function detectParty(int $firmId, array $data): DuplicateDetectionResult
    {
        if (empty($data['name'])) {
            return DuplicateDetectionResult::noMatch();
        }

        $query = Party::query()->where('firm_id', $firmId)->where('name', $data['name']);

        if (! empty($data['email'])) {
            $match = (clone $query)->where('email', $data['email'])->first();
            if ($match) {
                return DuplicateDetectionResult::match(Party::class, $match->id, 'name + email match');
            }
        }

        if (! empty($data['phone'])) {
            $match = (clone $query)->where('phone', $data['phone'])->first();
            if ($match) {
                return DuplicateDetectionResult::match(Party::class, $match->id, 'name + phone match');
            }
        }

        return DuplicateDetectionResult::noMatch();
    }

    /**
     * Invoices/payment_plans: compares against OTHER ImportRow rows for
     * the same firm carrying the same source reference — never against
     * the live invoices/payment_plans table (approved correction #5).
     */
    private function detectViaImportMetadataOnly(int $firmId, ImportEntityType $entityType, ImportRow $row): DuplicateDetectionResult
    {
        $data = $row->mapped_data ?? $row->raw_data;
        $reference = $data['external_reference'] ?? $data['source_id'] ?? $data['idempotency_key'] ?? null;

        if ($reference === null) {
            return DuplicateDetectionResult::noMatch();
        }

        $match = ImportRow::query()
            ->whereHas('importBatch', fn ($q) => $q->where('firm_id', $firmId)->where('entity_type', $entityType->value))
            ->where('id', '!=', $row->id)
            ->where('row_number', '<', $row->row_number)
            ->get()
            ->first(function (ImportRow $candidate) use ($reference) {
                $candidateData = $candidate->mapped_data ?? $candidate->raw_data;
                $candidateReference = $candidateData['external_reference'] ?? $candidateData['source_id'] ?? $candidateData['idempotency_key'] ?? null;

                return $candidateReference !== null && (string) $candidateReference === (string) $reference;
            });

        if ($match) {
            return DuplicateDetectionResult::match(ImportRow::class, $match->id, 'matching source reference in prior import metadata');
        }

        return DuplicateDetectionResult::noMatch();
    }
}
