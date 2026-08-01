<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmActivationStatus;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmProvisioningStatus;
use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\LicenseStatus;
use App\Enums\PlanStatus;
use App\Enums\RecordStatus;
use App\Exceptions\ExistingUserReviewRequiredException;
use App\Exceptions\FirmProvisioningRequestChangedException;
use App\Exceptions\InactivePlanSelectedException;
use App\Exceptions\PlatformAdminIdentityCollisionException;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\FirmProvisioningRequest;
use App\Models\FirmSettings;
use App\Models\FirmUser;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\ValueObjects\FirmProvisioningInput;
use App\ValueObjects\FirmProvisioningResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * FirmProvisioningService — the ONE authoritative place a complete,
 * login-ready firm tenant is created. Both the Platform Admin "Provision
 * firm" action and the optional `firms:provision` Artisan command call
 * this service; neither duplicates any of its logic. A future
 * trial-conversion workflow (ProvisionTrialRequestAction/
 * ActivateTrialRequestAction) is explicitly NOT wired to this service
 * yet — see this class's own "Trial request boundary" section below.
 *
 * REUSES every existing authoritative service this repository's own
 * discovery phase found: EncryptionKeyService::provision() (tenant
 * encryption key), ActivationChecklistService::createChecklist() (the
 * activation-checklist shell), EntitlementPlanSyncService::syncPlanEntitlements()
 * (base entitlements from the selected Plan), PlatformAdminAuditEventRecorder
 * (audit). Two real gaps were confirmed during discovery — no production
 * service creates a `FirmLicense` or a `FirmSettings` row today, only
 * test factories do — so this service is their first authoritative
 * production writer, via plain `::create()` calls, not a duplicated
 * wrapper service invented to look like there was something to
 * delegate to.
 *
 * TRANSACTION SHAPE. `Firm` itself is not RLS-protected (it IS the
 * tenancy boundary — see Firm's own docblock) and is created OUTSIDE any
 * tenant context. Every subsequent write (FirmUser, FirmSettings,
 * FirmLicense, entitlements, the activation checklist, the tenant
 * encryption key, the audit event) is FORCE-RLS-protected and runs
 * inside exactly ONE outer `TenantContextService::runWithFirmContext()`
 * call, which itself opens the real `DB::transaction()`. Several of the
 * inner service calls (EncryptionKeyService::provision(),
 * ActivationChecklistService::createChecklist(),
 * EntitlementService::setForSource()) already self-wrap their own body
 * in a SECOND, nested `runWithFirmContext()` call — this is safe to nest
 * (Laravel's transaction nesting uses savepoints, and each inner call's
 * own `finally` restores the context to whatever this outer wrap already
 * set, never clearing it — see ActivationChecklistService's own
 * "decoy wrap" docblock note for the failure mode this reasoning avoids)
 * rather than a defect to route around.
 *
 * IDEMPOTENCY. `firm_provisioning_requests.idempotency_key` is UNIQUE —
 * the single INSERT that claims a row IS the compare-and-set gate for
 * "two concurrent submissions create one Firm." The wizard mints this
 * key ONCE per form session; a genuine retry of the identical submission
 * carries the identical key and identical payload hash and resumes the
 * already-completed result; a key reused for a DIFFERENT payload is
 * refused (FirmProvisioningRequestChangedException).
 *
 * INVITATION TIMING. Dispatched strictly AFTER the local transaction
 * commits (no network/mail work happens while any row lock is held).
 * A delivery failure leaves the firm correctly provisioned but marks the
 * request `invitation_failed` rather than rolling anything back —
 * ResendFirmOwnerInvitationAction is the recovery path.
 *
 * TRIAL REQUEST BOUNDARY. TrialRequestService::provision()/activate()/
 * convert() remain purely commercial-lifecycle actions on the
 * TrialRequest row itself (organization attach + status flip) — they do
 * not call this service today. This service's own inputs were kept
 * deliberately independent of TrialRequest (no TrialRequest parameter
 * anywhere on FirmProvisioningInput) specifically so a LATER, separately
 * reviewed change can have ProvisionTrialRequestAction/
 * ActivateTrialRequestAction call `provision()` here without this
 * service itself needing to change shape.
 */
final class FirmProvisioningService
{
    public function __construct(
        private readonly EncryptionKeyService $encryptionKeyService,
        private readonly ActivationChecklistService $activationChecklistService,
        private readonly EntitlementPlanSyncService $entitlementPlanSyncService,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder,
    ) {}

    public function provision(FirmProvisioningInput $input, PlatformAdmin $actor): FirmProvisioningResult
    {
        $payloadHash = $input->payloadHash();

        $existingRequest = FirmProvisioningRequest::query()
            ->where('idempotency_key', $input->idempotencyKey)
            ->first();

        if ($existingRequest !== null) {
            return $this->resumeExisting($existingRequest, $payloadHash);
        }

        // Owner-email collision rules (mission §7) — checked BEFORE
        // claiming an idempotency row, so a rejected submission never
        // consumes a key.
        if (PlatformAdmin::query()->where('email', $input->ownerEmail)->exists()) {
            throw new PlatformAdminIdentityCollisionException;
        }

        $existingUser = User::query()->where('email', $input->ownerEmail)->first();
        $reuseExistingUser = false;

        if ($existingUser !== null) {
            if (! $input->reuseExistingUser) {
                throw new ExistingUserReviewRequiredException($existingUser->id);
            }

            $reuseExistingUser = true;
        }

        // The claim: this INSERT's uniqueness on idempotency_key is the
        // whole CAS gate for concurrent double-submission.
        try {
            $request = FirmProvisioningRequest::create([
                'idempotency_key' => $input->idempotencyKey,
                'request_payload_hash' => $payloadHash,
                'requested_by_platform_admin_id' => $actor->id,
                'status' => FirmProvisioningStatus::Pending,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost the race — another submission (or this same
            // double-click) already claimed this key. Resume ITS result
            // rather than proceeding to do the work twice.
            $winner = FirmProvisioningRequest::query()
                ->where('idempotency_key', $input->idempotencyKey)
                ->firstOrFail();

            return $this->resumeExisting($winner, $payloadHash);
        }

        try {
            [$firm, $owner] = $this->provisionLocalRecords($input, $actor, $request, $existingUser, $reuseExistingUser);
        } catch (Throwable $e) {
            $request->forceFill([
                'status' => FirmProvisioningStatus::Failed,
                'failure_category' => $this->safeFailureCategory($e),
            ])->save();

            $this->auditRecorder->recordPlatformEvent($actor, 'firm_provisioning_failed', 'firm_provisioning', [
                'idempotency_key' => $input->idempotencyKey,
                'failure_category' => $this->safeFailureCategory($e),
            ]);

            throw $e;
        }

        $invitationSucceeded = $this->dispatchOwnerInvitation($owner);

        $request->forceFill([
            'status' => $invitationSucceeded ? FirmProvisioningStatus::Completed : FirmProvisioningStatus::InvitationFailed,
        ])->save();

        return new FirmProvisioningResult($request->fresh(), $firm, $owner, $request->status, resumedFromExistingRequest: false);
    }

    /**
     * ResendFirmOwnerInvitationAction's sole entry point. Never
     * recreates the Firm/User/FirmUser — only re-dispatches the
     * password-setup notification and clears an `invitation_failed`
     * status back to `completed` on success.
     */
    public function resendInvitation(Firm $firm, PlatformAdmin $actor): bool
    {
        $request = FirmProvisioningRequest::query()->where('firm_id', $firm->id)->first();
        $owner = $request?->ownerUser;

        if ($owner === null) {
            $owner = (new TenantContextService)->runWithFirmContext($firm, fn () => $firm->firmUsers()
                ->where('role', FirmUserRole::FirmOwner->value)
                ->first()?->user);
        }

        if ($owner === null) {
            throw new \RuntimeException('This firm has no resolvable owner to invite.');
        }

        $succeeded = $this->dispatchOwnerInvitation($owner);

        if ($request !== null) {
            $request->forceFill([
                'status' => $succeeded ? FirmProvisioningStatus::Completed : FirmProvisioningStatus::InvitationFailed,
            ])->save();
        }

        $this->auditRecorder->record($firm, $actor, 'firm_owner_invitation_resent', 'firm_provisioning', [
            'owner_user_id' => $owner->id,
            'succeeded' => $succeeded,
        ]);

        return $succeeded;
    }

    /**
     * @return array{0: Firm, 1: User}
     */
    private function provisionLocalRecords(
        FirmProvisioningInput $input,
        PlatformAdmin $actor,
        FirmProvisioningRequest $request,
        ?User $existingUser,
        bool $reuseExistingUser,
    ): array {
        // ONE outer transaction covers EVERYTHING, including Firm
        // creation itself. This matters: Firm is not RLS-protected and
        // needs no tenant context to insert, but it still must roll
        // back if ANY later step (plan resolution, entitlement sync,
        // the encryption key, ...) fails — otherwise a failure after
        // this point would leave exactly the orphan Firm this service's
        // whole failure design exists to prevent.
        // TenantContextService::runWithFirmContext() below opens its
        // OWN nested DB::transaction() once the Firm exists — safe to
        // nest (Laravel uses savepoints), and any exception anywhere
        // inside propagates out through both layers to roll back this
        // outer transaction in full.
        return DB::transaction(function () use ($input, $actor, $request, $existingUser, $reuseExistingUser) {
            $organization = match ($input->organizationMode) {
                FirmOrganizationProvisioningMode::CreateNew => Organization::create([
                    'name' => $input->newOrganizationName,
                    'status' => RecordStatus::Active,
                ]),
                FirmOrganizationProvisioningMode::UseExisting => Organization::query()->findOrFail($input->organizationId),
                FirmOrganizationProvisioningMode::None => null,
            };

            $firm = Firm::create([
                'organization_id' => $organization?->id,
                'name' => $input->firmName,
                'legal_name' => $input->legalName,
                'customer_type' => $input->customerType,
                'deployment_mode' => $input->deploymentMode,
                'activation_status' => FirmActivationStatus::Onboarding,
            ]);

            $owner = (new TenantContextService)->runWithFirmContext($firm, function () use ($input, $actor, $firm, $request, $existingUser, $reuseExistingUser) {
                $owner = $reuseExistingUser
                    ? $existingUser
                    : User::create([
                        'name' => $input->ownerName,
                        'email' => $input->ownerEmail,
                        // An unusable, never-returned, never-logged random
                        // placeholder — the owner sets their REAL password
                        // exclusively through the post-commit invitation
                        // flow. Never displayed or included in any response.
                        'password' => Str::random(64),
                    ]);

                FirmUser::create([
                    'user_id' => $owner->id,
                    'firm_id' => $firm->id,
                    'role' => FirmUserRole::FirmOwner,
                    'status' => FirmUserStatus::Invited,
                    'is_primary' => true,
                ]);

                // No production service creates firm_settings today (repo
                // discovery confirmed only test factories do) — this is the
                // first authoritative writer. Every column beyond firm_id
                // has a safe database default.
                FirmSettings::create([
                    'firm_id' => $firm->id,
                ]);

                $plan = null;

                if ($input->planId !== null) {
                    $plan = Plan::query()->findOrFail($input->planId);

                    // Re-check status at the moment the transaction
                    // actually runs, not just at the wizard's earlier
                    // search-time filter — see
                    // InactivePlanSelectedException's own docblock for
                    // the stale-UI-state scenario this guards against.
                    if ($plan->status !== PlanStatus::Active) {
                        throw new InactivePlanSelectedException($plan->name, $plan->status->value);
                    }

                    $trialDays = $input->trialDaysOverride ?? $plan->trial_days;
                    $startsAt = now();

                    // Same gap as firm_settings — no production FirmLicense
                    // creator exists yet (FirmLicenseCommercialService only
                    // mutates an EXISTING row). billing_account_id is left
                    // null: a Draft/Onboarding firm's license is a trial,
                    // never yet tied to real platform billing — matches
                    // Firm.billing_account_id's own "nullable to allow a
                    // firm to exist pre-activation" design.
                    FirmLicense::create([
                        'firm_id' => $firm->id,
                        'plan_id' => $plan->id,
                        'license_key' => (string) Str::uuid(),
                        'license_status' => LicenseStatus::Trial,
                        'deployment_mode' => $input->deploymentMode,
                        'customer_type' => $input->customerType,
                        'starts_at' => $startsAt,
                        'expires_at' => $trialDays !== null ? $startsAt->copy()->addDays($trialDays) : null,
                    ]);

                    $this->entitlementPlanSyncService->syncPlanEntitlements($firm, $plan, null);
                }

                // Both of the following self-wrap their own body in a
                // SECOND runWithFirmContext() call — safe to nest, see this
                // class's own docblock.
                $this->encryptionKeyService->provision($firm);
                $this->activationChecklistService->createChecklist($firm);

                $this->auditRecorder->record($firm, $actor, 'firm_provisioned', 'firm_provisioning', [
                    'idempotency_key' => $request->idempotency_key,
                    'organization_id' => $firm->organization_id,
                    'firm_id' => $firm->id,
                    'owner_user_id' => $owner->id,
                    'owner_email_redacted' => $this->redactEmail($owner->email),
                    'customer_type' => $input->customerType->value,
                    'deployment_mode' => $input->deploymentMode->value,
                    'plan_id' => $plan?->id,
                    'reused_existing_user' => $reuseExistingUser,
                ]);

                $request->forceFill([
                    'firm_id' => $firm->id,
                    'owner_user_id' => $owner->id,
                ])->save();

                return $owner;
            });

            return [$firm, $owner];
        });
    }

    private function resumeExisting(FirmProvisioningRequest $existingRequest, string $payloadHash): FirmProvisioningResult
    {
        if ($existingRequest->request_payload_hash !== $payloadHash) {
            throw new FirmProvisioningRequestChangedException;
        }

        if ($existingRequest->status === FirmProvisioningStatus::Pending) {
            // Another attempt is (or was) mid-flight on this exact key.
            // A short-lived local operation should settle almost
            // immediately; surface this as a retryable condition rather
            // than blocking indefinitely.
            throw new \RuntimeException('This provisioning request is still in progress. Please try again shortly.');
        }

        $firm = $existingRequest->firm;
        $owner = $existingRequest->ownerUser;

        if ($firm === null || $owner === null) {
            // status=Failed never populated firm_id/owner_user_id.
            throw new \RuntimeException('This provisioning request previously failed. Correct the request or use a new one.');
        }

        return new FirmProvisioningResult($existingRequest, $firm, $owner, $existingRequest->status, resumedFromExistingRequest: true);
    }

    private function dispatchOwnerInvitation(User $owner): bool
    {
        try {
            $status = Password::broker('users')->sendResetLink(['email' => $owner->email]);

            return $status === Password::RESET_LINK_SENT;
        } catch (Throwable) {
            return false;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }

    /**
     * A short, safe, non-secret category only — never the raw exception
     * message (which could contain a query fragment or other internal
     * detail unsafe for an audit row).
     */
    private function safeFailureCategory(Throwable $e): string
    {
        return match (true) {
            $e instanceof QueryException => 'database_error',
            $e instanceof ModelNotFoundException => 'referenced_record_not_found',
            $e instanceof InactivePlanSelectedException => 'inactive_plan_selected',
            default => 'unexpected_error',
        };
    }

    private function redactEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $redactedLocal = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : $local[0].str_repeat('*', strlen($local) - 2).substr($local, -1);

        return $redactedLocal.'@'.$domain;
    }
}
