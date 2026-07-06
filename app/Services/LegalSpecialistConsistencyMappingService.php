<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * LegalSpecialistConsistencyMappingService — catalogs whether
 * LegalSpecialistBoundaryPolicyService is actually applied across the
 * 6 output surfaces the master plan names. Does NOT modify
 * LegalSpecialistBoundaryPolicyService and does NOT create any new
 * terminology-detection logic — this is a read-only declaration of
 * where the existing service is (and is not) wired in today.
 *
 * Confirmed by direct repository search: LegalSpecialistBoundaryPolicyService
 * is referenced only within its own file — no invoice generation,
 * export generation, notification dispatch, or email sending code
 * anywhere in the repository calls it. No dashboard or client-portal
 * UI exists at all (no app/Filament, no app/Livewire, no non-default
 * Blade views).
 */
class LegalSpecialistConsistencyMappingService
{
    private const SURFACES = [
        'dashboards',
        'portal',
        'emails',
        'invoices',
        'exports',
        'notifications',
    ];

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'dashboards',
                item_label: 'Internal/firm dashboards',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'No dashboard UI exists anywhere in the repository (no app/Filament, no app/Livewire, no non-default Blade views) — there is no rendering surface to apply the boundary to yet.',
            ),
            new GovernanceMappingResult(
                item_key: 'portal',
                item_label: 'Client portal',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotApplicableYet,
                notes: 'No client portal UI exists yet (client.portal_status/portal_invitation_* fields prepare the schema only) — there is no rendering surface to apply the boundary to yet.',
            ),
            new GovernanceMappingResult(
                item_key: 'emails',
                item_label: 'Outbound emails',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No email-sending or email-composition code anywhere in the repository calls LegalSpecialistBoundaryPolicyService::containsForbiddenTerminology()/assertBoundarySafeOutput(). The detection logic itself is real and tested; it is simply never invoked for this surface.',
            ),
            new GovernanceMappingResult(
                item_key: 'invoices',
                item_label: 'Invoices',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'InvoiceDraftingService does not call LegalSpecialistBoundaryPolicyService anywhere — invoice line descriptions/labels are never checked for law-firm-only terminology before a legal_specialist customer sees them.',
            ),
            new GovernanceMappingResult(
                item_key: 'exports',
                item_label: 'Exports (offboarding, accounting, etc.)',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'ExportJobService and OffboardingExportService do not call LegalSpecialistBoundaryPolicyService anywhere.',
            ),
            new GovernanceMappingResult(
                item_key: 'notifications',
                item_label: 'Notifications',
                owning_class: \App\Services\LegalSpecialistBoundaryPolicyService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No notification dispatch/template code (DispatchNotificationJob and related services) calls LegalSpecialistBoundaryPolicyService anywhere.',
            ),
        ];
    }

    public function bySurface(string $surface): ?GovernanceMappingResult
    {
        foreach ($this->all() as $item) {
            if ($item->item_key === $surface) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<int, string> surfaces where the boundary should
     *   eventually apply but is not currently wired in (excludes
     *   surfaces that are not-applicable because no rendering surface
     *   exists at all).
     */
    public function missingSurfaces(): array
    {
        return array_values(array_map(
            fn (GovernanceMappingResult $item) => $item->item_key,
            array_filter(
                $this->all(),
                fn (GovernanceMappingResult $item) => $item->status === GovernanceMappingStatus::NotFound,
            ),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function implementedSurfaces(): array
    {
        return array_values(array_map(
            fn (GovernanceMappingResult $item) => $item->item_key,
            array_filter(
                $this->all(),
                fn (GovernanceMappingResult $item) => $item->status === GovernanceMappingStatus::Implemented,
            ),
        ));
    }
}
