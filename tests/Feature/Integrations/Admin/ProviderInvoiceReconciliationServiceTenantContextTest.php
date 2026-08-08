<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\DeploymentMode;
use App\Integrations\Enums\ProviderKey;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\ProviderInvoiceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Second confirmed instance of the Plaid Anomaly Oversight 500's exact
 * root cause: this service's Firm::query()->select('id')->chunkById()
 * loop also passed the partial Firm model straight into
 * runWithFirmContext(), which would have thrown the identical TypeError
 * the moment a real invoice reconciliation ran against staging/
 * production data. Fixed the same way -- pass $firm->id, not $firm.
 * See PlaidAnomalyOversightPageTest for the fuller regression writeup.
 */
final class ProviderInvoiceReconciliationServiceTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_does_not_crash_with_a_non_default_deployment_mode_firm_in_the_chunk(): void
    {
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Saas]);
        Firm::factory()->create(['deployment_mode' => DeploymentMode::Dedicated]);
        Firm::factory()->create(['deployment_mode' => DeploymentMode::PrivateEnterprise]);

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $reconciliation = app(ProviderInvoiceReconciliationService::class)->run(
            providerKey: ProviderKey::Plaid->value,
            performedBy: $admin,
            periodStart: now()->subMonth(),
            periodEnd: now(),
            assertedInvoiceTotalCents: 0,
        );

        $this->assertSame(0, $reconciliation->system_recorded_total_cents);
    }
}
