<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * DirectoryFirmAdministrationService — MyAttorney SuperAdmin console
 * professionalization mission (MYAT2). The one place a SuperAdmin's
 * manual "Add Firm"/"Edit Firm" action actually writes a DirectoryFirm
 * + its primary FirmOffice + practice-area/language pivots, mirroring
 * app/Filament/Firm/Pages/MyAttorneyProfilePage.php's own established
 * sync()/profile-version/audit shape (the Firm panel's own existing
 * self-service editor for this exact model) rather than inventing a
 * new one.
 *
 * Deliberately separate from MarketplaceModerationService (publication
 * state / membership only), MarketplaceClaimService (claimed status),
 * and MarketplaceVerificationService (verification status) — a manual
 * create/edit through this service NEVER touches is_claimed,
 * is_marketplace_member, or any DirectoryVerification row. Those three
 * concepts must remain independently governed through their own
 * existing workflows (this mission's own explicit instruction) — an
 * admin manually adding a listing does not thereby claim, verify, or
 * grant membership on it.
 *
 * Every manually-created or manually-edited record is stamped with
 * source_type = AdminEntered (never a user-selectable form field — see
 * this mission's own "manual entry provenance" requirement: manual
 * data must never be disguised as Google/imported/firm-supplied). Edits
 * are additionally recorded via the existing
 * MarketplaceProfileVersionService so there is a genuine structured
 * before/after diff, not just a security_events metadata blob.
 */
class DirectoryFirmAdministrationService
{
    /**
     * The only DirectoryFirm columns a manual edit may ever touch.
     * Mirrors MarketplaceCorrectionService::PUBLIC_PROFILE_FIELDS'
     * established allowlist convention — address lives on FirmOffice
     * and is handled separately by syncOffice().
     */
    private const EDITABLE_FIRM_FIELDS = [
        'display_name',
        'legal_name',
        'phone',
        'website',
        'public_email',
        'founding_year',
        'description',
        'consultation_modes',
        'accepting_inquiries',
        'publication_state',
    ];

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $audit = new PlatformAdminAuditEventRecorder,
        private readonly MarketplaceProfileVersionService $versions = new MarketplaceProfileVersionService,
        private readonly MarketplaceImportDuplicateDetectionService $duplicates = new MarketplaceImportDuplicateDetectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    public function create(array $data, array $practiceAreaIds, array $languageIds, PlatformAdmin $admin): DirectoryFirm
    {
        return DB::transaction(function () use ($data, $practiceAreaIds, $languageIds, $admin): DirectoryFirm {
            $displayName = (string) $data['display_name'];
            $slugSource = filled($data['slug'] ?? null) ? (string) $data['slug'] : $displayName;
            $slug = DirectoryFirm::generateUniqueSlug($slugSource);

            /**
             * MyAttorney final hardening mission, finding 7: a manual
             * Add Firm submission is checked against the same
             * deterministic duplicate signals import uses, and — when a
             * candidate is found — requires an explicit, non-blank
             * override reason. Enforced HERE (not only via the Filament
             * form's own required() closure) so this guarantee holds for
             * every caller of this service, not just the one Livewire
             * page.
             */
            $duplicate = $this->duplicates->findDuplicateCandidate([
                'name_normalized' => Str::lower($displayName),
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
            ]);

            $overrideReason = trim((string) ($data['duplicate_override_reason'] ?? ''));

            if ($duplicate !== null && $overrideReason === '') {
                throw ValidationException::withMessages([
                    'duplicate_override_reason' => 'A reason is required to create this firm despite a possible duplicate match.',
                ]);
            }

            $firm = DirectoryFirm::create([
                'firm_id' => null,
                'slug' => $slug,
                'legal_name' => filled($data['legal_name'] ?? null) ? $data['legal_name'] : $displayName,
                'display_name' => $displayName,
                'name_normalized' => Str::lower($displayName),
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
                'public_email' => $data['public_email'] ?? null,
                'founding_year' => $data['founding_year'] ?? null,
                'description' => $data['description'] ?? null,
                'consultation_modes' => $data['consultation_modes'] ?? [],
                'accepting_inquiries' => (bool) ($data['accepting_inquiries'] ?? false),
                'is_claimed' => false,
                'claimed_at' => null,
                'is_marketplace_member' => false,
                'membership_activated_at' => null,
                'publication_state' => $data['publication_state'] ?? DirectoryPublicationState::Draft,
                'source_type' => DataProvenanceSourceType::AdminEntered,
                'source_reference' => 'platform_admin:'.$admin->id,
                'imported_at' => null,
                'last_verified_at' => null,
                'last_confirmed_by_firm_at' => null,
                'completeness_score' => 0,
            ]);

            $this->syncOffice($firm, $data);
            $this->syncPracticeAreasAndLanguages($firm, $practiceAreaIds, $languageIds);

            $metadata = [
                'directory_firm_id' => $firm->id,
                'slug' => $firm->slug,
                'publication_state' => $firm->publication_state->value,
            ];

            if ($duplicate !== null) {
                $metadata['duplicate_override'] = [
                    'matched_directory_firm_id' => $duplicate['firm']->id,
                    'matching_reasons' => $duplicate['reasons'],
                    'reason' => $overrideReason,
                ];
            }

            $this->writeAudit($firm, $admin, 'marketplace_firm_created', $metadata);

            return $firm->fresh(['offices', 'practiceAreas', 'languages']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    public function update(DirectoryFirm $firm, array $data, array $practiceAreaIds, array $languageIds, PlatformAdmin $admin): DirectoryFirm
    {
        return DB::transaction(function () use ($firm, $data, $practiceAreaIds, $languageIds, $admin): DirectoryFirm {
            $changes = [];
            $attributes = [];

            foreach (self::EDITABLE_FIRM_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $data[$field];
                $current = $firm->{$field};
                $currentComparable = $current instanceof BackedEnum ? $current->value : $current;
                $valueComparable = $value instanceof BackedEnum ? $value->value : $value;

                if ($currentComparable !== $valueComparable) {
                    $changes[$field] = $valueComparable;
                }

                $attributes[$field] = $value;
            }

            if (array_key_exists('slug', $data) && filled($data['slug']) && $data['slug'] !== $firm->slug) {
                $newSlug = DirectoryFirm::generateUniqueSlug((string) $data['slug'], $firm->id);
                $attributes['slug'] = $newSlug;
                $changes['slug'] = $newSlug;
            }

            if ($attributes !== []) {
                $firm->update($attributes);
            }

            $this->syncOffice($firm, $data);
            $this->syncPracticeAreasAndLanguages($firm, $practiceAreaIds, $languageIds);

            if ($changes !== []) {
                $this->versions->record($firm->fresh(), $changes, 'platform_admin', $admin->id, DataProvenanceSourceType::AdminEntered);
            }

            $this->writeAudit($firm, $admin, 'marketplace_firm_updated', [
                'directory_firm_id' => $firm->id,
                'changed_fields' => array_keys($changes),
            ]);

            return $firm->fresh(['offices', 'practiceAreas', 'languages']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncOffice(DirectoryFirm $firm, array $data): void
    {
        if (! array_key_exists('address_line1', $data) && ! array_key_exists('city', $data) && ! array_key_exists('state', $data)) {
            return;
        }

        if (blank($data['address_line1'] ?? null) && blank($data['city'] ?? null) && blank($data['state'] ?? null)) {
            return;
        }

        $office = $firm->offices()->where('is_primary', true)->first();

        $attributes = [
            'directory_firm_id' => $firm->id,
            'label' => $office->label ?? 'Primary Office',
            'address_line1' => $data['address_line1'] ?? null,
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'] ?? null,
            'city_normalized' => filled($data['city'] ?? null) ? Str::lower((string) $data['city']) : null,
            'state' => $data['state'] ?? null,
            'country' => $data['country'] ?? 'US',
            'postal_code' => $data['postal_code'] ?? null,
            'phone' => $data['office_phone'] ?? ($data['phone'] ?? null),
            'is_primary' => true,
            'published' => true,
            'source_type' => DataProvenanceSourceType::AdminEntered,
        ];

        if ($office !== null) {
            $office->update($attributes);
        } else {
            FirmOffice::create($attributes);
        }
    }

    /**
     * @param  array<int>  $practiceAreaIds
     * @param  array<int>  $languageIds
     */
    private function syncPracticeAreasAndLanguages(DirectoryFirm $firm, array $practiceAreaIds, array $languageIds): void
    {
        $firm->practiceAreas()->sync(collect($practiceAreaIds)->mapWithKeys(fn ($id) => [$id => ['source_type' => 'admin_entered']])->all());
        $firm->languages()->sync(collect($languageIds)->mapWithKeys(fn ($id) => [$id => ['source_type' => 'admin_entered']])->all());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function writeAudit(DirectoryFirm $firm, PlatformAdmin $admin, string $eventType, array $metadata): void
    {
        $tenantFirm = $firm->firm_id !== null ? $firm->firm()->first() : null;

        if ($tenantFirm !== null) {
            $this->audit->record($tenantFirm, $admin, $eventType, 'marketplace_administration', $metadata);

            return;
        }

        $this->audit->recordPlatformEvent($admin, $eventType, 'marketplace_administration', $metadata);
    }
}
