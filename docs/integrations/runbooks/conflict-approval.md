# Runbook: Conflict Approval

## Symptom

A firm or platform operator needs to review and resolve an `integration_conflicts` row currently in `Detected` or `AwaitingReview` status.

## Real source involved

`IntegrationConflictService` (`recordDetection()`, `transitionStatus()`, `proposeResolution()`), `ConflictStatus` enum, `FirmIntegrationResource`'s `ConflictsRelationManager`.

## Conflict lifecycle

`ConflictStatus` has 7 cases: `Detected`, `AwaitingReview` (the 2 open states — `openStates()`), `ResolvedLocalWins`, `ResolvedRemoteWins`, `ResolvedMerged`, `Ignored` (the 4 resolved-shaped terminal states — `resolvedShapedStates()`), and `Expired` (the one fully-automated terminal state, administrative closure with no human actor — structurally blocked for any row with `requires_manual_review = true`, since silent expiry of a row that explicitly required review would itself be a form of un-audited auto-resolution).

**Important**: only `Detected` is written by the base detection code path (`recordDetection()`) today — the full resolution workflow (transitioning through `AwaitingReview` to a resolved-shaped state) is a Checkpoint 10/11-era capability; confirm which UI surface is actually wired for your environment before assuming a specific transition path is available end-to-end.

**Dual-approval discipline**: the schema uniformly requires dual approval (a real human actor) for every resolved-shaped terminal status — confirmed unchanged from prior review, and understood to err toward *more* caution than strictly required (a UX cost, not a defect) — a single-approver "fast path" does not exist and should not be worked around.

## Required role

Firm-plane: connect/configure ceiling (FirmOwner, Attorney) for actually resolving a conflict; view ceiling for read-only review. Platform-plane: SupportAgent+ with active support-access session, or SuperAdmin/PlatformAdmin/ImplementationSpecialist, for oversight visibility — platform staff resolving a firm's data conflict on their behalf should go through the same governed access model as any other per-firm mutating action.

## Approved interface

`FirmIntegrationResource`'s `ConflictsRelationManager` (firm-plane). No dedicated platform-plane conflict-resolution action beyond general per-firm oversight visibility was confirmed wired — platform staff assisting with a conflict should coordinate with the firm's own users to resolve it through the firm-plane interface rather than assuming a platform-side resolution shortcut exists.

## Steps

1. Identify the open conflict (`Detected` or `AwaitingReview`) and review both the local and remote values it's flagging (via `IntegrationConflict`'s sanitized value fields — never raw, unsanitized provider payload).
2. Determine the correct resolution: local wins, remote wins, merged, or ignore.
3. Apply the resolution through the relation manager, which drives `transitionStatus()`/`proposeResolution()` — dual approval is required for any resolved-shaped terminal status.
4. Confirm the resulting state matches the intended resolution.

## Prohibited actions

Never directly write to `integration_conflicts.status` bypassing `IntegrationConflictService` — the dual-approval and state-machine discipline lives in the service layer, not the database. Never attempt to work around the dual-approval requirement with a single-actor "fast path."

## Evidence to capture

Firm id, conflict id, local record type/id, resolution chosen, approving actor(s).

## Escalation condition

Any conflict where the correct resolution is ambiguous from the data alone (e.g. genuinely unclear which system's value is authoritative) should be escalated to the firm's own staff for a business decision — this framework provides the mechanism to record and audit the resolution, not the judgment of which side is right.

## Recovery verification

Conflict row reaches a resolved-shaped terminal status (`ResolvedLocalWins`/`ResolvedRemoteWins`/`ResolvedMerged`/`Ignored`), removing it from the open-conflicts count for the connection.
