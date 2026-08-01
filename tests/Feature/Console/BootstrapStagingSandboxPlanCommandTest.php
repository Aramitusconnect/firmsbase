<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\BootstrapStagingSandboxPlanCommand;
use App\Enums\PlanStatus;
use App\Enums\PlatformRoleCode;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * BootstrapStagingSandboxPlanCommandTest — FIRMSVAULT STAGING ADMIN
 * STABILIZATION, Phase 7. Proves the console-only bootstrap path for
 * the synthetic "Staging Sandbox" plan: idempotent, authorization
 * enforced identically to the Admin UI, refuses production, never
 * invents commercial pricing.
 */
final class BootstrapStagingSandboxPlanCommandTest extends TestCase
{
    use RefreshDatabase;

    private function billingAdmin(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    public function test_creates_the_synthetic_plan_with_zero_price_and_no_modules(): void
    {
        $admin = $this->billingAdmin();

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->expectsConfirmation('Create this synthetic staging plan now?', 'yes')
            ->assertSuccessful();

        $plan = Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->first();

        $this->assertNotNull($plan);
        $this->assertSame('Staging Sandbox', $plan->name);
        $this->assertSame(0, $plan->price_cents);
        $this->assertSame(PlanStatus::Active, $plan->status);
        $this->assertTrue($plan->is_active);
        $this->assertSame(0, $plan->modules()->count());
    }

    public function test_is_idempotent_and_does_not_create_a_second_plan(): void
    {
        $admin = $this->billingAdmin();

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->expectsConfirmation('Create this synthetic staging plan now?', 'yes')
            ->assertSuccessful();

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->assertSuccessful();

        $this->assertSame(1, Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->count());
    }

    public function test_refuses_for_a_read_only_auditor(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->assertFailed();

        $this->assertSame(0, Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->count());
    }

    public function test_refuses_for_an_admin_without_billing_management_role(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->assertFailed();

        $this->assertSame(0, Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->count());
    }

    public function test_refuses_in_production_even_with_confirm_staging(): void
    {
        $admin = $this->billingAdmin();

        $this->swapEnvironment('production', function () use ($admin) {
            $this->artisan('plans:bootstrap-staging-sandbox', [
                '--requested-by' => $admin->email,
                '--confirm-staging' => true,
            ])->assertFailed();
        });

        $this->assertSame(0, Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->count());
    }

    private function swapEnvironment(string $environment, callable $callback): void
    {
        $original = App::make('env');

        App::instance('env', $environment);

        try {
            $callback();
        } finally {
            App::instance('env', $original);
        }
    }

    public function test_cancelling_the_confirmation_creates_nothing(): void
    {
        $admin = $this->billingAdmin();

        $this->artisan('plans:bootstrap-staging-sandbox', ['--requested-by' => $admin->email])
            ->expectsConfirmation('Create this synthetic staging plan now?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, Plan::query()->where('code', BootstrapStagingSandboxPlanCommand::PLAN_CODE)->count());
    }
}
