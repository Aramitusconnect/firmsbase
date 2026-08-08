<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\PlatformRoleCode;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProvisionFirmCommandSeatsTest — Firm Feature Manifest §12's flat
 * per-firm seat model, `firms:provision` console entry-point proof.
 * `ProvisionFirmCommand` collects `--purchased-seats` and threads it
 * through to `FirmProvisioningInput` identically to the wizard
 * (`ProvisionFirmAction`) — this test proves that wiring end to end via
 * the real console command.
 */
final class ProvisionFirmCommandSeatsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): PlatformAdmin
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::SuperAdmin);

        return $admin;
    }

    public function test_the_command_sets_purchased_seats_on_the_new_license_when_a_plan_is_given(): void
    {
        $admin = $this->actor();
        $plan = Plan::factory()->create();

        $this->artisan('firms:provision', [
            '--confirm-staging' => true,
            '--requested-by' => $admin->email,
            '--firm-name' => 'Console Seats Firm',
            '--owner-name' => 'Console Owner',
            '--owner-email' => 'console-seats-owner-'.uniqid().'@example.test',
            '--customer-type' => CustomerType::LawFirm->value,
            '--deployment-mode' => DeploymentMode::Saas->value,
            '--plan-id' => $plan->id,
            '--purchased-seats' => 8,
        ])
            ->expectsConfirmation('Provision this firm now?', 'yes')
            ->assertExitCode(0);

        $firm = Firm::query()->where('name', 'Console Seats Firm')->firstOrFail();
        $license = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());

        $this->assertNotNull($license);
        $this->assertSame(8, $license->purchased_seats);
    }

    public function test_the_command_prompts_for_seats_when_a_plan_is_given_and_the_option_is_omitted(): void
    {
        $admin = $this->actor();
        $plan = Plan::factory()->create();

        $this->artisan('firms:provision', [
            '--confirm-staging' => true,
            '--requested-by' => $admin->email,
            '--firm-name' => 'Console Prompt Firm',
            '--owner-name' => 'Console Owner',
            '--owner-email' => 'console-prompt-owner-'.uniqid().'@example.test',
            '--customer-type' => CustomerType::LawFirm->value,
            '--deployment-mode' => DeploymentMode::Saas->value,
            '--plan-id' => $plan->id,
        ])
            ->expectsQuestion('Purchased seats (every FirmUser, any role, consumes one seat)', '6')
            ->expectsConfirmation('Provision this firm now?', 'yes')
            ->assertExitCode(0);

        $firm = Firm::query()->where('name', 'Console Prompt Firm')->firstOrFail();
        $license = $this->runWithFirmContext($firm, fn () => FirmLicense::query()->where('firm_id', $firm->id)->first());

        $this->assertNotNull($license);
        $this->assertSame(6, $license->purchased_seats);
    }
}
