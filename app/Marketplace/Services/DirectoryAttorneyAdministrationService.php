<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryAttorneyFirm;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DirectoryAttorneyAdministrationService — MyAttorney SuperAdmin
 * console professionalization mission (MYAT3). No admin write path
 * exists yet for DirectoryAttorney at all (only a public read-only
 * profile controller) — this is the first one, deliberately mirroring
 * two already-established shapes rather than inventing a third:
 * DirectoryFirmAdministrationService's create()/update() (manual
 * entry, provenance stamping, profile-agnostic edit allowlist) and
 * MarketplaceModerationService's transition()/audit() shape for
 * publish/unpublish/archive (kept as a SEPARATE service rather than
 * generalizing MarketplaceModerationService itself, since that
 * service's audit() resolves a linked tenant Firm via
 * DirectoryFirm::firm() directly — DirectoryAttorney has no such
 * relation, it goes through firmRelationships(), so forcing both
 * models through one service would mean widening a well-tested
 * existing service's contract for a relationship path it was never
 * built to express).
 *
 * Firm association changes go through associateWithFirm()/
 * endFirmAssociation() rather than a raw DirectoryAttorneyFirm write —
 * "a safe workflow" per this mission's own instruction — reusing the
 * existing DirectoryAttorneyFirmRelationshipState machine (an attorney
 * moving firms transitions the existing row, per its own docblock,
 * never spawns a duplicate — enforced by the table's unique
 * (directory_attorney_id, directory_firm_id) constraint).
 */
class DirectoryAttorneyAdministrationService
{
    private const EDITABLE_ATTORNEY_FIELDS = [
        'name',
        'title',
        'biography',
        'bar_number',
        'license_jurisdictions',
    ];

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $audit = new PlatformAdminAuditEventRecorder,
        private readonly MarketplaceImportDuplicateDetectionService $duplicates = new MarketplaceImportDuplicateDetectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    public function create(array $data, array $practiceAreaIds, array $languageIds, PlatformAdmin $admin): DirectoryAttorney
    {
        return DB::transaction(function () use ($data, $practiceAreaIds, $languageIds, $admin): DirectoryAttorney {
            $name = (string) $data['name'];
            $slug = DirectoryAttorney::generateUniqueSlug($name);

            /**
             * MyAttorney final hardening mission, finding 7: same
             * server-side enforced Create Anyway gate as
             * DirectoryFirmAdministrationService::create() — see that
             * method's own docblock for why this check is not solely
             * relied on the Filament form's client-observable state.
             */
            $duplicate = $this->duplicates->findAttorneyDuplicateCandidate([
                'name' => $name,
                'bar_number' => $data['bar_number'] ?? null,
            ]);

            $overrideReason = trim((string) ($data['duplicate_override_reason'] ?? ''));

            if ($duplicate !== null && $overrideReason === '') {
                throw ValidationException::withMessages([
                    'duplicate_override_reason' => 'A reason is required to create this attorney despite a possible duplicate match.',
                ]);
            }

            $attorney = DirectoryAttorney::create([
                'slug' => $slug,
                'name' => $name,
                'name_normalized' => Str::lower($name),
                'title' => $data['title'] ?? null,
                'biography' => $data['biography'] ?? null,
                'bar_number' => $data['bar_number'] ?? null,
                'license_jurisdictions' => $data['license_jurisdictions'] ?? [],
                'publication_state' => $data['publication_state'] ?? DirectoryPublicationState::Draft,
                'source_type' => DataProvenanceSourceType::AdminEntered,
                'source_reference' => 'platform_admin:'.$admin->id,
                'imported_at' => null,
                'last_verified_at' => null,
            ]);

            $this->syncPracticeAreasAndLanguages($attorney, $practiceAreaIds, $languageIds);

            if (filled($data['directory_firm_id'] ?? null)) {
                $this->associateWithFirm($attorney, (int) $data['directory_firm_id'], $data['firm_title'] ?? null, $admin);
            }

            $metadata = [
                'directory_attorney_id' => $attorney->id,
                'slug' => $attorney->slug,
                'publication_state' => $attorney->publication_state->value,
            ];

            if ($duplicate !== null) {
                $metadata['duplicate_override'] = [
                    'matched_directory_attorney_id' => $duplicate['attorney']->id,
                    'matching_reasons' => $duplicate['reasons'],
                    'reason' => $overrideReason,
                ];
            }

            $this->writeAudit($attorney, $admin, 'marketplace_attorney_created', $metadata);

            return $attorney->fresh(['firmRelationships', 'practiceAreas', 'languages']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    public function update(DirectoryAttorney $attorney, array $data, array $practiceAreaIds, array $languageIds, PlatformAdmin $admin): DirectoryAttorney
    {
        return DB::transaction(function () use ($attorney, $data, $practiceAreaIds, $languageIds, $admin): DirectoryAttorney {
            $changes = [];
            $attributes = [];

            foreach (self::EDITABLE_ATTORNEY_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $current = $attorney->{$field};

                if ($current !== $value) {
                    $changes[$field] = $value;
                }

                $attributes[$field] = $value;
            }

            if (array_key_exists('name', $attributes)) {
                $attributes['name_normalized'] = Str::lower((string) $attributes['name']);
            }

            if ($attributes !== []) {
                $attorney->update($attributes);
            }

            $this->syncPracticeAreasAndLanguages($attorney, $practiceAreaIds, $languageIds);

            $this->writeAudit($attorney, $admin, 'marketplace_attorney_updated', [
                'directory_attorney_id' => $attorney->id,
                'changed_fields' => array_keys($changes),
            ]);

            return $attorney->fresh(['firmRelationships', 'practiceAreas', 'languages']);
        });
    }

    public function publish(DirectoryAttorney $attorney, PlatformAdmin $admin): DirectoryAttorney
    {
        return $this->transition($attorney, $admin, DirectoryPublicationState::Published, 'marketplace_attorney_published');
    }

    public function unpublish(DirectoryAttorney $attorney, PlatformAdmin $admin): DirectoryAttorney
    {
        return $this->transition($attorney, $admin, DirectoryPublicationState::Draft, 'marketplace_attorney_unpublished');
    }

    public function archive(DirectoryAttorney $attorney, PlatformAdmin $admin): DirectoryAttorney
    {
        return $this->transition($attorney, $admin, DirectoryPublicationState::Archived, 'marketplace_attorney_archived');
    }

    /**
     * The "safe workflow" for changing an attorney's firm association.
     * Ends any existing Current relationship to a DIFFERENT firm
     * (transitions it to Former — never deletes the row, preserving
     * history) before creating/reactivating the relationship to the
     * target firm as Current, matching the unique-per-pair constraint.
     */
    public function associateWithFirm(DirectoryAttorney $attorney, int $directoryFirmId, ?string $title, PlatformAdmin $admin): DirectoryAttorneyFirm
    {
        return DB::transaction(function () use ($attorney, $directoryFirmId, $title, $admin): DirectoryAttorneyFirm {
            $attorney->firmRelationships()
                ->where('relationship_state', DirectoryAttorneyFirmRelationshipState::Current)
                ->where('directory_firm_id', '!=', $directoryFirmId)
                ->update(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Former, 'ended_at' => now()]);

            $relationship = DirectoryAttorneyFirm::query()->updateOrCreate(
                ['directory_attorney_id' => $attorney->id, 'directory_firm_id' => $directoryFirmId],
                [
                    'relationship_state' => DirectoryAttorneyFirmRelationshipState::Current,
                    'title' => $title,
                    'is_primary_firm' => true,
                    'started_at' => now(),
                    'ended_at' => null,
                    'source_type' => DataProvenanceSourceType::AdminEntered,
                ],
            );

            $this->writeAudit($attorney, $admin, 'marketplace_attorney_firm_associated', [
                'directory_attorney_id' => $attorney->id,
                'directory_firm_id' => $directoryFirmId,
            ]);

            return $relationship;
        });
    }

    public function endFirmAssociation(DirectoryAttorney $attorney, DirectoryAttorneyFirm $relationship, PlatformAdmin $admin): DirectoryAttorneyFirm
    {
        $relationship->update(['relationship_state' => DirectoryAttorneyFirmRelationshipState::Former, 'ended_at' => now()]);

        $this->writeAudit($attorney, $admin, 'marketplace_attorney_firm_association_ended', [
            'directory_attorney_id' => $attorney->id,
            'directory_firm_id' => $relationship->directory_firm_id,
        ]);

        return $relationship->fresh();
    }

    private function transition(DirectoryAttorney $attorney, PlatformAdmin $admin, DirectoryPublicationState $state, string $eventType): DirectoryAttorney
    {
        $attorney->update(['publication_state' => $state]);
        $this->writeAudit($attorney, $admin, $eventType, ['directory_attorney_id' => $attorney->id, 'slug' => $attorney->slug]);

        return $attorney->fresh();
    }

    /**
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    private function syncPracticeAreasAndLanguages(DirectoryAttorney $attorney, array $practiceAreaIds, array $languageIds): void
    {
        $attorney->practiceAreas()->sync(collect($practiceAreaIds)->mapWithKeys(fn ($id) => [$id => ['source_type' => 'admin_entered']])->all());
        $attorney->languages()->sync(collect($languageIds)->mapWithKeys(fn ($id) => [$id => ['source_type' => 'admin_entered']])->all());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeAudit(DirectoryAttorney $attorney, PlatformAdmin $admin, string $eventType, array $metadata): void
    {
        $tenantFirm = $this->resolveLinkedTenantFirm($attorney);

        if ($tenantFirm !== null) {
            $this->audit->record($tenantFirm, $admin, $eventType, 'marketplace_administration', $metadata);

            return;
        }

        $this->audit->recordPlatformEvent($admin, $eventType, 'marketplace_administration', $metadata);
    }

    private function resolveLinkedTenantFirm(DirectoryAttorney $attorney): ?Firm
    {
        $currentFirm = $attorney->firmRelationships()
            ->where('relationship_state', DirectoryAttorneyFirmRelationshipState::Current)
            ->first()?->firm;

        return $currentFirm?->firm()->first();
    }
}
