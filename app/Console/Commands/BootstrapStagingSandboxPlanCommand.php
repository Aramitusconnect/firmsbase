<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * `plans:bootstrap-staging-sandbox` — FIRMSVAULT STAGING ADMIN
 * STABILIZATION, Phase 7. The one supported non-UI way to create the
 * single synthetic "Staging Sandbox" plan (`code: staging-sandbox`),
 * for use in staging smoke tests when nobody has created a plan
 * through the Admin UI yet (this pass's own defect: "Admin -> Billing
 * & Commercial -> Plans contains no plans").
 *
 * Implements NO independent database-creation logic of its own — every
 * write happens inside PlanService::create(), identically to how the
 * Admin UI's CreatePlanAction calls it. This command only collects the
 * fixed synthetic attributes below and reports the outcome.
 *
 * Idempotent by construction: if a plan with code "staging-sandbox"
 * already exists, this command reports that and exits successfully
 * without creating a second one or mutating the existing row — running
 * it twice (or after someone already created it by hand in the UI) is
 * always safe.
 *
 * Blocked outside local/testing environments unless --confirm-staging
 * is passed (mirrors ProvisionFirmCommand's own convention). Production
 * is refused outright regardless of any flag — there is no escape
 * hatch, matching ProvisionFirmCommand's own "must never run in
 * production" rule.
 *
 * No commercial pricing is invented: price is fixed at 0 (an explicitly
 * synthetic, non-commercial value), matching the mission's own
 * suggested "zero or another explicitly synthetic value allowed by the
 * schema." No modules are pre-attached — which modules a real
 * commercial plan bundles is a product decision this bootstrap command
 * does not make; add modules afterward through AddPlanModuleAction if a
 * given smoke test needs one.
 */
class BootstrapStagingSandboxPlanCommand extends Command
{
    public const PLAN_CODE = 'staging-sandbox';

    /**
     * @var string
     */
    protected $signature = 'plans:bootstrap-staging-sandbox
        {--requested-by= : Email of the platform administrator this bootstrap is recorded against}
        {--confirm-staging : Required to run this command outside a local/testing environment}';

    protected $description = 'Idempotently create the one synthetic "Staging Sandbox" plan via PlanService (staging/local use only).';

    public function handle(PlanService $planService, PlatformAdminAuditEventRecorder $auditRecorder): int
    {
        if ((! app()->environment(['local', 'testing'])) && (! $this->option('confirm-staging'))) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment without --confirm-staging. plans:bootstrap-staging-sandbox is a staging/local tool.',
                app()->environment(),
            ));

            return self::FAILURE;
        }

        // Deliberate, not a gap: production is refused even WITH
        // --confirm-staging, matching ProvisionFirmCommand's own rule.
        if (app()->environment('production')) {
            $this->components->error('plans:bootstrap-staging-sandbox must never run in production.');

            return self::FAILURE;
        }

        $existing = Plan::query()->where('code', self::PLAN_CODE)->first();

        if ($existing !== null) {
            $this->components->info('A plan with code "'.self::PLAN_CODE."\" already exists (id={$existing->id}, status={$existing->status->value}). Nothing to do.");

            return self::SUCCESS;
        }

        $requestedByEmail = $this->option('requested-by') ?: $this->ask('Platform admin email this bootstrap is recorded against');
        $actor = PlatformAdmin::query()->where('email', $requestedByEmail)->first();

        if ($actor === null) {
            $this->components->error("No platform administrator found with email [{$requestedByEmail}].");

            return self::FAILURE;
        }

        $accessPolicy = app(PlatformStaffAccessPolicyService::class);
        $manageDecision = $accessPolicy->canManagePlatformBilling($actor);

        if (! $manageDecision->allowed) {
            $this->components->error("Not permitted: {$manageDecision->reason}");

            return self::FAILURE;
        }

        $mutateDecision = $accessPolicy->canMutate($actor);

        if (! $mutateDecision->allowed) {
            $this->components->error("Not permitted: {$mutateDecision->reason}");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Name', 'Staging Sandbox');
        $this->components->twoColumnDetail('Code', self::PLAN_CODE);
        $this->components->twoColumnDetail('Price', '$0.00 (explicitly synthetic — not real commercial pricing)');
        $this->components->twoColumnDetail('Billing interval', BillingInterval::Monthly->value);
        $this->components->twoColumnDetail('Trial', '14 days, no card required');

        if (! $this->confirm('Create this synthetic staging plan now?', false)) {
            $this->components->warn('Cancelled — nothing was created.');

            return self::SUCCESS;
        }

        try {
            $plan = $planService->create([
                'name' => 'Staging Sandbox',
                'code' => self::PLAN_CODE,
                'status' => PlanStatus::Active,
                'price_cents' => 0,
                'billing_interval' => BillingInterval::Monthly,
                'support_access_level' => 'standard',
                'description' => 'Synthetic, non-commercial plan for staging smoke tests only. Not for real customer use.',
                'trial_days' => 14,
                'trial_requires_card' => false,
                'is_active' => true,
            ], $actor);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            $auditRecorder->recordConsoleEvent('staging_sandbox_plan_bootstrap_refused', 'platform_billing', [
                'reason' => $e->getMessage(),
            ]);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Bootstrap failed.');
            report($e);

            return self::FAILURE;
        }

        $auditRecorder->recordConsoleEvent('staging_sandbox_plan_bootstrapped', 'platform_billing', [
            'plan_id' => $plan->id,
            'code' => $plan->code,
            'requested_by_platform_admin_id' => $actor->id,
        ]);

        $this->components->info("Plan [{$plan->name}] (code: {$plan->code}) created and active.");

        return self::SUCCESS;
    }
}
