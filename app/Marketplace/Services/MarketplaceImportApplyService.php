<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryImportBatchStatus;
use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\DirectoryImportBatch;
use App\Marketplace\Models\DirectoryImportRow;
use App\Models\PlatformAdmin;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * MarketplaceImportApplyService — Mission 2 (MyAttorney Marketplace
 * Core), sections 53-55. The only place a DirectoryImportBatch's rows
 * turn into real directory_firms writes.
 *
 * Source-precedence rule (section 53-55, verbatim): "verified Firm-
 * managed data must not be silently overwritten by older CSV import;
 * a stale public source must not override a more-recent verified
 * source." Concretely: a Duplicate row's update is refused (the row is
 * marked Skipped, never silently dropped) whenever the matched
 * existing listing is already claimed (is_claimed — Firm-managed data
 * takes precedence over any CSV, unconditionally) OR has been
 * verified/firm-confirmed more recently than the import batch itself
 * was created (the CSV is provably the staler source).
 *
 * A newly created listing always starts Draft, never auto-published —
 * bulk-imported data goes live only after a SuperAdmin review
 * (checkpoint 11), consistent with section 27's "no uncontrolled
 * scraping" caution.
 *
 * Section 27's SOURCE_APPROVAL_REQUIRED gate: apply() refuses to run
 * at all until the importing admin has explicitly confirmed source
 * rights via confirmSourceRights() — never inferred, never bypassed.
 */
class MarketplaceImportApplyService
{
    private const APPLIABLE_FIELDS = [
        'legal_name', 'display_name', 'name_normalized', 'phone', 'website',
        'public_email', 'description', 'city', 'city_normalized', 'state', 'postal_code', 'founding_year',
    ];

    public function __construct(
        private readonly TenantContextService $tenantContext = new TenantContextService,
        private readonly MarketplaceProfileVersionService $versions = new MarketplaceProfileVersionService,
    ) {}

    /**
     * @return array{creatable: int, updatable: int, skipped_already_claimed: int, skipped_more_recently_verified: int, invalid: int}
     */
    public function preview(DirectoryImportBatch $batch): array
    {
        $summary = ['creatable' => 0, 'updatable' => 0, 'skipped_already_claimed' => 0, 'skipped_more_recently_verified' => 0, 'invalid' => 0];

        foreach ($batch->rows()->get() as $row) {
            if ($row->status === DirectoryImportRowStatus::Valid) {
                $summary['creatable']++;

                continue;
            }

            if ($row->status === DirectoryImportRowStatus::Invalid) {
                $summary['invalid']++;

                continue;
            }

            if ($row->status === DirectoryImportRowStatus::Duplicate) {
                $target = $row->duplicateOfFirm;
                $reason = $target !== null ? $this->refusalReason($target, $batch) : null;

                match ($reason) {
                    'already_claimed' => $summary['skipped_already_claimed']++,
                    'more_recently_verified' => $summary['skipped_more_recently_verified']++,
                    default => $summary['updatable']++,
                };
            }
        }

        return $summary;
    }

    public function confirmSourceRights(DirectoryImportBatch $batch): DirectoryImportBatch
    {
        $batch->update(['source_rights_confirmed' => true]);

        return $batch->fresh();
    }

    /**
     * @param  array<int, 'update'|'skip'>  $duplicateRowDecisions  Keyed by DirectoryImportRow id. A Duplicate row with no explicit decision defaults to 'skip' — never silently applied.
     */
    public function apply(DirectoryImportBatch $batch, PlatformAdmin $admin, array $duplicateRowDecisions = []): DirectoryImportBatch
    {
        if (! $batch->source_rights_confirmed) {
            $batch->update(['status' => DirectoryImportBatchStatus::SourceApprovalRequired]);

            throw new \RuntimeException('This import batch requires explicit source-rights confirmation before it can be applied (section 27).');
        }

        $appliedCount = 0;
        $skippedCount = 0;

        foreach ($batch->rows()->get() as $row) {
            if ($row->status === DirectoryImportRowStatus::Valid) {
                $this->createFromRow($row, $batch);
                $appliedCount++;

                continue;
            }

            if ($row->status !== DirectoryImportRowStatus::Duplicate) {
                continue;
            }

            $decision = $duplicateRowDecisions[$row->id] ?? 'skip';
            $target = $row->duplicateOfFirm;

            if ($decision !== 'update' || $target === null) {
                $row->update(['status' => DirectoryImportRowStatus::Skipped]);
                $skippedCount++;

                continue;
            }

            $reason = $this->refusalReason($target, $batch);
            if ($reason !== null) {
                $row->update(['status' => DirectoryImportRowStatus::Skipped]);
                $skippedCount++;

                continue;
            }

            $this->updateFromRow($row, $target, $admin);
            $appliedCount++;
        }

        $batch->update([
            'status' => DirectoryImportBatchStatus::Applied,
            'applied_rows' => $appliedCount,
            'skipped_rows' => $skippedCount,
        ]);

        $this->audit($batch, $admin, 'marketplace_import_applied', [
            'directory_import_batch_id' => $batch->id,
            'applied_rows' => $appliedCount,
            'skipped_rows' => $skippedCount,
        ]);

        return $batch->fresh();
    }

    private function createFromRow(DirectoryImportRow $row, DirectoryImportBatch $batch): DirectoryFirm
    {
        $data = $row->mapped_data;

        $firm = DirectoryFirm::create(array_merge($data, [
            'slug' => DirectoryFirm::generateUniqueSlug($data['display_name']),
            'publication_state' => DirectoryPublicationState::Draft,
            'source_type' => DataProvenanceSourceType::CsvImport,
            'source_reference' => (string) $batch->uuid,
            'imported_at' => now(),
        ]));

        $row->update(['status' => DirectoryImportRowStatus::Applied, 'applied_directory_firm_id' => $firm->id]);

        return $firm;
    }

    private function updateFromRow(DirectoryImportRow $row, DirectoryFirm $target, PlatformAdmin $admin): void
    {
        $changes = array_intersect_key($row->mapped_data, array_flip(self::APPLIABLE_FIELDS));
        $changes = array_filter($changes, fn ($value) => $value !== null && $value !== '');

        if ($changes !== []) {
            $target->update($changes);
            $this->versions->record($target, $changes, 'csv_import', null, DataProvenanceSourceType::CsvImport);
        }

        $row->update(['status' => DirectoryImportRowStatus::Applied, 'applied_directory_firm_id' => $target->id]);
    }

    private function refusalReason(DirectoryFirm $target, DirectoryImportBatch $batch): ?string
    {
        if ($target->is_claimed) {
            return 'already_claimed';
        }

        $moreRecentlyVerified = ($target->last_verified_at !== null && $target->last_verified_at->greaterThan($batch->created_at))
            || ($target->last_confirmed_by_firm_at !== null && $target->last_confirmed_by_firm_at->greaterThan($batch->created_at));

        return $moreRecentlyVerified ? 'more_recently_verified' : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(DirectoryImportBatch $batch, PlatformAdmin $admin, string $eventType, array $metadata): void
    {
        $this->tenantContext->runWithoutFirmContext(function () use ($admin, $eventType, $metadata) {
            DB::table('security_events')->insert([
                'firm_id' => null,
                'actor_type' => PlatformAdmin::class,
                'actor_id' => $admin->id,
                'event_type' => $eventType,
                'category' => 'marketplace_import',
                'metadata' => json_encode($metadata),
                'created_at' => now(),
            ]);
        });
    }
}
