# Firm provisioning and the trial-request boundary

## What "provisioning" means here

`App\Services\FirmProvisioningService` is the one place a complete,
login-ready firm tenant is created: `Organization` (optional) → `Firm` →
owner `User` → owner `FirmUser` membership → `FirmLicense` (trial) →
`FirmSettings` → base entitlements → tenant encryption key → activation
checklist shell, all in one atomic transaction, followed by a
post-commit owner invitation email.

Two callers exist today, both calling this same service and duplicating
none of its logic:

- `App\Filament\Actions\Platform\ProvisionFirmAction` — the Platform
  Admin "Provision firm" wizard on `FirmResource`/`ListFirms`.
- `firms:provision` — an optional staging/local console command.

## Firm activation lifecycle

`Firm.activation_status` (`App\Enums\FirmActivationStatus`) has exactly
three values: `Draft`, `Onboarding`, `Activated`. A firm created by
`FirmProvisioningService` always starts in `Onboarding` — never
`Activated` merely because database provisioning succeeded.

`App\Services\ActivationChecklistService` is the ONLY place a firm may
transition to `Activated`, gated on: a billing account, `firmSettings`
existing, at least one usable license, every required activation
checklist item satisfied, and an active tenant encryption key.
`FirmProvisioningService` provisions the license, `firmSettings`, and
the tenant encryption key, and creates the (empty) activation checklist
shell — but never calls `ActivationChecklistService::activate()` itself.
Reaching `Activated` requires further real-world steps (the owner
accepting their invitation, completing the checklist, etc.) that this
service does not simulate or skip.

## The trial-request boundary (deliberately not crossed here)

`App\Services\TrialRequestService` (`provision()`/`activate()`/
`convert()`) and its three Filament actions
(`ProvisionTrialRequestAction`/`ActivateTrialRequestAction`/
`ConvertTrialRequestAction`) remain **purely commercial-lifecycle**
actions on the `TrialRequest` row itself — attaching an `Organization`
and flipping a status enum, recording a conversion event. **None of them
create a `Firm`, `User`, `FirmUser`, license, or subscription.** This was
independently re-verified against the current code as part of this
feature's own discovery phase, not merely assumed from an earlier report.

This mission deliberately does **not** wire `TrialRequestService` into
`FirmProvisioningService` — the direct Platform Admin "Provision firm"
workflow must work, and was built to work, entirely independently of
any `TrialRequest`. `FirmProvisioningInput` carries no `TrialRequest`
reference of any kind, specifically so a **later, separately reviewed**
change can have `ProvisionTrialRequestAction`/`ActivateTrialRequestAction`
call `FirmProvisioningService::provision()` without this service itself
needing to change shape. Until that reviewed change happens, a converted
trial request and an actually-provisioned firm tenant are two unrelated
concepts in this codebase, and nothing should assume otherwise.

## Existing-owner-email handling

If the submitted owner email already belongs to a `User` row,
provisioning refuses by default (`ExistingUserReviewRequiredException`)
unless the caller explicitly sets `reuseExistingUser: true` — reuse is
always an explicit, audited operator decision, never inferred silently.
If the email belongs to an existing `PlatformAdmin` instead,
provisioning is refused outright and unconditionally
(`PlatformAdminIdentityCollisionException`) — a platform-staff identity
must never also become a firm-tenant login.

## Idempotency

`firm_provisioning_requests.idempotency_key` is unique. The Provision
Firm wizard mints this key once when it mounts; a genuine retry of the
identical submission (a double-click, a resubmit after a timeout)
carries the identical key and resumes the already-completed result
rather than creating a second firm. A key resubmitted with a
**different** payload is refused
(`FirmProvisioningRequestChangedException`) rather than silently
overwriting what that key already meant.
