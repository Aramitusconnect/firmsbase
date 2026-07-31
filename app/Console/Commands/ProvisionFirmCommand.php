<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmProvisioningStatus;
use App\Exceptions\ExistingUserReviewRequiredException;
use App\Exceptions\FirmProvisioningRequestChangedException;
use App\Exceptions\PlatformAdminIdentityCollisionException;
use App\Models\PlatformAdmin;
use App\Services\FirmProvisioningService;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use App\ValueObjects\FirmProvisioningInput;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * `firms:provision` — the optional staging-safe console entry point to
 * FirmProvisioningService. Implements NO independent database-creation
 * logic of its own; every write happens inside
 * FirmProvisioningService::provision(), identically to
 * ProvisionFirmAction. This command only collects input interactively
 * and reports the outcome.
 *
 * Blocked outside local/testing environments unless --confirm-staging is
 * passed, mirroring PlatformAdminEmergencyMfaResetCommand's own
 * environment-allowlist convention — this is a safety confirmation, not
 * an authorization mechanism (actual authorization is server/console
 * access itself). The mission explicitly scopes this to
 * staging/local use; production is refused outright regardless of any
 * flag (unlike the MFA command's --confirm-production, there is no
 * escape hatch here at all — this command must never run in production).
 */
class ProvisionFirmCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'firms:provision
        {--requested-by= : Email of the platform administrator this provisioning is recorded against}
        {--firm-name= : Firm name}
        {--owner-name= : Owner full name}
        {--owner-email= : Owner email}
        {--customer-type= : One of: '.self::CUSTOMER_TYPE_LIST.'}
        {--deployment-mode= : One of: '.self::DEPLOYMENT_MODE_LIST.'}
        {--organization-id= : Attach to this existing organization id (omit for a standalone firm)}
        {--plan-id= : Assign this plan id (omit to provision without a plan)}
        {--reuse-existing-user : Explicitly authorize reusing an existing user account with the given owner email}
        {--confirm-staging : Required to run this command outside a local/testing environment}';

    private const CUSTOMER_TYPE_LIST = 'law_firm, legal_specialist';

    private const DEPLOYMENT_MODE_LIST = 'saas, dedicated, private_enterprise';

    protected $description = 'Provision a complete, login-ready firm tenant via FirmProvisioningService (staging/local use).';

    public function handle(FirmProvisioningService $provisioningService, PlatformAdminAuditEventRecorder $auditRecorder): int
    {
        if ((! app()->environment(['local', 'testing'])) && (! $this->option('confirm-staging'))) {
            $this->components->error(sprintf(
                'Refusing to run in the "%s" environment without --confirm-staging. firms:provision is a staging/local tool — production provisioning is not supported by this command.',
                app()->environment(),
            ));

            return self::FAILURE;
        }

        // Deliberate, not a gap: production is refused even WITH
        // --confirm-staging, unlike the MFA emergency command's
        // --confirm-production escape hatch.
        if (app()->environment('production')) {
            $this->components->error('firms:provision must never run in production.');

            return self::FAILURE;
        }

        $requestedByEmail = $this->option('requested-by') ?: $this->ask('Platform admin email this provisioning is recorded against');
        $actor = PlatformAdmin::query()->where('email', $requestedByEmail)->first();

        if ($actor === null) {
            $this->components->error("No platform administrator found with email [{$requestedByEmail}].");

            return self::FAILURE;
        }

        // Same two checks ProvisionFirmAction's own closure enforces —
        // this console entry point has no ->visible() concept at all,
        // so skipping this here would let ANY PlatformAdmin (including
        // read_only_auditor) bypass the panel's own authorization
        // entirely via the command line.
        $accessPolicy = app(PlatformStaffAccessPolicyService::class);
        $manageDecision = $accessPolicy->canManageFirms($actor);

        if (! $manageDecision->allowed) {
            $this->components->error("Not permitted: {$manageDecision->reason}");

            return self::FAILURE;
        }

        $mutateDecision = $accessPolicy->canMutate($actor);

        if (! $mutateDecision->allowed) {
            $this->components->error("Not permitted: {$mutateDecision->reason}");

            return self::FAILURE;
        }

        $firmName = $this->option('firm-name') ?: $this->ask('Firm name');
        $ownerName = $this->option('owner-name') ?: $this->ask('Owner full name');
        $ownerEmail = $this->option('owner-email') ?: $this->ask('Owner email');
        $customerType = $this->option('customer-type') ?: $this->choice('Customer type', array_map(fn (CustomerType $c) => $c->value, CustomerType::cases()));
        $deploymentMode = $this->option('deployment-mode') ?: $this->choice('Deployment mode', array_map(fn (DeploymentMode $d) => $d->value, DeploymentMode::cases()), DeploymentMode::Saas->value);
        $organizationId = $this->option('organization-id');
        $planId = $this->option('plan-id');

        $this->components->twoColumnDetail('Firm', (string) $firmName);
        $this->components->twoColumnDetail('Owner', $ownerName.' <'.$ownerEmail.'>');
        $this->components->twoColumnDetail('Customer type', (string) $customerType);
        $this->components->twoColumnDetail('Deployment mode', (string) $deploymentMode);

        if (! $this->confirm('Provision this firm now?', false)) {
            $this->components->warn('Cancelled — nothing was created.');

            return self::SUCCESS;
        }

        $input = new FirmProvisioningInput(
            idempotencyKey: (string) Str::uuid(),
            firmName: (string) $firmName,
            legalName: null,
            organizationMode: filled($organizationId) ? FirmOrganizationProvisioningMode::UseExisting : FirmOrganizationProvisioningMode::None,
            organizationId: filled($organizationId) ? (int) $organizationId : null,
            newOrganizationName: null,
            ownerName: (string) $ownerName,
            ownerEmail: (string) $ownerEmail,
            reuseExistingUser: (bool) $this->option('reuse-existing-user'),
            customerType: CustomerType::from((string) $customerType),
            deploymentMode: DeploymentMode::from((string) $deploymentMode),
            planId: filled($planId) ? (int) $planId : null,
            trialDaysOverride: null,
            note: 'Provisioned via firms:provision console command.',
        );

        try {
            $result = $provisioningService->provision($input, $actor);
        } catch (PlatformAdminIdentityCollisionException|ExistingUserReviewRequiredException|FirmProvisioningRequestChangedException $e) {
            $this->components->error($e->getMessage());

            $auditRecorder->recordConsoleEvent('firm_provisioning_command_refused', 'firm_provisioning', [
                'reason' => $e::class,
            ]);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error('Provisioning failed. No partial firm was left behind.');
            report($e);

            return self::FAILURE;
        }

        $auditRecorder->recordConsoleEvent('firm_provisioned_via_command', 'firm_provisioning', [
            'firm_id' => $result->firm->id,
            'requested_by_platform_admin_id' => $actor->id,
        ]);

        if ($result->status === FirmProvisioningStatus::InvitationFailed) {
            $this->components->warn("Firm [{$result->firm->name}] provisioned, but the owner invitation email failed to send. Use the Platform Admin panel's \"Resend owner invitation\" action.");

            return self::SUCCESS;
        }

        $this->components->info("Firm [{$result->firm->name}] provisioned. The owner has been sent a setup link.");

        return self::SUCCESS;
    }
}
