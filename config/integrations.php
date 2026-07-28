<?php

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider;
use App\Integrations\Providers\Microsoft365\Microsoft365Provider;
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
        // explicitly true AND the running environment is not
        // `production` — both conditions are required, so the flag
        // alone can never register this class in production even if
        // misconfigured. Absent from the registry entirely whenever
        // either condition fails (defense in depth alongside
        // TestProvider::isConfigured() independently re-checking the
        // same two conditions).
        ProviderKey::Test->value => env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false) && ! app()->environment('production')
            ? TestProvider::class
            : null,

        // FirmsVault Live Integrations, Checkpoint 2 addition
        // (checkpoint2-combined-design.md §1.2). Unlike TestProvider,
        // there is no `! app()->environment('production')` term here —
        // Microsoft 365 is meant to run in production once enabled; the
        // env flag alone is the gate, the same shape ProviderRegistry/
        // ConnectProviderAction already assume for a real,
        // production-capable provider. Microsoft365Provider::isConfigured()
        // independently re-checks that the platform-level app
        // registration credentials (oauth_apps.microsoft365 below) are
        // actually present, mirroring TestProvider::isEnabledByEnvironment()'s
        // "defense in depth, never assumed true from the registry alone"
        // discipline. The referenced class does not exist yet as of this
        // change (a later checkpoint builds it) — this is safe: the
        // env() gate defaults false, so this map entry resolves to null
        // and ProviderRegistry::registeredMap() filters it out before
        // anything would ever try to instantiate the class; the literal
        // FQCN string below never triggers autoloading merely by being
        // written here.
        ProviderKey::Microsoft365->value => env('INTEGRATIONS_MICROSOFT365_ENABLED', false)
            ? Microsoft365Provider::class
            : null,

        // FirmsVault Live Integrations, Checkpoint 3 addition
        // (checkpoint3-combined-design.md §4.1). Same production-capable
        // shape as Microsoft365 immediately above (no
        // `! app()->environment('production')` term) — the env flag
        // alone is the gate; GoogleWorkspaceProvider::isConfigured()
        // independently re-checks that oauth_apps.googleworkspace's
        // platform credentials are actually present. The referenced
        // class is built as a parallel, disjoint change in this same
        // checkpoint — safe for the identical reason documented on the
        // Microsoft365 entry above: the env() gate defaults false, so
        // this map entry resolves to null until explicitly enabled.
        ProviderKey::GoogleWorkspace->value => env('INTEGRATIONS_GOOGLEWORKSPACE_ENABLED', false)
            ? GoogleWorkspaceProvider::class
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

        // Checkpoint 13 P3 (finding #5, DISABLE_BY_DEFAULT —
        // agent-13h-testing-release-review.md §3/§4 item 2). Kill-switch
        // for the three FIRM-DATA, client/matter-adjacent retention
        // sweeps (sync items, sync runs, resolved conflicts), which have
        // no legal-hold resolution layer today. Defaults OFF so this
        // unattended destructive path cannot delete firm data that might
        // be under a legal hold until a human explicitly enables it. The
        // platform-owned webhook-receipts sweep (no client/matter
        // linkage) and the outbox/OAuth-state sweeps are NOT gated by
        // this flag. This is purely a switch — it builds no legal-hold
        // resolution layer (explicitly out of scope for this checkpoint).
        'sweep_firm_data_enabled' => env('INTEGRATIONS_RETENTION_SWEEP_FIRM_DATA_ENABLED', false),
    ],

    /*
    |----------------------------------------------------------------
    | Checkpoint 9 — usage records
    |----------------------------------------------------------------
    |
    | NO DEFAULT (agent-9h-architecture-security-review.md §6.3,
    | explicitly REJECTING Agent 9A's own 400-day placeholder
    | recommendation) — matches the
    | `oauth_states.unconsumed_expired_retention_hours` fail-safe
    | precedent above: env('INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS')
    | with NO second argument. `IntegrationUsageRecorderService::recordOnce()`
    | leaves `retention_deadline` null when this resolves to null, rather
    | than guessing a number; any future sweep method must check for
    | null and no-op with a disclosed log event, never invent a value.
    |
    */

    'usage_records' => [
        'retention_days' => env('INTEGRATIONS_USAGE_RECORDS_RETENTION_DAYS'),
    ],

    /*
    |----------------------------------------------------------------
    | Checkpoint 1 (FirmsVault Live Integrations) — shared outbound
    | HTTP path, proactive rate limiting, sandbox/live environment
    | resolution
    |----------------------------------------------------------------
    |
    | `http`/`rate_limits` back App\Integrations\Support\ProviderRequestExecutor
    | (checkpoint1-design-http-ratelimit-usage.md §4.2) — the sole file
    | anywhere under app/Integrations/ permitted to reference
    | Illuminate\Support\Facades\Http. `provider_environments` backs
    | App\Integrations\Support\ProviderEnvironmentResolver
    | (checkpoint1-design-health-sandbox.md §B.2 point 1).
    |
    */

    'http' => [
        'default_timeout_seconds' => env('INTEGRATIONS_HTTP_DEFAULT_TIMEOUT_SECONDS', 15),
        'connect_timeout_seconds' => env('INTEGRATIONS_HTTP_CONNECT_TIMEOUT_SECONDS', 5),
        'correlation_id_header' => env('INTEGRATIONS_HTTP_CORRELATION_ID_HEADER', 'X-FirmsVault-Correlation-Id'),
    ],

    'rate_limits' => [
        // Applied to any ProviderKey with no explicit entry in
        // `providers` below — conservative by design (mirrors this
        // file's existing "conservative defaults, explicit opt-in for
        // anything looser" posture, e.g. retention.sweep_firm_data_enabled
        // defaults false). Every real adapter (Checkpoints 2-5) should
        // override this once its actual documented rate-limit ceiling
        // is known.
        'default' => [
            'max_attempts_per_window' => env('INTEGRATIONS_RATE_LIMIT_DEFAULT_MAX_ATTEMPTS', 30),
            'window_seconds' => env('INTEGRATIONS_RATE_LIMIT_DEFAULT_WINDOW_SECONDS', 60),
        ],

        'providers' => [
            ProviderKey::Test->value => [
                'max_attempts_per_window' => env('INTEGRATIONS_RATE_LIMIT_TEST_MAX_ATTEMPTS', 100),
                'window_seconds' => env('INTEGRATIONS_RATE_LIMIT_TEST_WINDOW_SECONDS', 60),
            ],

            // FirmsVault Live Integrations, Checkpoint 2 addition
            // (checkpoint2-design-oauth-capabilities.md §1.2).
            // PLACEHOLDER / CONSERVATIVE VALUES ONLY — Microsoft Graph's
            // exact per-API throttling ceilings (Mail, Calendar, Files
            // each publish different documented limits) were NOT
            // deep-fetched/confirmed as part of this checkpoint. Rather
            // than guess a specific number with false confidence
            // (matches this file's own established discipline — see
            // `usage_records.retention_days`'s deliberate "no default"
            // precedent above), this reuses `rate_limits.default`'s own
            // conservative shape/values verbatim. MUST be revisited and
            // set to Microsoft's actual documented per-resource
            // throttling ceilings before this provider is enabled
            // against real production traffic.
            ProviderKey::Microsoft365->value => [
                'max_attempts_per_window' => env('INTEGRATIONS_RATE_LIMIT_MICROSOFT365_MAX_ATTEMPTS', 30),
                'window_seconds' => env('INTEGRATIONS_RATE_LIMIT_MICROSOFT365_WINDOW_SECONDS', 60),
            ],

            // FirmsVault Live Integrations, Checkpoint 3 addition
            // (checkpoint3-combined-design.md §4.1). PLACEHOLDER /
            // CONSERVATIVE VALUES ONLY, identical posture to Microsoft
            // 365's own entry immediately above — Gmail/Calendar/Drive
            // each publish different documented per-API throttling
            // ceilings that were NOT deep-fetched/confirmed as part of
            // this checkpoint. Reuses `rate_limits.default`'s own
            // conservative shape/values verbatim rather than guessing a
            // specific number with false confidence. MUST be revisited
            // and set to Google's actual documented per-resource
            // throttling ceilings before this provider is enabled
            // against real production traffic.
            ProviderKey::GoogleWorkspace->value => [
                'max_attempts_per_window' => env('INTEGRATIONS_RATE_LIMIT_GOOGLEWORKSPACE_MAX_ATTEMPTS', 30),
                'window_seconds' => env('INTEGRATIONS_RATE_LIMIT_GOOGLEWORKSPACE_WINDOW_SECONDS', 60),
            ],

            // Checkpoints 4-5 each add one entry here, e.g.:
            // ProviderKey::Plaid->value => ['max_attempts_per_window' => 600, 'window_seconds' => 60],
        ],
    ],

    // FirmsVault Live Integrations, Checkpoint 2 addition
    // (checkpoint2-design-oauth-capabilities.md §1.2; checkpoint2-combined-design.md
    // §2 P-8). Microsoft 365 needs TWO distinct allowlisted hosts per
    // mode — an identity host (login.microsoftonline.com, for the
    // OAuth2 authorize/token endpoints) and a resource-API host
    // (graph.microsoft.com, for the actual Graph pull/push/webhook
    // calls) — which the prior singular sandbox_base_url/live_base_url
    // shape could not represent. `sandbox_base_urls`/`live_base_urls`
    // are now purpose-keyed arrays; ProviderEnvironmentResolver's
    // baseUrlFor()/assertUrlAllowedFor() take a matching `$purpose`
    // parameter (defaulting to `'default'` for a genuinely single-host
    // provider, e.g. a future Plaid entry).
    //
    // Note the sandbox and live URLs below are the SAME real Microsoft
    // hosts in both modes — this is deliberate, not a copy-paste
    // mistake (checkpoint2-design-oauth-capabilities.md §1.2/§7 FP-10):
    // for Microsoft 365, "sandbox vs. live" is a TENANT-identity
    // distinction (which Microsoft tenant a firm actually connects),
    // not a host/URL distinction the way it is for a provider like
    // Plaid with genuinely separate sandbox/production hosts. The
    // ProviderEnvironmentResolver host-allowlist guard is still
    // valuable here — it stops a misconfigured/non-Microsoft URL from
    // ever being requested (a real SSRF-adjacent safety net) — but it
    // cannot, by itself, distinguish a Developer Program tenant from a
    // real firm's production tenant the way it can distinguish Plaid
    // sandbox from Plaid production; that distinction lives entirely in
    // which tenant a firm actually connects, a policy/rollout decision
    // outside this config's scope.
    'provider_environments' => [

        ProviderKey::Microsoft365->value => [
            'mode' => env('INTEGRATIONS_MICROSOFT365_MODE', 'sandbox'),
            'sandbox_base_urls' => [
                'identity' => env('INTEGRATIONS_MICROSOFT365_SANDBOX_IDENTITY_BASE_URL', 'https://login.microsoftonline.com'),
                'graph' => env('INTEGRATIONS_MICROSOFT365_SANDBOX_GRAPH_BASE_URL', 'https://graph.microsoft.com'),
            ],
            'live_base_urls' => [
                'identity' => env('INTEGRATIONS_MICROSOFT365_LIVE_IDENTITY_BASE_URL', 'https://login.microsoftonline.com'),
                'graph' => env('INTEGRATIONS_MICROSOFT365_LIVE_GRAPH_BASE_URL', 'https://graph.microsoft.com'),
            ],
        ],

        // FirmsVault Live Integrations, Checkpoint 3 addition
        // (checkpoint3-combined-design.md §1.2, the reconciled/binding
        // shape). Google needs FOUR named purposes, not Microsoft's two —
        // the reconciliation between this checkpoint's OAuth design (which
        // proposed a 3-purpose split sharing one `workspace_api` purpose
        // for Calendar+Drive via ProviderEnvironmentResolver's boundary-
        // anchored path matching) and its sync/webhooks design (which
        // implements pull()/push()/subscribe() against Calendar and Drive
        // as two independently-toggleable purposes) resolved in favor of
        // four explicit purposes: simpler to reason about and test than
        // relying on path-prefix disambiguation between two Google APIs
        // that happen to share one host. `token` (not `identity`) is kept
        // as the auth-token purpose name — "analogous to, but distinct
        // from, Microsoft's `identity` purpose" (the one purpose name
        // either source design actually argued for). Deliberately NO
        // `'default'` key alongside these named purposes (Checkpoint 2
        // security review Finding 4's binding rule for every future
        // provider) — every call site
        // (exchangeCodeForToken()/refreshToken()/revokeAtProvider()/
        // pullGmail*()/pullCalendar()/pullDrive*()/subscribe()/
        // renewSubscription()) must pass an explicit `urlPurpose`.
        ProviderKey::GoogleWorkspace->value => [
            'mode' => env('INTEGRATIONS_GOOGLEWORKSPACE_MODE', 'sandbox'),
            'sandbox_base_urls' => [
                'token' => env('INTEGRATIONS_GOOGLEWORKSPACE_SANDBOX_TOKEN_BASE_URL', 'https://oauth2.googleapis.com'),
                'gmail' => env('INTEGRATIONS_GOOGLEWORKSPACE_SANDBOX_GMAIL_BASE_URL', 'https://gmail.googleapis.com'),
                'calendar' => env('INTEGRATIONS_GOOGLEWORKSPACE_SANDBOX_CALENDAR_BASE_URL', 'https://www.googleapis.com'),
                'drive' => env('INTEGRATIONS_GOOGLEWORKSPACE_SANDBOX_DRIVE_BASE_URL', 'https://www.googleapis.com'),
            ],
            'live_base_urls' => [
                'token' => env('INTEGRATIONS_GOOGLEWORKSPACE_LIVE_TOKEN_BASE_URL', 'https://oauth2.googleapis.com'),
                'gmail' => env('INTEGRATIONS_GOOGLEWORKSPACE_LIVE_GMAIL_BASE_URL', 'https://gmail.googleapis.com'),
                'calendar' => env('INTEGRATIONS_GOOGLEWORKSPACE_LIVE_CALENDAR_BASE_URL', 'https://www.googleapis.com'),
                'drive' => env('INTEGRATIONS_GOOGLEWORKSPACE_LIVE_DRIVE_BASE_URL', 'https://www.googleapis.com'),
            ],
        ],

    ],

    /*
    |----------------------------------------------------------------
    | FirmsVault Live Integrations, Checkpoint 2 — platform OAuth app
    | registration credentials
    |----------------------------------------------------------------
    |
    | The ONE app registration's credentials FirmsVault itself owns
    | with each provider (e.g. a single multi-tenant Azure AD app
    | registration for Microsoft 365, consented to individually by
    | each connecting firm's tenant/user) — NEVER a per-firm value,
    | never something a firm enters itself. Kept centralized in this
    | file (not config/services.php) per this file's own existing
    | convention of owning every Integration-domain config key.
    | `Microsoft365Provider::isConfigured()` returns true only when
    | both values below are present and non-empty (checkpoint2-design-oauth-capabilities.md
    | §1.2).
    |
    */

    'oauth_apps' => [

        ProviderKey::Microsoft365->value => [
            'client_id' => env('INTEGRATIONS_MICROSOFT365_CLIENT_ID'),
            'client_secret' => env('INTEGRATIONS_MICROSOFT365_CLIENT_SECRET'),
        ],

        // FirmsVault Live Integrations, Checkpoint 3 addition
        // (checkpoint3-combined-design.md §4.1/§4.6, §6.4.4 of
        // checkpoint3-design-sync-webhooks.md). `client_id`/`client_secret`
        // are the same "one app registration's credentials FirmsVault
        // itself owns" shape as Microsoft365's own entry above.
        //
        // The three Gmail-specific keys below are NOT OAuth client
        // credentials — they back the Gmail Cloud Pub/Sub push-delivery
        // trust boundary (a genuinely new, inbound, attacker-reachable
        // verification path with no Microsoft 365 precedent):
        //   - `pubsub_push_audience` / `pubsub_push_service_account_email`:
        //     the exact `aud` claim and push-auth service-account `email`
        //     claim GoogleWorkspaceProvider's inbound OIDC JWT
        //     verification (via Google\Auth\AccessToken::verify(), bound
        //     in app/Providers/IntegrationServiceProvider.php) checks with
        //     hash_equals() — never partial match, never trusted from the
        //     unverified webhook payload itself.
        //   - `gmail_mailbox_routing_hmac_key`: a NEW, DEDICATED,
        //     platform-wide secret (generated once via random_bytes(32),
        //     the same CSPRNG discipline
        //     ProviderConnectionService::generateRawWebhookRoutingToken()
        //     already uses), the HMAC key GmailMailboxRoutingService uses
        //     to compute integration_gmail_mailbox_routes.mailbox_lookup_hmac.
        //     Deliberately NEVER derived from APP_KEY (no cross-purpose
        //     key reuse) and NEVER a per-firm EmailBodyEncryptionService
        //     key (wrong shape for a lookup that must resolve BEFORE any
        //     firm context exists — see that migration's own "WHY THIS
        //     TABLE HAS NO RLS" docblock).
        //   - `gmail_pubsub_topic_name`: the fully-qualified Cloud Pub/Sub
        //     topic name (`projects/<project>/topics/<topic>`) GoogleWorkspaceProvider::subscribe()/renewSubscription()
        //     pass as watch()'s required `topicName` parameter — a
        //     platform-level, project-scoped resource (one shared topic
        //     for the whole platform, per checkpoint3-design-sync-webhooks.md
        //     §6.2's Path B design), never a per-firm value.
        ProviderKey::GoogleWorkspace->value => [
            'client_id' => env('INTEGRATIONS_GOOGLEWORKSPACE_CLIENT_ID'),
            'client_secret' => env('INTEGRATIONS_GOOGLEWORKSPACE_CLIENT_SECRET'),
            'pubsub_push_audience' => env('INTEGRATIONS_GOOGLEWORKSPACE_PUBSUB_PUSH_AUDIENCE'),
            'pubsub_push_service_account_email' => env('INTEGRATIONS_GOOGLEWORKSPACE_PUBSUB_PUSH_SERVICE_ACCOUNT_EMAIL'),
            'gmail_mailbox_routing_hmac_key' => env('INTEGRATIONS_GOOGLEWORKSPACE_GMAIL_MAILBOX_ROUTING_HMAC_KEY'),
            'gmail_pubsub_topic_name' => env('INTEGRATIONS_GOOGLEWORKSPACE_GMAIL_PUBSUB_TOPIC_NAME'),
        ],

    ],

];
