# Firm-Facing Operations

## 1. Filament resource

`App\Filament\Firm\Resources\FirmIntegrationResource` — the first Filament Resource in this codebase's `App\Filament\Firm\*` namespace (Checkpoint 10). List and View pages only, deliberately **no Create/Edit pages**: "Connect" is a redirect-initiation Action with no user-entered record fields, and "configure" is narrowly rename + webhook-routing toggles — neither needs a generic model-bound Form schema that could ever accidentally reference credential fields.

- **Pages**: `ListFirmIntegrations`, `ViewFirmIntegration` (`app/Filament/Firm/Resources/FirmIntegrationResource/Pages/`).
- **Relation managers**: `ConflictsRelationManager`, `FailedItemsRelationManager`, `SyncRunsRelationManager`.
- **`recordTitleAttribute`**: `display_label` only — a confirmed-safe column, never a hidden-only/never column, per the global-search discipline requirement.
- **Companion page**: `App\Filament\Firm\Pages\IntegrationUsagePage`.

## 2. Two independent gates, both layered on the same page

- **Role authority**: `FirmIntegrationPolicy` gates `viewAny`/`view` via Laravel's standard policy mechanism, which Filament's `canAccess()`/`canViewAny()` defaults already consult automatically.
- **Entitlement**: a separate, UX-layer, non-throwing check (`IntegrationEntitlementPolicyService`) — hides the feature entirely for a disentitled firm rather than merely greying it out. `canAccess()` combines both; `shouldRegisterNavigation()` mirrors it for the nav item.

**Neither of these is the real security boundary.** The real boundary is every mutating service method's own `assertEnabled()`/`assertCan*()` calls, re-checked unconditionally inside each action's own closure — the UI-layer checks above exist for UX, not as the enforcement mechanism.

## 3. Role ceilings (from `IntegrationAccessPolicyService`, non-financial tier)

| Action | Roles |
|---|---|
| Connect / configure / disconnect | FirmOwner, Attorney |
| View connection / health / activity | FirmOwner, Attorney, Paralegal, LegalAssistant |
| View usage / billing impact | FirmOwner, BillingStaff |

`Receptionist` never appears in any allowlist. See [security-model.md](security-model.md) §4.

## 4. What a firm user can see and do today

- View connection list and per-connection detail (status, health, activity).
- Initiate a connect flow (OAuth redirect, or API-key entry for providers implementing `SupportsApiKeyContract` — no such provider is registered today; TestProvider implements it for exercise purposes only).
- Rename a connection's display label.
- Toggle webhook routing for a connection.
- Disconnect a connection.
- View sync runs, failed items, and conflicts for a connection (relation managers).
- View usage summary (`IntegrationUsagePage`) — reads `IntegrationUsageSummaryService`, which reads from `integration_usage_records`. Because `IntegrationUsageRecorderService` (the writer) has zero production call sites, this page will show empty/zero usage data for every real connection today — this is a known, disclosed gap, not a UI bug. See [known-limitations.md](known-limitations.md).

## 5. What a firm user cannot do

There is no in-app "pause/resume this connection" control, no in-app rate-limit visibility (the rate limiter is unwired — see [security-model.md](security-model.md) §8), and no self-service credential rotation UI beyond reconnect/disconnect. See [known-limitations.md](known-limitations.md) and the `runbooks/` tree for what an operator can do on a firm's behalf when something goes wrong.
