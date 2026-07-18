<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\CommissionEvent;
use App\Models\CommissionPlan;
use App\Models\ConversionEvent;
use App\Models\CustomerSuccessHealthScore;
use App\Models\DemoEvent;
use App\Models\Firm;
use App\Models\HighRiskChangeRequest;
use App\Models\ImplementationProject;
use App\Models\ImplementationTask;
use App\Models\Opportunity;
use App\Models\PlatformLead;
use App\Models\PlatformSalesTask;
use App\Models\ProductAnalyticsEvent;
use App\Models\ReleaseNote;
use App\Models\SalesRepAssignment;
use App\Models\SupportAccessRequest;
use App\Models\SupportAccessSession;
use App\Models\TrialRequest;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the approved Phase 7 uuid decision: 17 workflow models carry
 * a public uuid, while ProductAnalyticsEvent and PlatformRole
 * (audit/grant-style, never addressed individually) do not.
 */
class Phase7PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_has_a_public_uuid(string $modelClass): void
    {
        // CustomerSuccessHealthScore is the only tenant-owned,
        // FORCE-RLS-enforced model in this data provider (Section
        // 39A-5, Checkpoint 1) — unlike Client/EmployeeRate/etc.,
        // CustomerSuccessHealthScoreFactory deliberately does not
        // auto-establish tenant context (fail-closed by design, see
        // CustomerSuccessHealthScoresForceRlsActivationTest), so a bare
        // factory()->create() must be run under explicit firm context
        // here, same as any other caller would need to.
        if ($modelClass === CustomerSuccessHealthScore::class) {
            $firm = Firm::factory()->create();
            $instance = (new TenantContextService)->runWithFirmContext(
                $firm,
                fn () => $modelClass::factory()->forFirm($firm)->create(),
            );
        } else {
            $instance = $modelClass::factory()->create();
        }

        $this->assertNotNull($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [PlatformLead::class],
            [Opportunity::class],
            [DemoEvent::class],
            [TrialRequest::class],
            [SalesRepAssignment::class],
            [PlatformSalesTask::class],
            [ConversionEvent::class],
            [CommissionPlan::class],
            [CommissionEvent::class],
            [ImplementationProject::class],
            [ImplementationTask::class],
            [CustomerSuccessHealthScore::class],
            [SupportAccessRequest::class],
            [SupportAccessSession::class],
            [Announcement::class],
            [ReleaseNote::class],
            [HighRiskChangeRequest::class],
        ];
    }

    public function test_product_analytics_event_has_no_uuid_column(): void
    {
        $columns = Schema::getColumnListing('product_analytics_events');

        $this->assertNotContains('uuid', $columns);
    }

    public function test_platform_role_has_no_uuid_column(): void
    {
        $columns = Schema::getColumnListing('platform_roles');

        $this->assertNotContains('uuid', $columns);
    }
}
