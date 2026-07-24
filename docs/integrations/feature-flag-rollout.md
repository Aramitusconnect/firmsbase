# Feature Flags and Rollout

## 1. What is actually shipped today

Exactly **one** flag-adjacent change is shipped as of Checkpoint 14: **the TestProvider environment-name guard.** TestProvider's provider-registration gate (`config/integrations.php`) and its `isEnabledByEnvironment()` gate (`app/Integrations/Providers/TestProvider/TestProvider.php`) now both also require the application **not** be running in the `production` environment, independent of the `INTEGRATIONS_TEST_PROVIDER_ENABLED` value. See [testprovider.md](testprovider.md) for the exact code.

This closes a fail-closed gap in an already-shipped, already-intended control (TestProvider was always meant to be inert in production) — it is a narrowing-only security fix, not new capability. It was implemented by a separate, narrowly-scoped production writer under full diff-review/test/commit discipline, not by this documentation effort. This document only describes the resulting behavior.

**No other flag described in this document exists in the codebase today.** Everything in §2 and §3 below is deferred, unbuilt design work for a later, separately-authorized checkpoint.

## 2. Current control inventory (what exists today, without any new flag)

The framework's current posture is fail-closed **by composition**, not by a single master switch:

| Capability | Current control |
|---|---|
| Global integrations availability | No single master switch exists — fail-closed by composition (no provider registered without explicit opt-in; every downstream capability is itself gated) |
| Firm UI visibility | `IntegrationEntitlementPolicyService` (`integration` module_catalog code) |
| SuperAdmin UI visibility | `PlatformStaffAccessPolicyService::canAccessIntegrationOversight()` (role/policy) |
| Connection creation | Entitlement + provider-registration fail-closed |
| OAuth initiation | Entitlement |
| Webhooks (inbound route) | No control at ingress (route is always live once wired — see [webhooks.md](webhooks.md)); fail-closed at processing (signature verification) |
| Pull/push sync | Connection-status gated, no capability flag |
| Outbox dispatch | No in-app switch — only lever is unscheduling the command |
| Retries | No in-app disable — only lever is unscheduling |
| Retention sweeps | Fail-closed for firm-data sweeps (`INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED`, default `false`) / no control for the rest |
| Manual sync / requeue | Entitlement-controlled |
| Provider availability | Fail-closed (registry absence) |
| TestProvider specifically | Environment-controlled (see [testprovider.md](testprovider.md)) — **not** an independent flag, a composition of an env var and an environment-name check |
| Operational (support/platform) actions | Role + governed session (`SupportAccessSession`) |

Where an existing control is already adequate, no new flag is proposed to replace it: per-firm capability gating is already covered by the `integration` entitlement; provider availability is already fail-closed; operational actions are already role+session gated; the retention firm-data flag is already correctly scoped and should not be generalized into a broader "retention master" flag.

## 3. Proposed capability flags — DEFERRED, UNBUILT, future work only

Checkpoint 14 preparation work (Agent 14C) proposed a minimal hierarchy of **3 new capability flags**. **None of these exist in the codebase today. None are authorized for implementation at this checkpoint.** They are documented here purely so a future, separately-authorized checkpoint has a starting design — not as a roadmap commitment.

| Proposed flag | Proposed default | Proposed effect |
|---|---|---|
| `integrations.processing.enabled` | `true` | Pauses outbox/retry/sweep dispatch without touching the scheduler itself |
| `integrations.webhook.ingress_enabled` | `true` | In-app cutoff for inbound webhook ingress |
| `integrations.enabled` | `true` | Single global master switch, short-circuiting UI visibility + connection/OAuth initiation + the two flags above |

### Why these were ruled deferred, not approved (Agent 14H's ruling)

Agent 14H applied a fail-closed-gap test, not an operational-convenience test, to decide what belongs in this checkpoint. Verdict: these three flags **exceed the bar** for Checkpoint 14 and are deferred to documentation-only, because:

- None exist in the repo today, and the current posture is already fail-closed by composition (no provider registered without explicit opt-in; entitlement-gated UI/connection/OAuth; fail-closed provider availability).
- Building new switches here would be **net-new capability**, not gap closure — a materially different, broader change than the narrow TestProvider fix.
- No alerting infrastructure exists anywhere in this codebase that would ever trigger their use automatically (see [known-limitations.md](known-limitations.md) — observability is doc-manual/queryable-only, never alerting) — so a kill-switch that nothing can page an operator to flip has limited operational value until that changes too.
- Adequate manual containment levers already exist without them: unscheduling the relevant Artisan command, scaling workers to zero, and per-firm entitlement revocation.

**Do not implement any of these three flags without a new, dedicated review cycle explicitly authorizing them.** If a future change proposes implementing them, it should re-run the same fail-closed-gap test against the state of the codebase at that time, not assume this document's snapshot still applies.

## 4. Proposed 5-stage rollout plan — also deferred design, not a commitment

For completeness, the preparation work also proposed a 5-stage rollout plan built around the 3 deferred flags above (M=master, E=entitlement cohort, P=processing, W=webhook-ingress, T=TestProvider, R=retention-firm-data):

| Stage | Description | Flag states |
|---|---|---|
| 0 | Dev-only | T on in dev/CI only |
| 1 | Internal read-only | M on (prod), P/W off, no real provider registered — genuinely read-only |
| 2 | Controlled pilot | Small real cohort, P/W on |
| 3 | Expanded pilot | Wider cohort, R stays off |
| 4 | GA | R on only after a legal-hold resolution layer ships (see KR-01 in [known-limitations.md](known-limitations.md)) |

Proposed rollback/kill-switch order (most surgical → most drastic): (1) per-firm entitlement revoke, no deploy; (2) capability pause (P/W off), no deploy, global but non-destructive; (3) global master off (M=false); (4) provider deregistration, requires deploy, final hard cutoff.

**This entire section is design-only.** No stage of this plan has begun. No real provider has ever been registered. This framework has never been deployed to any real environment.
