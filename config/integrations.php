<?php

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Providers\TestProvider\TestProvider;

/*
|--------------------------------------------------------------------------
| Integration Provider Registry
|--------------------------------------------------------------------------
|
| Structurally mirrors config/filesystems.php's disk-driver-listing
| shape: this file lists registered provider classes only, it wires no
| behavior (checkpoint-00-final-specification.md §6/§8/§21). Each entry
| maps a stable App\Integrations\Enums\ProviderKey string value to the
| fully-qualified provider class App\Integrations\Core\ProviderRegistry
| will resolve via the container. A null value means the key is
| currently NOT registered at all (ProviderRegistry::get() throws
| UnknownProviderException for it) — this is how environment-gated
| providers (like TestProvider) are kept out of the registry entirely
| when their gate is off, rather than merely "marked disabled" while
| still being resolvable.
|
| No real provider (Google/Microsoft/QuickBooks/LawPay/Clio/Stripe/
| Plaid/Zoom/Dropbox) is registered anywhere in this mission.
|
*/

return [

    'providers' => [

        // The only provider implemented in this mission
        // (checkpoint-00-final-specification.md §18). Never
        // registered unless INTEGRATIONS_TEST_PROVIDER_ENABLED is
        // explicitly true — default OFF, so it is absent from the
        // registry entirely in any environment that does not set this
        // flag (defense in depth alongside TestProvider::isConfigured()
        // independently re-checking the same flag).
        ProviderKey::Test->value => env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false)
            ? TestProvider::class
            : null,

    ],

    /*
    |----------------------------------------------------------------
    | Checkpoint 8 — outbox dispatch / retry / health / retention
    |----------------------------------------------------------------
    |
    | This is the first checkpoint whose file allowlist includes this
    | file (agent-8h-architecture-security-review.md §2 item 19). Every
    | key below formalizes an inline PHP-level default a Checkpoint
    | 6/7/8 service already falls back to when the key is absent — see
    | each service's own docblock for the exact default value and
    | citation. `oauth_states.unconsumed_expired_retention_hours` is
    | DELIBERATELY OMITTED — no inline default exists anywhere for it,
    | and the retention sweep must explicitly no-op (log
    | `oauth_state_unconsumed_cleanup_not_configured`) until a human
    | sets it (agent-8g-retention-cleanup-design.md §5.1/§9 B2) — never
    | guess a value for it here or anywhere else.
    |
    */

    'outbox' => [
        // Deterministic backoff ceiling shared by BOTH
        // IntegrationOutboxEventService::fail()'s own retry delay AND
        // RetryAfterParser's clamp ceiling for a provider-supplied
        // Retry-After signal — one shared maximum, never a second,
        // independently-configured, possibly-more-permissive one.
        'max_backoff_seconds' => env('INTEGRATIONS_OUTBOX_MAX_BACKOFF_SECONDS', 3600),

        'completed_retention_days' => env('INTEGRATIONS_OUTBOX_COMPLETED_RETENTION_DAYS', 30),
        'dead_lettered_retention_days' => env('INTEGRATIONS_OUTBOX_DEAD_LETTERED_RETENTION_DAYS', 90),
        'cancelled_retention_days' => env('INTEGRATIONS_OUTBOX_CANCELLED_RETENTION_DAYS', 30),
    ],

    'oauth_states' => [
        // The conservative/longer end of Checkpoint 5's frozen 24-72h
        // range — biases toward retaining forensic evidence longer.
        'consumed_retention_hours' => env('INTEGRATIONS_OAUTH_STATES_CONSUMED_RETENTION_HOURS', 72),

        // NO DEFAULT — see the file-level docblock above. Must remain
        // absent (not set to any number) unless a human explicitly
        // configures it.
        'unconsumed_expired_retention_hours' => env('INTEGRATIONS_OAUTH_STATES_UNCONSUMED_EXPIRED_RETENTION_HOURS'),
    ],

    'sync_runs' => [
        'retention_days' => env('INTEGRATIONS_SYNC_RUNS_RETENTION_DAYS', 180),
    ],

    'sync_items' => [
        'retention_days' => env('INTEGRATIONS_SYNC_ITEMS_RETENTION_DAYS', 60),
    ],

    'conflicts' => [
        'retention_days' => env('INTEGRATIONS_CONFLICTS_RETENTION_DAYS', 365),
    ],

    'webhook' => [
        // Verified-receipt evidence retention (frozen design §13's
        // 30-day commitment) — additive to whatever
        // receipt_retention_days / event_redact_after_days /
        // event_delete_after_days keys a prior checkpoint already
        // documented as an inline default.
        'receipt_verified_retention_days' => env('INTEGRATIONS_WEBHOOK_RECEIPT_VERIFIED_RETENTION_DAYS', 30),
    ],

    'health' => [
        'backoff_base_seconds' => env('INTEGRATIONS_HEALTH_BACKOFF_BASE_SECONDS', 60),
        'backoff_max_seconds' => env('INTEGRATIONS_HEALTH_BACKOFF_MAX_SECONDS', 3600),
        'degraded_after_failures' => env('INTEGRATIONS_HEALTH_DEGRADED_AFTER_FAILURES', 1),
        'unavailable_after_failures' => env('INTEGRATIONS_HEALTH_UNAVAILABLE_AFTER_FAILURES', 3),
        'diagnostic_summary_max_length' => env('INTEGRATIONS_HEALTH_DIAGNOSTIC_SUMMARY_MAX_LENGTH', 500),
    ],

    'retention' => [
        // Smaller, separate ceiling than tenant-table sweepers,
        // deliberately: bounds the blast radius of a single invocation
        // on integration_webhook_receipts — the one retention target
        // with no RLS backstop at all (agent-8g-retention-cleanup-design.md
        // §6 item 3).
        'platform_max_batches_per_run' => env('INTEGRATIONS_RETENTION_PLATFORM_MAX_BATCHES_PER_RUN', 50),
    ],

];
