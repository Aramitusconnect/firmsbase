# Integration Framework Deployment Checklist

**Deployment of this framework remains separately, explicitly unauthorized for this mission.** No AWS/staging/production access has occurred at any point in the work that produced this documentation tree. This checklist describes what a real deployment would require, against the real, existing infrastructure this repository already has from a separate, prior mission — it does not authorize performing any of it, and nothing in it has been executed.

## 1. Real, existing infrastructure this would run on

- **Compute**: ECS Fargate, 4 long-running services (web, worker, critical-worker, scheduler) plus a one-off `migrate` task family — `infrastructure/ecs/modules/ecs_service`, `infrastructure/ecs/modules/ecs_cluster`.
- **Load balancing**: ALB — `infrastructure/ecs/modules/alb`.
- **Images**: ECR — `infrastructure/ecs/modules/ecr`. Every deployment step after push references the image **digest**, never a mutable tag.
- **Networking / IAM / security groups / KMS / ElastiCache**: `infrastructure/ecs/modules/{networking,iam,security_groups,kms,elasticache}`.
- **Environments**: `infrastructure/ecs/environments/staging/` (Terraform config exists; `terraform validate` passes; has never been `apply`'d with real AWS credentials by this mission) and `infrastructure/ecs/environments/production/` (a README placeholder only — **no production environment exists**).
- **CI**: `.github/workflows/ecs-pipeline.yml` builds, scans, and publishes an image but explicitly does **not** deploy — the `deploy-staging` job is a disabled stub. **No CD pipeline exists.**

This checklist describes a deploy of this framework's code onto that infrastructure — it does not describe standing up new infrastructure, which is `docs/ecs/`'s own scope, unrelated to this framework specifically.

## 2. Preconditions

- [ ] `feature/integration-core-framework` (or its target branch) is green — full test suite passing, including all 12 `*ForceRlsActivationTest.php` files (see [testing.md](../testing.md)).
- [ ] `security:rls-report` run and clean against a real database instance for this migration set (see [rls-and-tenancy.md](../rls-and-tenancy.md)).
- [ ] `INTEGRATIONS_TEST_PROVIDER_ENABLED` confirmed `false`/absent in the target environment's configuration — required in any production-like environment regardless of the Checkpoint 14 environment-name guard, which is defense-in-depth, not a reason to relax this check (see [testprovider.md](../testprovider.md)).
- [ ] `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` confirmed `false` — must never be flipped as part of a routine deploy; only after a legal-hold resolution layer exists (see [known-limitations.md](../known-limitations.md) KR-01).
- [ ] A fresh, restorable RDS snapshot exists and its ID is recorded in release evidence. RDS backup/snapshot policy for this application is otherwise undecided pending human approval — this checklist describes the check, it does not resolve that open policy question.
- [ ] Required secrets (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`) already exist in Secrets Manager at the ARNs the target Terraform environment references. A firm's own per-connection provider credential (an individual firm's OAuth access/refresh token) flows through the existing `IntegrationCredentialService` path — never a new mechanism.
- [ ] If Microsoft 365 (`INTEGRATIONS_MICROSOFT365_ENABLED`), Google Workspace (`INTEGRATIONS_GOOGLEWORKSPACE_ENABLED`), or Plaid (`INTEGRATIONS_PLAID_ENABLED`) is being enabled in the target environment, that provider's PLATFORM-level app-registration credentials are present in Secrets Manager / the target environment's config store — never committed to the repository, never left as the empty placeholder `.env.example` ships with. See `.env.example`'s own Integrations section and `config/integrations.php`'s `oauth_apps` key for the exact required set per provider (Microsoft 365: `INTEGRATIONS_MICROSOFT365_CLIENT_ID`/`_CLIENT_SECRET`; Google Workspace: `INTEGRATIONS_GOOGLEWORKSPACE_CLIENT_ID`/`_CLIENT_SECRET` plus the Gmail Pub/Sub trust-boundary keys; Plaid: `INTEGRATIONS_PLAID_CLIENT_ID`/`_SECRET`/`_ITEM_ROUTING_HMAC_KEY`/`_WEBHOOK_URL`). `IntegrationServiceProvider::boot()` logs a warning (not a hard failure) at application boot if a provider is enabled with any of these missing — treat that log line as a deploy-blocking signal, not routine noise.

## 3. Migration ordering

31 integration migrations (`database/migrations/2026_09_*`), all purely additive — the only `dropColumn`/`dropIndex` calls anywhere in the set are inside `down()` methods, never `up()`. RLS-prepare migrations follow this codebase's canonical expand-before-enable shape. No destructive migration exists anywhere in this domain, meaning old and new application code can coexist safely during a rolling deploy. Follow the same migration-task sequencing already described in `docs/ecs/runbooks/deployment-runbook.md` and `docs/ecs/database-migrations.md` — this framework introduces no new migration mechanism.

## 4. Deploy-time database gates (mirrors `DatabaseRoleProofTest.php`'s own proof, run as a deploy-time check rather than a test)

- [ ] Runtime database role is non-superuser, non-`BYPASSRLS`.
- [ ] Every FORCE-RLS table in this framework (12 tables — see [rls-and-tenancy.md](../rls-and-tenancy.md)) has both `relrowsecurity` and `relforcerowsecurity` set.
- [ ] Zero permissive (bare-`true`) policies exist anywhere in this framework's tables.
- [ ] No webhook-signing-secret carve-out exists on any policy — this was never authorized and must never appear.

## 5. Scheduler and queue

- [ ] Exactly one scheduler process (`schedule:work`/`schedule:run` cron) runs per environment — never more than one, since `withoutOverlapping()`'s cache lock is scoped per-scheduler-process. See [jobs-and-scheduler.md](../jobs-and-scheduler.md).
- [ ] Default queue connection confirmed as `database` (or whatever the target environment's actual queue driver is) before any job is expected to process.
- [ ] Workers reload code post-deploy (task definitions are held in memory) — every integration job is idempotent, so at-least-once redelivery after a restart is safe.

## 6. Zero-downtime consideration: the Livewire update-route middleware

The custom `/livewire/update` route's URI is unchanged from the framework's own routing — no ALB/routing change is needed. On old (pre-Checkpoint-13) task revisions, the tenant-context middleware for this route is absent, so the KR-03 bug (see [known-limitations.md](../known-limitations.md)) fails **closed** (denies), never open — safe during a rolling deploy. One real caveat: the route is registered dynamically in a service provider, so the deploy must rebuild all framework caches (`config:cache`/`route:cache`/etc.) — matching a stale-cache flake previously found and fixed during this framework's own RLS review.

## 7. Smoke tests (post-deploy, no real data touched)

- [ ] `/up` and `/readyz` both return 2xx.
- [ ] `migrate:status` shows all 31 integration migrations as `Ran`.
- [ ] Live RLS/role catalog check (§4) re-run against the deployed database.
- [ ] One real firm-panel Livewire action round-trip succeeds.
- [ ] All 4 scheduled commands (`integrations:outbox:dispatch`, `integrations:sync:retry-poll`, `integrations:retention:sweep`, `integrations:platform-overview:refresh`) show as registered.
- [ ] Webhook route reachable via a probe `POST` to an unknown provider — expect a fail-closed rejection (`401 {"status":"rejected"}`), never a `404`/`500`. See [webhooks.md](../webhooks.md).

## 8. Health checks

`/readyz` (checks DB, and Redis where configured) is the correct ALB health-check target. This framework's own `HealthStateService` is per-connection **business** health (is a specific provider connection healthy), not a deploy/infrastructure probe — do not wire it as one.

## 9. Record release evidence

Git commit SHA, image digest, task definition revisions, migration result, deployment result, timestamp, RDS snapshot ID used as the pre-deploy restore point.

## 10. What this checklist deliberately does not do

Authorize deployment. Every checkbox above describes a real, existing mechanism this repository already has from a separate, prior mission — none of it has been exercised by the work that produced this documentation tree. See [runbooks/integration-rollback-checklist.md](integration-rollback-checklist.md) for the corresponding rollback procedure.
