# Known Limitations

This document cross-references the KR-01 through KR-19 risk register maintained in `firmsbase-integration-implementation-reports/reviews/checkpoint-14/preparation/agent-14e-risk-register.md` (the authoritative source for full severity/likelihood/component/mitigation/owner/status detail per entry). This document presents the dispositions that carry direct operational or compliance weight, stated precisely — **never more resolved than the source material supports.**

## 1. Headline entries (verified precisely against agent-14e's report and, where cited, against live source)

### KR-01 — Legal-hold retention gap: DISABLED_BY_DEFAULT, not resolved

`RetentionSweepJob`'s firm-data sweeps (sync items, sync runs, resolved conflicts) contain no `LegalHold`/`legal_hold` reference anywhere and issue unconditional age-based deletes. There is no resolution layer (`resource_type`+`local_id` → `client_id`/`matter_id`) that could even perform a legal-hold check today. `INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED` defaults to `false` specifically because of this gap.

**This is a kill-switch, not a fix.** The underlying gap — automated, unattended, irreversible deletion with no legal-hold awareness — still exists in the code; the flag only prevents that code from running by default. Do not describe this as "resolved," "fixed," or "closed." It blocks enabling firm-data retention at any rollout stage until a real resolution layer is built. See [runbooks/retention-kill-switch-runbook.md](runbooks/retention-kill-switch-runbook.md) and [runbooks/retention-sweep-failure.md](runbooks/retention-sweep-failure.md).

### KR-02 — Sync-item retry exhaustion: FIXED, genuinely resolved

Confirmed present and not reverted at HEAD. This is the one item in this register that genuinely warrants the word "resolved."

### KR-03 — Livewire tenant-context fix: FIXED for 4/7 actions, PARTIALLY PROVEN for 3/7

A Checkpoint 13 fix closed a tenant-context gap on the firm integrations detail page's Livewire update route. **Do not claim this is "fully fixed."** Of the affected mutating actions, 4 of 7 are fully proven correct via round-trip tests; the remaining 3 of 7 are partially proven — full closure requires additional (e.g. Dusk/browser-level) testing that has not yet been done. Treat this as a partial, disclosed closure, not a complete one.

### KR-15 — No operator-initiated kill-switch (compounds with KR-02): DEFERRED

Even with KR-02 fixed, there is still no operator-initiated disable for sync retries or outbox processing broadly — only the scheduler-unschedule / scale-to-zero levers described in [feature-flag-rollout.md](feature-flag-rollout.md) §2. Recommended before any Stage 3+ (limited real cohort) rollout; not built today.

## 2. Other carried-forward, named dispositions (from the risk register; precise KR-numbering for each beyond those above is authoritative only in the full agent-14e report, not reproduced here to avoid mis-numbering)

| Item | Disposition |
|---|---|
| Usage retention (`INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS`) | DEFERRED — coupled to usage-recorder wiring (below); correctly fail-safe by design (no invented compliance number as a default) |
| Conflict-value sanitization | ACCEPTED, dormant — zero current producer of unsanitized conflict values; must remain tracked for whenever a live producer lands |
| Audit attribution (`SupportAccessPolicyService::logSessionAudit()`) | STILL OPEN — source-level misattribution for cross-actor revokes remains; use the `platform_integration_oversight` security-events category, not `support_access`, when attributing a revoke. See [operations-superadmin.md](operations-superadmin.md) §5 and [runbooks/support-session-revoke.md](runbooks/support-session-revoke.md) |
| Rate-limiter wiring (`PerConnectionRateLimiter`) | STILL UNWIRED — must be decided (wired or explicitly deferred) before any real provider onboarding. See [security-model.md](security-model.md) §8 and [runbooks/rate-limiting.md](runbooks/rate-limiting.md) |
| Usage-recorder wiring (`IntegrationUsageRecorderService`) | STILL UNWIRED — the entire class has zero production call sites, a product decision, not casual wiring work. `integration_usage_records` stays permanently empty in production; `IntegrationUsagePage` will show empty usage data for every real connection |
| API-key connection path | STILL ABSENT — low priority; OAuth already satisfies the framework's own capability requirement |
| Accessibility coverage mapping (`AccessibilityCoverageMappingService`) | STILL a false all-clear — out-of-domain for this framework specifically, escalated separately; not owned by `app/Integrations/` |
| TestProvider production isolation | Confirmed gated as of Checkpoint 14's environment-name guard (see [testprovider.md](testprovider.md)) — precision caveat: this is a default-off env gate plus an environment-name check, not a hardcoded literal block independent of any configuration |

## 3. Compliance caution: the 2,555-day webhook retention window

The `integration_inbound_webhook_events` deletion horizon (2,555 days ≈ 7 years, `integrations.webhook.event_delete_after_days`) is a **disclosed, carried-forward placeholder originating in Checkpoint 7 §16**, first acted upon as an actual deletion horizon in Checkpoint 8. There is no seeded `RetentionPolicy` row and no `RetentionRecordType` case anchoring this specific number, and no documented human/legal decision establishes it as correct or required for this data.

**This window must never be described as legally validated or compliance-satisfying.** It is an open compliance question, not a settled one. See [webhooks.md](webhooks.md) §5 for the full detail and source citations.

## 4. Zero real-world operational validation

This entire framework — every claim in this documentation tree — describes application code that has never been deployed, never handled a real credential, and never processed traffic from a real provider. See [README.md](README.md#deployment-authorization) and [runbooks/integration-deployment-checklist.md](runbooks/integration-deployment-checklist.md).

## 5. Dead / unreachable code confirmed at HEAD

- `IntegrationUsageRecorderService` — entire class, zero production call sites.
- `PerConnectionRateLimiter` — unwired, only a docblock comment references it in production code.
- `TestProvider::rotateWebhookSigningKey()` — unwired, test-only caller.
- `FinancialIntegrationAccessPolicyService` — deliberate, documented "pure scaffolding," reviewed-safe; no financial provider exists to exercise it.

None of these are bugs to silently patch around — each is a disclosed, tracked gap. See [architecture.md](architecture.md) §4 and §2.
