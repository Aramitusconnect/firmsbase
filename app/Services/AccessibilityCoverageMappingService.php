<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * AccessibilityCoverageMappingService — declares the 5 required
 * accessibility surfaces from the master plan and maps each to its
 * owning backend readiness-checklist service. No UI exists anywhere in
 * this repo (no Blade views beyond the default Laravel welcome page,
 * no Filament/Livewire, no axe/pa11y/Dusk tooling) — every surface is
 * therefore PreparedNotEnforced: a concrete, testable checklist exists,
 * but there is no renderable UI to run real browser accessibility
 * testing against yet. This service does not invent one.
 */
class AccessibilityCoverageMappingService
{
    private const REQUIRED_SURFACES = [
        'client_portal',
        'payment_flows',
        'payment_plan_flows',
        'legal_form_workflows',
        'e_signature_screens',
    ];

    /**
     * @return array<int, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            new GovernanceMappingResult(
                item_key: 'client_portal',
                item_label: 'Client portal accessibility',
                owning_class: \App\Services\ClientPortalAccessibilityReadinessService::class,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'No client portal UI exists yet. ClientPortalAccessibilityReadinessService declares the 7-item WCAG checklist (keyboard navigation, visible focus states, contrast, labels, error messages, mobile-safe layouts, status indicators) a future portal UI must satisfy.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_flows',
                item_label: 'Payment flow accessibility',
                owning_class: \App\Services\BillingAccessibilityReadinessService::class,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'No payment UI exists yet. BillingAccessibilityReadinessService declares the required WCAG checklist for payment/billing screens.',
            ),
            new GovernanceMappingResult(
                item_key: 'payment_plan_flows',
                item_label: 'Payment plan flow accessibility',
                owning_class: \App\Services\BillingAccessibilityReadinessService::class,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'Payment plan screens share the same billing surface and checklist as payment_flows — BillingAccessibilityReadinessService covers both, no UI exists yet for either.',
            ),
            new GovernanceMappingResult(
                item_key: 'legal_form_workflows',
                item_label: 'Legal form/document workflow accessibility',
                owning_class: \App\Services\FormAccessibilityReadinessService::class,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'No form/document UI exists yet. FormAccessibilityReadinessService declares the required WCAG checklist for the legal PDF/form workflow.',
            ),
            new GovernanceMappingResult(
                item_key: 'e_signature_screens',
                item_label: 'E-signature screen accessibility',
                owning_class: \App\Services\SignatureAccessibilityReadinessService::class,
                status: GovernanceMappingStatus::PreparedNotEnforced,
                notes: 'No signing UI exists yet. SignatureAccessibilityReadinessService declares the required WCAG + mobile-safe signing checklist.',
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
     * True only if a renderable UI surface (Blade view beyond the
     * default Laravel scaffold, Filament resource, or Livewire
     * component) exists for at least one of the required surfaces.
     * This repo has none, so this always returns false today.
     */
    public function hasRenderableUiSurface(): bool
    {
        if (is_dir(base_path('app/Filament')) || is_dir(base_path('app/Livewire'))) {
            return true;
        }

        $viewsDir = resource_path('views');

        if (! is_dir($viewsDir)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()
                && str_ends_with($file->getFilename(), '.blade.php')
                && $file->getFilename() !== 'welcome.blade.php'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string> required surface keys with no
     *   renderable UI to test accessibility against.
     */
    public function missingSurfaces(): array
    {
        if ($this->hasRenderableUiSurface()) {
            return [];
        }

        return self::REQUIRED_SURFACES;
    }
}
