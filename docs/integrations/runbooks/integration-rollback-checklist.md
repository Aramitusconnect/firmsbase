# Integration Framework Rollback Checklist

**As with [integration-deployment-checklist.md](integration-deployment-checklist.md), nothing in this document has ever been exercised.** No deployment of this framework has occurred, so no rollback has occurred either. This describes the real, existing rollback mechanism this repository already has (`docs/ecs/runbooks/rollback-runbook.md`) as it would apply to this framework's code, plus the 5-level containment hierarchy specific to this framework.

## 1. Five-level rollback/containment hierarchy (least to most disruptive)

| Level | Action | Requires redeploy? | Notes |
|---|---|---|---|
| 0 | Disable a feature/flag | No | Only real lever today: unschedule an Artisan command or scale a worker to zero — no in-app capability flag exists yet (see [feature-flag-rollout.md](../feature-flag-rollout.md)) |
| 1 | Disable a provider | No | Edit the provider-registry config map so a `ProviderKey` resolves to `null` — the provider becomes absent from the registry, `UnknownProviderException` on any attempted use. The only per-provider containment lever that exists today. |
| 2 | Pause queue/scheduler | No | Contains a runaway loop without a code rollback; jobs remain enqueued and are idempotent on resume |
| 3 | Application rollback | Yes | Redeploy the prior ECR image digest — safe because every integration migration is purely additive; old code ignores new columns/tables |
| 4 | Migration rollback | Yes (last resort) | Human-gated; requires independent proof no newer data would be destroyed. Almost never justified here, since everything in this framework's migration set is additive |

This mirrors the existing `docs/ecs/runbooks/rollback-runbook.md` philosophy exactly: **do not reflexively run `migrate:rollback`.** Prefer a forward-fix migration over a `down()` migration whenever the migration in question is not safely reversible in isolation.

## 2. Case: bad application code, no schema change involved

Re-deploy the previous known-good image digest by registering a new task definition revision pointing at it, then `update-service --force-new-deployment` for the affected service(s) — exactly as `docs/ecs/runbooks/rollback-runbook.md` Case 2.1 describes for any application. This framework introduces no different mechanism.

## 3. Case: a migration ran as part of the bad deploy

**Do not reflexively run `migrate:rollback`.** Because every migration in `database/migrations/2026_09_*` is purely additive:

- If the migration only added structures (new nullable column/table) and the old application code never referenced them: rolling back the application code (§2) is sufficient — the unused new column/table is harmless until a later forward-fix removes it.
- If a migration is genuinely not safely reversible in isolation: this requires a **forward fix**, not a rollback — write a new migration restoring compatibility, test it, deploy that. Human decision point; do not automate past it.

3 of this framework's RLS-related migrations are disclosed as lacking an independently executable rollback proof beyond their own `down()` method (see the migration ordering inventory referenced in [architecture.md](../architecture.md)) — this raises, rather than lowers, the bar for ever reaching for Level 4 against them.

## 4. Case: rolling back worker/scheduler specifically

Same mechanism as web (§2) — register the previous digest's task definition revision for that specific service. Services are independent; rolling back web does not require rolling back workers, and vice versa, unless the bad deploy included a schema change only one of them is compatible with — which would itself indicate the migration wasn't written expand-contract-safe, and is the actual bug to fix.

## 5. Verification after any rollback

- [ ] `/up` and `/readyz` both return 2xx.
- [ ] `security:rls-report` re-run and clean (critical if Level 4 was used) — see [rls-and-tenancy.md](../rls-and-tenancy.md).
- [ ] All 4 scheduled commands still registered.
- [ ] Webhook route still reachable (fail-closed probe, per [integration-deployment-checklist.md](integration-deployment-checklist.md) §7).
- [ ] `INTEGRATIONS_TEST_PROVIDER_ENABLED` and `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` both confirmed at their intended values.
- [ ] Digests/SHA/reason recorded in release evidence.

## 6. What this checklist deliberately does not do

Authorize deployment or rollback of anything. Automatically trigger on an alarm — no alerting infrastructure exists in this codebase (see [known-limitations.md](../known-limitations.md)); every rollback described here is an operator decision (or, at the infrastructure layer, ECS's own built-in deployment circuit breaker), never a fully automated alarm-to-rollback pipeline.
