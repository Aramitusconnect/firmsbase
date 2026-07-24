# Testing

## 1. Location

- `tests/Feature/Integrations/` — including `Admin/`, `EndToEnd/`, `Ui/` subdirectories.
- `tests/Unit/Integrations/` — including `Outbox/`, `Support/` subdirectories.

117 test files across both directories at HEAD (method-count totals vary depending on counting methodology across different checkpoint reports — treat any single reported number as a snapshot, not a fixed fact; re-count fresh at the commit in question rather than trusting a prior report's figure).

## 2. RLS activation tests

One dedicated `*ForceRlsActivationTest.php` per FORCE-RLS table, proving the negative case (cross-firm access denied), not merely assuming it from a positive case passing — the established pattern for every forced table in this codebase:

`FirmIntegrationsForceRlsActivationTest`, `IntegrationConflictsForceRlsActivationTest`, `IntegrationConnectionHealthForceRlsActivationTest`, `IntegrationCredentialsForceRlsActivationTest`, `IntegrationExternalMappingsForceRlsActivationTest`, `IntegrationOauthStatesForceRlsActivationTest`, `IntegrationOutboxEventsForceRlsActivationTest`, `IntegrationSyncCursorsForceRlsActivationTest`, `IntegrationSyncItemsForceRlsActivationTest`, `IntegrationSyncRunsForceRlsActivationTest`, `IntegrationUsageRecordsForceRlsActivationTest` (all `tests/Feature/Integrations/`), plus `IntegrationInboundWebhookEventsForceRlsActivationTest` (`tests/Unit/Integrations/`) — 12 total, one per FORCE-RLS table. A companion `IntegrationWebhookRoutingIndexNoRlsAndNoSecretColumnTest` proves the *absence* of RLS and of any secret column on the platform-owned routing-index table, the negative-space counterpart to the 12 positive activation tests.

## 3. Governance/firewall tests

`tests/Feature/Governance/DeploymentEnvironment/DeploymentEnvironmentFirewallTest.php` includes checks relevant to this framework: forbidding real alerting-provider tokens (supporting the "no real alerting exists" posture — see `docs/integrations` observability framing in [known-limitations.md](known-limitations.md)) and confirming protected Phase 16 files (including `integration_degradation_modes`'s migrations, per [architecture.md](architecture.md)'s footnote) were not modified.

## 4. Running the suite

Standard Laravel/Pest or PHPUnit invocation against this codebase's existing test tooling — no integration-specific test runner exists. Run the full suite, or scope to the integration directories:

```
php artisan test tests/Feature/Integrations tests/Unit/Integrations
```

This framework has never been exercised against a real database beyond the disposable test databases this suite itself creates and tears down — no staging or production test run has ever occurred. See [README.md](README.md#deployment-authorization).

## 5. What the test suite proves, and what it doesn't

The suite proves: tenant isolation (RLS activation tests), idempotency of outbox/webhook writes, guarded-UPDATE state-machine correctness (outbox/sync-item terminal states), the Livewire tenant-context fix for 4 of 7 affected actions (see [known-limitations.md](known-limitations.md) KR-03), and TestProvider's full capability surface end-to-end with zero real network calls.

The suite does **not** prove: behavior against a real provider (none exists), behavior under real production load/concurrency at scale, or the remaining 3 of 7 Livewire actions to full closure (partially proven only — browser-level testing would be required for that, and has not been done).
