# Integration Framework

**Status as of Checkpoint 14:** application-code framework only. No real provider has ever been built, no real customer credential has ever existed, and this framework has never been deployed to any real environment (no AWS/staging/production access has occurred at any point in this mission — see [Deployment authorization](#deployment-authorization) below).

This is the documentation set for `app/Integrations/` — FirmsBase's provider-neutral framework for connecting a firm's account to an external system (OAuth, API-key, or webhook-driven), syncing data in both directions, and giving firm staff and platform staff bounded, audited operational visibility into that connection.

## What exists today

- **One registered provider**: `App\Integrations\Providers\TestProvider\TestProvider` — an internal, synthetic, zero-network-call adapter that exercises every framework capability end to end. See [testprovider.md](testprovider.md). No real provider (Google, Microsoft, QuickBooks, LawPay, Clio, Stripe, Plaid, Zoom, Dropbox, or any other) is registered or implemented anywhere in this mission.
- **16 tables** owned by this framework (not 17 — see the footnote below), all under `database/migrations/2026_09_*`. See [architecture.md](architecture.md) for the full inventory.
- **12 DirectTenant tables under FORCE ROW LEVEL SECURITY**, one canonical policy shape plus one documented, narrow deviation. See [rls-and-tenancy.md](rls-and-tenancy.md).
- **An inbound webhook intake route** (`POST /webhooks/integrations/{provider}`), wired and reachable since Checkpoint 7. See [webhooks.md](webhooks.md).
- **An outbox/sync/retry/retention machinery** driven by 4 scheduled Artisan commands and 7 queued jobs. See [sync-and-outbox.md](sync-and-outbox.md) and [jobs-and-scheduler.md](jobs-and-scheduler.md).
- **Firm-facing and SuperAdmin-facing Filament surfaces** for connection management and bounded cross-firm oversight. See [operations-firm.md](operations-firm.md) and [operations-superadmin.md](operations-superadmin.md).

> **Footnote — 16 tables, not 17.** An earlier checkpoint's own reporting (Checkpoint 13 and its own review) counted 17 integration tables. That count is wrong: it includes `integration_degradation_modes`, a **pre-existing, unrelated table from a separate Phase 16 mission** that only shares a naming prefix with this framework. `integration_degradation_modes` is created by `database/migrations/2026_07_25_900007_create_integration_degradation_modes_table.php` — dated five weeks before this framework's own `2026_09_xx` migration range — lives outside `app/Integrations/Models/` (its model, `IntegrationDegradationMode`, is owned by a distinct, pre-existing `IntegrationDegradationRegistryService`), and is explicitly named as a protected Phase 16 file in `tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php`. This framework genuinely owns 16 tables. See [architecture.md](architecture.md) §1 for the full list.

## Where to start

| Topic | Document |
|---|---|
| System shape, table/model/service inventory, request lifecycle | [architecture.md](architecture.md) |
| Threat model, credential handling, authorization tiers | [security-model.md](security-model.md) |
| Multi-tenancy, RLS policy shapes, the one documented deviation | [rls-and-tenancy.md](rls-and-tenancy.md) |
| OAuth connect/callback/refresh flow | [oauth.md](oauth.md) |
| Inbound webhook intake, signature verification, retention | [webhooks.md](webhooks.md) |
| Outbox (outbound events) and pull/push sync | [sync-and-outbox.md](sync-and-outbox.md) |
| Scheduled commands and background jobs | [jobs-and-scheduler.md](jobs-and-scheduler.md) |
| Firm-facing UI and role gates | [operations-firm.md](operations-firm.md) |
| SuperAdmin / platform oversight UI and support-access model | [operations-superadmin.md](operations-superadmin.md) |
| The TestProvider adapter | [testprovider.md](testprovider.md) |
| Test suite shape and how to run it | [testing.md](testing.md) |
| All 19 `INTEGRATIONS_*` env vars | [configuration.md](configuration.md) |
| Feature flags: what's shipped vs. deferred | [feature-flag-rollout.md](feature-flag-rollout.md) |
| Known gaps, the KR-01–KR-19 risk register | [known-limitations.md](known-limitations.md) |
| Operational runbooks | [runbooks/](runbooks/) |

## Deployment authorization

This framework has **never been deployed**. Real ECS Fargate / ALB / ECR infrastructure exists in this repository (`infrastructure/ecs/`, documented in `docs/ecs/`) from a separate, prior mission — this framework's code has never been built into an image, pushed to ECR, or run against that infrastructure, and no staging or production environment has ever been reached by this mission. Deploying this framework remains **separately, explicitly unauthorized** for this mission. See [runbooks/integration-deployment-checklist.md](runbooks/integration-deployment-checklist.md) for what such a deployment would require and who would need to authorize it.

## Style and scope of this documentation set

Every claim in this tree is grounded in source code read and verified at HEAD `c34f94c3752ad6f53b0f39de3f279da1f8b4ba13` on `feature/integration-core-framework` — file paths and class names are cited throughout so a reader can verify independently. Where no operator-facing tool exists today for a described scenario, the relevant document says so explicitly rather than describing a capability that doesn't exist.
