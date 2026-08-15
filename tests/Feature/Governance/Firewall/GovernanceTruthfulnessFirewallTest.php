<?php

declare(strict_types=1);

namespace Tests\Feature\Governance\Firewall;

use App\Enums\DeletionRequestStatus;
use App\Enums\LegalHoldStatus;
use Tests\TestCase;

/**
 * GovernanceTruthfulnessFirewallTest — source-level guards over the
 * governance claims that are easiest to erode by accident and most
 * damaging when wrong.
 *
 * These are deliberately structural rather than behavioural. Each one
 * encodes a fact about what the backend can actually do on this HEAD,
 * so that a future change which adds the UI affordance without adding
 * the capability fails here rather than shipping a false compliance
 * claim. If a capability is genuinely built later, the corresponding
 * guard should be updated in the same change that builds it — that
 * coupling is the point.
 */
final class GovernanceTruthfulnessFirewallTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path, "expected governance file {$relativePath} to exist");

        return (string) file_get_contents($path);
    }

    // -----------------------------------------------------------------
    // No fake export capability (§129)
    // -----------------------------------------------------------------

    /**
     * export_jobs stores no file path, size, checksum, encryption state,
     * expiry, or download record, and ExportJobService generates no
     * archive — markCompleted() only sets a status and a timestamp. A
     * download affordance would therefore be a promise the backend
     * cannot keep.
     */
    public function test_the_export_resource_offers_no_download_affordance(): void
    {
        $source = strtolower($this->source('app/Filament/Resources/ExportJobResource.php'));

        foreach (['download', 'temporaryurl', 'storage::disk', '->disk(', 'signedurl'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "ExportJobResource must not reference '{$forbidden}' — export jobs produce no downloadable archive"
            );
        }
    }

    public function test_the_export_service_still_produces_no_file(): void
    {
        $source = $this->source('app/Services/ExportJobService.php');

        foreach (['Storage::', 'put(', 'writeStream', 'zip', 'Zip'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'ExportJobService must remain manifest/status only. If real export generation is added, '
                .'update this guard and the Governance Overview export metrics in the same change.'
            );
        }
    }

    // -----------------------------------------------------------------
    // No physical deletion shortcut (§126)
    // -----------------------------------------------------------------

    /**
     * DeletionRequestStatus has no Executed case and
     * DeletionGovernanceService stops at ReadyForExecution. No Admin
     * surface may reach past that with a raw delete.
     */
    public function test_deletion_status_has_no_executed_state(): void
    {
        $values = array_map(
            static fn (DeletionRequestStatus $case): string => $case->value,
            DeletionRequestStatus::cases(),
        );

        foreach (['executed', 'deleted', 'destroyed', 'disposed'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $values,
                "DeletionRequestStatus gained a '{$forbidden}' case. If governed physical disposition is now "
                .'real, the Governance Overview must stop reporting execution as Not supported.'
            );
        }
    }

    public function test_no_governance_admin_surface_performs_a_raw_delete(): void
    {
        $paths = [
            'app/Filament/Resources/DeletionRequestResource.php',
            'app/Filament/Resources/LegalHoldResource.php',
            'app/Filament/Resources/OffboardingRequestResource.php',
            'app/Filament/Resources/ExportJobResource.php',
            'app/Filament/Resources/AuditLogResource.php',
            'app/Filament/Resources/MigrationProjectResource.php',
            'app/Filament/Pages/PlatformRetentionGovernancePage.php',
            'app/Filament/Pages/PlatformGovernanceOverviewPage.php',
        ];

        foreach ($paths as $path) {
            $source = $this->source($path);

            foreach (['forceDelete(', 'DB::delete(', 'DeleteAction', 'DeleteBulkAction'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$path} must not perform or offer a destructive delete — governance evidence is not "
                    .'CRUD, and physical disposition has no canonical service on this HEAD'
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Audit evidence stays read-only (§38)
    // -----------------------------------------------------------------

    public function test_the_audit_log_resource_registers_no_mutation_action(): void
    {
        $source = $this->source('app/Filament/Resources/AuditLogResource.php');

        foreach (['CreateAction', 'EditAction', 'DeleteAction', 'ReplicateAction'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'Audit evidence is immutable — AuditLogResource must never register a mutation action'
            );
        }
    }

    // -----------------------------------------------------------------
    // No fake legal hold expiry (§74, §C)
    // -----------------------------------------------------------------

    /**
     * A hold stays active until a governed release is performed. If the
     * status enum ever gains an expiry-like case, the overview's
     * "Not supported" expiry metrics become a lie.
     */
    public function test_legal_hold_status_models_no_automatic_expiry(): void
    {
        $values = array_map(
            static fn (LegalHoldStatus $case): string => $case->value,
            LegalHoldStatus::cases(),
        );

        sort($values);

        $this->assertSame(
            ['active', 'released'],
            $values,
            'LegalHoldStatus changed. Holds must not gain an expired/lapsed state without the Governance '
            .'Overview and Legal Holds UI being updated to stop reporting expiry as Not supported.'
        );
    }

    // -----------------------------------------------------------------
    // The hold gate keeps its own tenant context (§11, §12)
    // -----------------------------------------------------------------

    /**
     * The single most important guard in this file. legal_holds carries
     * FORCE RLS, so an unwrapped read returns zero rows rather than
     * raising — checkHold() must establish its own context, or a missing
     * ambient context silently becomes "no hold".
     */
    public function test_the_legal_hold_gate_establishes_its_own_tenant_context(): void
    {
        $source = $this->source('app/Services/LegalHoldService.php');

        $checkHoldBody = substr($source, (int) strpos($source, 'public function checkHold'));

        $this->assertStringContainsString(
            'runWithFirmContext',
            substr($checkHoldBody, 0, 400),
            'LegalHoldService::checkHold() must wrap its reads in tenant context. Without it, an absent or '
            .'wrong ambient context turns an active hold into a silent "not blocked" — the fail-open '
            .'regression this mission closed.'
        );
    }
}
