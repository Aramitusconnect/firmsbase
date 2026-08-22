<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Admin;

use App\Enums\PlatformRoleCode;
use App\Integrations\Models\FirmIntegration;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmIntegrationBoundedAccessService;
use App\Services\PlatformRoleService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * PlatformAdminTenantContextLifecycleTest — Checkpoint 11 (frozen-design-
 * post-security-review.md §2, §6, §12). Mirrors the rigor of Checkpoint
 * 10's PersistentMiddlewareTenantContextLifetimeTest: proves tenant
 * context established by PlatformFirmIntegrationBoundedAccessService
 * (via TenantContextService::runWithFirmContext()) is established ONLY
 * for the duration of the bounded operation, and is reliably cleared
 * afterward — on success, on a pre-context denial (the coarse/per-firm
 * gate rejects BEFORE runWithFirmContext() is ever entered), and on an
 * exception raised from inside the wrapped callback.
 */
final class PlatformAdminTenantContextLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_tenant_context_is_active_before_any_bounded_access_call(): void
    {
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_is_cleared_after_a_successful_per_firm_read(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $sawContextDuring = null;

        $bounded->readWithinFirmAccess($admin, $firm, function () use (&$sawContextDuring) {
            $sawContextDuring = app(TenantContextService::class)->hasFirmContext();

            return null;
        });

        $this->assertTrue($sawContextDuring, 'Expected firm context to be active DURING the wrapped callback.');
        $this->assertFalse(app(TenantContextService::class)->hasFirmContext(), 'Expected firm context to be cleared AFTER the call returns.');
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_is_never_established_at_all_when_the_coarse_gate_denies_before_entering_the_callback(): void
    {
        $firm = Firm::factory()->activated()->create();
        // No role grant at all -> assertCanAccessOversight() throws
        // BEFORE readWithinFirmAccess() ever calls runWithFirmContext().
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        try {
            $bounded->readWithinFirmAccess($admin, $firm, function () {
                $this->fail('The wrapped callback must never run when the coarse gate denies first.');
            });
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_is_never_established_when_the_per_firm_session_gate_denies_a_support_agent(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SupportAgent);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        try {
            $bounded->readWithinFirmAccess($admin, $firm, function () {
                $this->fail('The wrapped callback must never run when the per-firm session gate denies first.');
            });
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_is_cleared_even_when_the_wrapped_callback_itself_throws(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        try {
            $bounded->readWithinFirmAccess($admin, $firm, function () {
                throw new \DomainException('Deliberate failure from inside the wrapped callback.');
            });
            $this->fail('Expected a DomainException to propagate.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertFalse(
            app(TenantContextService::class)->hasFirmContext(),
            'Firm context must be cleared even when the wrapped callback throws — runWithFirmContext() must clean up via finally.'
        );
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_is_cleared_after_a_mutating_action_call(): void
    {
        Queue::fake();

        $firm = Firm::factory()->activated()->create();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->create());
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $bounded->nudgeQueue($admin, $firm);

        $this->assertFalse(app(TenantContextService::class)->hasFirmContext());
        $this->assertNoDatabaseTenantContext();
    }

    public function test_context_established_during_the_operation_matches_the_exact_target_firm(): void
    {
        $firm = Firm::factory()->activated()->create();
        $admin = $this->adminWithRole(PlatformRoleCode::SuperAdmin);
        $bounded = app(PlatformFirmIntegrationBoundedAccessService::class);

        $observedFirmId = null;

        $bounded->readWithinFirmAccess($admin, $firm, function () use (&$observedFirmId) {
            $observedFirmId = DB::selectOne(
                "select current_setting('app.current_firm_id', true) as value"
            )->value;
        });

        $this->assertSame((string) $firm->id, $observedFirmId);
        $this->assertNoDatabaseTenantContext();
    }

    private function adminWithRole(PlatformRoleCode $role): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, $role);

        return $admin;
    }
}
