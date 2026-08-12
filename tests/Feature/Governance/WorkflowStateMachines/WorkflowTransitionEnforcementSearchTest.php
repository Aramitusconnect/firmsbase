<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use Tests\TestCase;

/**
 * WorkflowTransitionEnforcementSearchTest — a live, structural search
 * (not a hand-maintained file list) proving every direct status-enum
 * write for the 14 catalog workflows lives inside app/Services (a
 * dedicated owning service), never inside a UI/controller/Livewire/
 * Blade layer. This codebase has no routes/controllers/Filament/
 * Livewire/Blade views at all (confirmed by every prior Section 25-32
 * firewall test), so the meaningful, durable check is: does a write
 * ever appear anywhere OTHER than app/Services? If a future change
 * ever adds a write site outside app/Services, this test fails loudly
 * with the exact file and enum involved, rather than silently passing
 * because a hardcoded allow-list happened not to mention it.
 */
class WorkflowTransitionEnforcementSearchTest extends TestCase
{
    /**
     * The status enum for each of the 14 catalog workflows.
     */
    private const WORKFLOW_ENUMS = [
        'LicenseStatus',
        'FirmLeadStatus',
        'MatterStatus',
        'DocumentRequestItemStatus',
        'TaskStatus',
        'InvoiceStatus',
        'PaymentPlanStatus',
        'PaymentPlanInstallmentStatus',
        'PaymentStatus',
        'TrustTransferRequestStatus',
        'TrustRefundRequestStatus',
        'AiApprovalRequestStatus',
        'ImportBatchStatus',
        'SignatureRequestStatus',
        'FleetMigrationRunStatus',
    ];

    /**
     * Directories scanned. database/factories, database/seeders,
     * database/migrations, and tests/ are deliberately excluded per
     * approved scope — only production app/ code is searched.
     */
    private const SCANNED_DIRECTORIES = ['app'];

    public function test_no_direct_status_write_for_any_of_the_fourteen_workflow_enums_exists_outside_app_services(): void
    {
        $violations = [];

        foreach ($this->productionPhpFiles() as $path) {
            $relativePath = $this->relativePath($path);

            if (str_starts_with($relativePath, 'app/Services/')) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (self::WORKFLOW_ENUMS as $enum) {
                if ($this->hasDirectStatusWrite($source, $enum)) {
                    $violations[] = "{$relativePath} writes {$enum} directly outside app/Services.";
                }
            }
        }

        $this->assertEmpty($violations, "Suspicious direct status writes found outside owning services:\n".implode("\n", $violations));
    }

    /**
     * (?!class\b) excludes cast declarations like
     * 'status' => MatterStatus::class, which are type declarations,
     * not writes of a specific case value. The key is either "status"
     * or "license_status" — firm_licenses is the one workflow table in
     * this catalog whose real column is named license_status rather
     * than the plain status every other workflow table uses.
     */
    private function hasDirectStatusWrite(string $source, string $enum): bool
    {
        $quotedEnum = preg_quote($enum, '/');

        return (bool) preg_match('/[\'"](?:status|license_status)[\'"]\s*=>\s*(?:\\\\App\\\\Enums\\\\)?'.$quotedEnum.'::(?!class\b)/', $source)
            || (bool) preg_match('/->(?:status|license_status)\s*=\s*(?:\\\\App\\\\Enums\\\\)?'.$quotedEnum.'::(?!class\b)/', $source);
    }

    public function test_no_ui_or_controller_layer_exists_that_could_perform_an_informal_transition(): void
    {
        // app/Http/Controllers exists only as Laravel's empty
        // abstract-base-class scaffolding (Controller.php) — no real
        // controller has ever been added, so the meaningful check is
        // "no additional file exists here," not "the directory itself
        // is absent."
        // ReadinessController.php (ECS readiness foundation) is a reviewed,
        // narrow exception: a pure infra health-check endpoint that never
        // touches a workflow-state-machine model or performs a status
        // write — orthogonal to this test's actual concern.
        $controllerFiles = glob(base_path('app/Http/Controllers/*.php')) ?: [];
        $this->assertSame(['Controller.php', 'ReadinessController.php'], array_map('basename', $controllerFiles), 'No real controller should exist beyond the empty Laravel scaffold and the reviewed ECS readiness probe.');

        // Narrowly updated by Checkpoint 4 (FirmsVault Live Integrations,
        // "Plaid financial evidence add-on") -- resources/views/filament-
        // client-portal/plaid-link.blade.php is a reviewed, narrow
        // exception, the same shape as the ReadinessController exception
        // above: a pure Plaid Link JS embed (initiates the Plaid Link
        // handshake and POSTs the resulting public_token to
        // client-portal.plaid.exchange) that never references a
        // workflow-state-machine model or writes a status/license_status
        // field directly -- orthogonal to this test's actual concern.
        // Additive only, no existing assertion removed or weakened.
        //
        // Narrowly updated AGAIN by the Payment Link / QR Routing phase
        // -- resources/views/layouts/public.blade.php is a reviewed,
        // narrow exception of the same shape: a pure HTML/CSS layout
        // wrapper for the public payment page (no PHP logic beyond
        // {{ $slot }}, no enum reference, no status write of any kind).
        // The page's own real logic (submit(), status transitions) all
        // lives in PublicPaymentPage.php/PaymentRequestCheckoutService.php
        // under app/, both already covered by the first assertion above.
        // Narrowly updated AGAIN by Mission 2 (MyAttorney Marketplace
        // Core), checkpoint 4 -- the four new resources/views/myattorney/*
        // Blade views are a reviewed, narrow exception of the same
        // shape: pure read-only public-profile display (server-rendered
        // from PublicFirmProfile/PublicAttorneyProfile view-model DTOs --
        // see app/Marketplace/ViewModels), no PHP logic beyond
        // conditionals/loops over already-resolved data, no reference to
        // any of the 14 workflow-state-machine enums above, and no
        // status/license_status write of any kind. None of Mission 2's
        // own new marketplace lifecycle enums (DirectoryPublicationState,
        // DirectoryAttorneyFirmRelationshipState, etc.) are in this
        // test's WORKFLOW_ENUMS catalog either -- a distinct, later
        // mission's own domain, out of this test's scope by construction.
        // Narrowly updated AGAIN by Mission 3 (MyAttorney Conversion +
        // AI Intake), checkpoint 2 -- resources/views/layouts/public-intake.blade.php
        // and resources/views/livewire/marketplace/public-intake-page.blade.php
        // are a reviewed, narrow exception of the same shape as
        // layouts/public.blade.php above: pure read-only status
        // display (no PHP logic beyond conditionals over already-
        // resolved MarketplaceIntake fields), no reference to any of
        // the 14 workflow-state-machine enums above, and no status
        // write of any kind -- the page's own real logic (mount(),
        // resume tracking, expiry transitions) all lives in
        // PublicIntakePage.php/MarketplaceIntakeService.php under
        // app/. MarketplaceIntakeStatus is not in this test's
        // WORKFLOW_ENUMS catalog either -- Mission 3's own domain, out
        // of this test's scope by construction, matching Mission 2's
        // own marketplace-lifecycle-enum exclusion reasoning above.
        $bladeFiles = glob(resource_path('views/**/*.blade.php')) ?: [];
        $bladeFiles = array_values(array_filter(
            $bladeFiles,
            fn (string $path) => $path !== resource_path('views/filament-client-portal/plaid-link.blade.php')
                && $path !== resource_path('views/layouts/public.blade.php')
                && $path !== resource_path('views/layouts/public-intake.blade.php')
                && $path !== resource_path('views/livewire/marketplace/public-intake-page.blade.php')
                && ! str_starts_with($path, resource_path('views/myattorney/'))
        ));
        $this->assertEmpty($bladeFiles, 'No Blade views should exist that could write workflow status directly.');
    }

    /**
     * Broader than hasDirectStatusWrite() on purpose: some owning
     * services (e.g. FirmLicenseCommercialService::changeStatus())
     * receive the target enum case as a method ARGUMENT from a caller
     * (LicenseFileValidationService passing LicenseStatus::GracePeriod)
     * rather than as a literal array-assignment at the write site
     * itself. This test's job is confirming every workflow enum has
     * real, non-cast case usage somewhere in app/Services — not
     * re-verifying the narrower write-shape already checked above.
     */
    public function test_every_workflow_enum_has_at_least_one_confirmed_owning_service_write_site(): void
    {
        $foundEnums = [];

        foreach ($this->productionPhpFiles() as $path) {
            $relativePath = $this->relativePath($path);

            if (! str_starts_with($relativePath, 'app/Services/')) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (self::WORKFLOW_ENUMS as $enum) {
                if (in_array($enum, $foundEnums, true)) {
                    continue;
                }

                if (preg_match('/'.preg_quote($enum, '/').'::(?!class\b)\w+/', $source)) {
                    $foundEnums[] = $enum;
                }
            }
        }

        sort($foundEnums);
        $expected = self::WORKFLOW_ENUMS;
        sort($expected);

        $this->assertSame($expected, $foundEnums, 'Every workflow enum should have at least one real owning-service case reference in app/Services.');
    }

    /**
     * @return array<int, string>
     */
    private function productionPhpFiles(): array
    {
        $files = [];

        foreach (self::SCANNED_DIRECTORIES as $dir) {
            $absoluteDir = base_path($dir);

            if (! is_dir($absoluteDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(str_replace(base_path(), '', $absolutePath), '/');
    }
}
