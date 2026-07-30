<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Billing\ProviderBillableCallResult;
use App\Integrations\Billing\ProviderOperationAttemptService;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\CursorStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\SyncItemStatus;
use App\Integrations\Enums\SyncRunStatus;
use App\Integrations\Enums\SyncTriggerSource;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Exceptions\SyncRunAlreadyInProgressException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationExternalMapping;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\IntegrationSyncRun;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationConflictService;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Services\SyncItemService;
use App\Integrations\Services\SyncRunService;
use App\Integrations\Support\FinancialEvidenceMaterializerService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Models\Firm;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PullSyncJob — Checkpoint 8 (agent-8c-sync-job-design.md §1-§7;
 * agent-8h-architecture-security-review.md §2 item 16). Scalar-ID-only
 * constructor mirroring App\Jobs\WebhookDispatchJob exactly. Introduces
 * ZERO new locking/claiming primitives — every mechanism below reuses
 * an already-proven one: the cursor claim is
 * App\Integrations\Services\SyncCursorService::claim(); resume-vs-start
 * is SyncRunService::startRun()'s own attempt-then-catch discipline.
 *
 * ---------------------------------------------------------------------
 * CHECKPOINT 8.2 (§A6) — NO TRANSACTION AND NO ROW LOCK ACROSS A
 * PROVIDER CALL
 * ---------------------------------------------------------------------
 *
 * WHAT WAS WRONG. This job used to run its ENTIRE body — every provider
 * HTTP request included — inside a single `runInFirmContext()`
 * transaction, while holding `FOR UPDATE` on its own
 * `firm_integrations` row (taken with `->lockForUpdate()` for
 * "fresh-read re-validation AND the connection lock"). Two consequences,
 * the second of which is a proven production defect:
 *
 *   1. Every local write for a multi-page sync was one enormous
 *      transaction that could not commit until the last provider
 *      response arrived, so a failure at page 40 discarded 39 pages of
 *      committed-looking work.
 *   2. Any OTHER database session that needed to write durably about
 *      this connection had to wait for that lock. Checkpoint 8.1 tried
 *      to record billable-call evidence from an independent session and
 *      deadlocked here: a cross-session INSERT whose foreign key
 *      references a row held `FOR UPDATE` must take `FOR KEY SHARE` and
 *      waits for a transaction that cannot commit until this job
 *      finishes. Proven live with pg_stat_activity/pg_locks.
 *
 * THE PHASING NOW. Three kinds of phase, and the provider call is alone
 * in the middle one:
 *
 *   CLAIM   `claimSyncOwnership()` — ONE short transaction. Re-reads the
 *           connection fresh (still never trusting the payload, just
 *           without a lock), resolves the cursor, starts or resumes the
 *           run, and takes the ONLY ownership primitive that matters:
 *           `SyncCursorService::claim()`'s atomic compare-and-set on the
 *           cursor row. Committed before any request leaves.
 *   PROVIDER `runInFirmContextWithoutTransaction()` — tenant context is
 *           session-scoped (exactly what the firm panel's HTTP
 *           middleware already establishes for a whole request), so RLS
 *           is satisfied for the billing pipeline's own writes, but NO
 *           transaction and NO row lock is held while a request is in
 *           flight.
 *   APPLY   `applyPage()` / `finishRun()` — one short transaction each,
 *           so a page's item writes and its cursor advance still commit
 *           atomically together, exactly as
 *           `SyncCursorService::advance()`'s contract requires.
 *
 * WHY DROPPING THE CONNECTION LOCK IS SAFE — and what it does change.
 * The lock never was what prevented double-processing; the cursor claim
 * is, and it is per (connection, resource type, direction), atomic, and
 * persisted. What the lock ALSO did, incidentally, was serialize two
 * unrelated things, and both are better off unserialized:
 *
 *   - Two pulls for DIFFERENT resource types on one connection may now
 *     overlap. Each owns a different cursor row, a different run, and
 *     mappings keyed by its own `resource_type`; the materializer only
 *     ever INSERTs. Nothing is shared to corrupt.
 *   - A credential refresh (`IntegrationCredentialService::withRefreshLock()`,
 *     which takes the same lock) may now proceed DURING a long sync
 *     instead of waiting for it. DISCLOSED TRADEOFF: a rotation between
 *     two pages can make the next page fail authentication, which
 *     surfaces as an ordinary sanitized failure and leaves the cursor
 *     un-advanced at its last committed position. That is strictly
 *     better than the alternative it replaces — blocking token refresh
 *     for up to five minutes per sync, which is how a connection ends up
 *     with an expired token and no way to refresh it.
 *
 * WHY THE CURSOR CLAIM NEEDED A LEASE. Because the claim now commits
 * instead of rolling back with a crashed worker's transaction, a killed
 * worker would otherwise leave the cursor `running` forever. See
 * `SyncCursorService::claim()`'s own docblock for the bounded
 * abandoned-claim takeover that closes that gap.
 */
final class PullSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * FirmsVault Live Integrations, Checkpoint 4 cost-control wiring pass
     * (checkpoint4-design-cost-control.md §2.1 call site #1, resolving
     * Finding 1 of checkpoint4-security-review.md). Translates this
     * framework's provider-neutral `ResourceType` value (this job's own
     * `$resourceType` constructor property) into
     * `App\Integrations\Billing\ProviderBillingClassifier`'s governed
     * `product` vocabulary — a DELIBERATELY SEPARATE axis from Plaid's
     * own `/link/token/create` `products` strings
     * (`PlaidProvider::translateCapabilitiesToProducts()`, e.g.
     * `income_verification`) and from `ResourceType`'s own value strings
     * (e.g. `bank_account`, `liability`) — neither of those two
     * vocabularies is what `ProviderBillingClassifier::classify()` (and,
     * downstream, `provider_rate_card_entries.product`/
     * `provider_kill_switches.target`) actually key on. Only entries for
     * the resource types `PlaidProvider::pullableResourceTypes()` /
     * `pull()` actually dispatches are listed; any resource type not
     * listed here falls back to its own raw `ResourceType` value (never
     * thrown), which only matters for a future non-Plaid
     * `RequiresBillableCallPipelineContract` implementer this map was
     * not written for.
     *
     * @var array<string, string>
     */
    private const PLAID_BILLING_PRODUCT_MAP = [
        ResourceType::BankAccount->value => 'auth',
        ResourceType::Transaction->value => 'transactions',
        ResourceType::Income->value => 'income',
        ResourceType::Liability->value => 'liabilities',
        ResourceType::Investment->value => 'investments',
        ResourceType::Statement->value => 'statements',
        ResourceType::Identity->value => 'identity',
    ];

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
        public readonly string $resourceType,
        public readonly ?int $triggeringWebhookEventId = null,
        public readonly ?int $retriedRunId = null,
        // Checkpoint 10 addition (frozen-design-post-security-review.md
        // §11; agent-10h-architecture-security-review.md §12, "Manual
        // sync"): additive, OPTIONAL, trailing scalar param — every
        // existing caller that omits it (the scheduler poller, webhook
        // dispatch, cursor-repair auto-fire, retry poller) is completely
        // unaffected and preserves today's exact behavior (the job calls
        // SyncRunService::startRun() itself, below). When the Livewire
        // manual-sync action handler has ALREADY called startRun()
        // synchronously (so the UI has an immediate run id to show), it
        // passes that run's id here so this job does not double-create a
        // second run for the same dispatch.
        public readonly ?int $preCreatedRunId = null,
        // Checkpoint 12 addition (frozen-design-post-security-review.md
        // §2 F2): additive, OPTIONAL, trailing nullable param — every
        // existing caller that omits it is completely unaffected and
        // preserves today's exact behavior (provider->pull() continues
        // to receive [] as its context argument). Exists so a test
        // harness (or a future real caller) can drive knobs the
        // provider's pull() reads out of its $context parameter (e.g.
        // TestProvider's simulate_pages/simulate_conflict_for/
        // FAILURE_SENTINEL-shaped knobs) without this job inventing any
        // provider-specific behavior of its own.
        //
        // Post-checkpoint-12 fix (JobConstructorsCarryOnlyScalarSecretSafeTypesTest):
        // declared ?string, not ?array — every ShouldQueue constructor
        // parameter in this codebase must be scalar/enum/DateTimeInterface
        // so Laravel never serializes an array into the queue payload.
        // Callers now pass a JSON-encoded string; decoded back to an
        // array in handle() below before use.
        public readonly ?string $providerContext = null,
    ) {}

    public function handle(
        SyncRunService $runs,
        SyncItemService $items,
        SyncCursorService $cursors,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
    ): void {
        // Resolved via the container (not added as new handle() parameters)
        // — App\Jobs\PullSyncJob's constructor docblock requires this
        // class's public signature to stay scalar-ID-only/stable, and
        // App\Integrations\Jobs\RefreshIntegrationToken::failed() already
        // establishes this exact app()-resolution pattern for the same
        // two services on the equivalent failure path.
        $credentials = app(IntegrationCredentialService::class);
        $healthState = app(HealthStateService::class);
        // FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
        // financial evidence add-on" — checkpoint4-combined-design.md
        // §1.1.3/§7). Resolved via the container, mirroring
        // $credentials/$healthState's own identical convention
        // immediately above, rather than widening this class's own
        // constructor (which must stay scalar-ID-only) or handle()'s
        // already-large injected-parameter list further.
        $materializer = app(FinancialEvidenceMaterializerService::class);

        // PHASE 1 — CLAIM. One short transaction, committed before any
        // provider request can leave this process. Returns the ids the
        // later phases re-read from, never live models carried across a
        // transaction boundary.
        $claim = $this->runInFirmContext($this->firmId, fn () => $this->claimSyncOwnership($runs, $cursors));

        if ($claim === null) {
            return;
        }

        // PHASE 2/3 — PROVIDER CALLS (no transaction, no row lock) with
        // per-page LOCAL APPLY transactions nested inside. Tenant context
        // is session-scoped here so the billing pipeline's own
        // RLS-protected writes still work; see this class's docblock.
        $this->runInFirmContextWithoutTransaction($this->firmId, function () use ($claim, $runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState, $materializer) {
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->firstOrFail();

            $run = IntegrationSyncRun::query()
                ->where('id', $claim['run_id'])
                ->where('firm_integration_id', $connection->id)
                ->firstOrFail();

            $cursor = IntegrationSyncCursor::query()
                ->where('id', $claim['cursor_id'])
                ->where('firm_integration_id', $connection->id)
                ->firstOrFail();

            $this->runBatchLoop($run, $cursor, $connection, $runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState, $materializer);
        });
    }

    /**
     * PHASE 1 — the only phase that establishes ownership, and the only
     * one that must be atomic with respect to a competing worker.
     *
     * Returns null when this job must not proceed at all (inactive
     * connection, un-claimable Invalid cursor, or a cursor another live
     * run already owns) — every one of those is a silent, deliberate
     * skip-duplicate, exactly as before.
     *
     * CHECKPOINT 8.2 (§A6): the connection re-read below is still a fresh,
     * past-dispatch-time re-verification scoped to this job's own
     * firm_id — nothing from the payload is trusted — but it no longer
     * takes `->lockForUpdate()`. See this class's docblock for why the
     * lock was not the mechanism preventing double-processing, and what
     * removing it does and does not change.
     *
     * @return array{run_id: int, cursor_id: int}|null
     */
    private function claimSyncOwnership(SyncRunService $runs, SyncCursorService $cursors): ?array
    {
        $connection = FirmIntegration::query()
            ->where('id', $this->firmIntegrationId)
            ->where('firm_id', $this->firmId)
            ->firstOrFail();

        if ($connection->status !== ConnectionStatus::Active) {
            return null;
        }

        $cursor = $cursors->firstOrCreate($connection, $this->resourceType, SyncDirection::Inbound);

        if ($cursor->status === CursorStatus::Invalid && $this->retriedRunId === null) {
            // An Incremental run must refuse to claim an Invalid
            // cursor at all — fail closed rather than starting a
            // Pending run for a scope that structurally cannot
            // proceed as an ordinary incremental pull.
            return null;
        }

        if ($this->preCreatedRunId !== null) {
            // Checkpoint 10 addition: the dispatching Livewire
            // handler already called SyncRunService::startRun()
            // synchronously (SyncTriggerSource::Manual) before
            // dispatching this job — re-fetch that SAME run fresh
            // (never trust anything about it beyond its id) rather
            // than creating a second one. Scoped to this connection
            // to guard against a mismatched/foreign run id.
            $run = IntegrationSyncRun::query()
                ->where('id', $this->preCreatedRunId)
                ->where('firm_integration_id', $connection->id)
                ->firstOrFail();
        } else {
            $triggerSource = $this->retriedRunId !== null
                ? SyncTriggerSource::RetryPoller
                : ($this->triggeringWebhookEventId !== null ? SyncTriggerSource::Webhook : SyncTriggerSource::SchedulerPoller);

            try {
                $run = $runs->startRun(
                    $connection,
                    $this->resourceType,
                    SyncDirection::Inbound,
                    $triggerSource,
                    $cursor,
                    $this->retriedRunId,
                    $this->triggeringWebhookEventId,
                );
            } catch (SyncRunAlreadyInProgressException $e) {
                $run = $e->existingRun;
            }
        }

        $claimedCursor = $cursors->claim($cursor->id, $run->id);

        if ($claimedCursor === null) {
            // Another run genuinely already holds this cursor —
            // skip-duplicate, never busy-wait or double-process.
            return null;
        }

        return ['run_id' => (int) $run->id, 'cursor_id' => (int) $claimedCursor->id];
    }

    /**
     * PHASES 2/3. Runs with tenant context active but NO enclosing
     * transaction (see this class's docblock): every provider call happens
     * outside a transaction and outside any row lock, and every local
     * write is committed by its own short `runInFirmContext()` block.
     */
    private function runBatchLoop(
        IntegrationSyncRun $run,
        IntegrationSyncCursor $cursor,
        FirmIntegration $connection,
        SyncRunService $runs,
        SyncItemService $items,
        SyncCursorService $cursors,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        ProviderRegistry $registry,
        OutboundProviderHttpClient $httpClient,
        IntegrationCredentialService $credentials,
        HealthStateService $healthState,
        FinancialEvidenceMaterializerService $materializer,
    ): void {
        $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

        // PREFLIGHT — one short transaction. Every check that can end the
        // run before a single request is made lives here, so none of them
        // can be left half-applied.
        $preflight = $this->runInFirmContext($this->firmId, fn () => $this->preflightRun(
            $run, $cursor, $connection, $provider, $runs, $cursors, $credentials, $healthState,
        ));

        if ($preflight === null) {
            return;
        }

        $pageCursor = $preflight['page_cursor'];
        $cursorVersion = $preflight['cursor_version'];

        $providerContext = $this->decodeProviderContext();
        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider) fix: a real provider's pull() must reach
        // ProviderRequestExecutor::send(), which requires a full
        // FirmIntegration object — the pre-Checkpoint-2 $providerContext
        // (test-only, JSON-encoded scalar bag) never carried one. Merged
        // in unconditionally, after decoding, so any caller-supplied
        // test keys are preserved and 'connection' is always present.
        $providerContext['connection'] = $connection;

        $itemsTotal = 0;
        $itemsSucceeded = 0;
        $itemsFailed = 0;
        $itemsSkipped = 0;
        $sawBlockingFailure = false;
        $sanitizedErrorSummary = null;
        // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
        // §1.4; checkpoint2-combined-design.md §2 P-15c): set only when
        // the CATEGORY_CURSOR_EXPIRED branch below has already called
        // invalidate() — that call already flips status and clears
        // cursor_value/cursor_value_encryption_key_id (and bumps
        // cursor_version) in one CAS-guarded statement, so the generic
        // markFailed() call after the loop must be skipped entirely for
        // this run; calling both would be redundant at best, a
        // version-conflict exception at worst.
        $cursorInvalidated = false;

        do {
            // ---------------------------------------------------------
            // PROVIDER CALL. No transaction is open and no row lock is
            // held for the duration of this block.
            // ---------------------------------------------------------
            try {
                $page = $this->fetchPage($provider, $connection, $run, $providerContext, $pageCursor, $cursorVersion, $httpClient);
            } catch (SanitizedProviderHttpException $e) {
                // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
                // §1.4; checkpoint2-combined-design.md §2 P-15c): a
                // provider-reported expired/invalid delta cursor (e.g.
                // Microsoft Graph's `410 Gone`) must invalidate the
                // cursor — the mission's "clear signal, never silently
                // restart full-history sync" self-healing mechanism —
                // INSTEAD OF falling through to the generic markFailed()
                // path below. SyncRunService::determineRunType() already
                // promotes the next dispatch against this now-Invalid
                // cursor to a Repair run; no further action is needed
                // here for the self-heal to take effect.
                if ($e->category() === SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED) {
                    $this->runInFirmContext($this->firmId, function () use ($cursors, $cursor, $cursorVersion) {
                        $cursors->invalidate($cursor->id, $cursorVersion);
                    });
                    $cursorInvalidated = true;
                    $sanitizedErrorSummary = 'pull_failed: cursor_expired_resync_required';
                    $sawBlockingFailure = true;
                    break;
                }

                // Checkpoint 1 (FirmsVault Live Integrations) addition
                // (checkpoint1-design-http-ratelimit-usage.md §4.4, last
                // bullet — optional, non-blocking): thread a
                // provider-supplied retryAfterSeconds into the run's
                // failure summary so a future scheduler-level "don't
                // re-dispatch before this timestamp" check (out of this
                // checkpoint's scope) has the data available. Purely
                // additive to the existing summary text — no behavior
                // change to cursor/run status handling below.
                $sanitizedErrorSummary = "pull_failed: {$e->category()}";
                if ($e->retryAfterSeconds() !== null) {
                    $sanitizedErrorSummary .= " retry_after_seconds={$e->retryAfterSeconds()}";
                }
                $sawBlockingFailure = true;
                break;
            }

            if ($page === null) {
                // CHECKPOINT 8.2 (§A6). The durable gate refused to call
                // the provider because THIS logical page of THIS run was
                // already requested by an earlier attempt whose local work
                // did not commit. The page's data is genuinely
                // unrecoverable — payloads are never stored (§A8), by
                // design — so there is nothing to resume from and nothing
                // to invent. End the run honestly, leaving the cursor at
                // its last committed position.
                //
                // This is not a stall: the next dispatch starts a NEW run,
                // whose id is part of the logical operation key, so the
                // page is legitimately re-requested as a new attempt cycle
                // (see ProviderBillableCallPipeline's docblock). The cost
                // is one re-fetched page after a crash in a narrow window,
                // which is the correct trade against either double-billing
                // silently or advancing a cursor over data never applied.
                $sanitizedErrorSummary = 'pull_failed: provider_page_already_consumed_new_run_required';
                $sawBlockingFailure = true;
                break;
            }

            // ---------------------------------------------------------
            // LOCAL APPLY. One short transaction per page, so the item
            // writes and the cursor advance still commit atomically
            // together — SyncCursorService::advance()'s own contract.
            // ---------------------------------------------------------
            $applied = $this->runInFirmContext($this->firmId, fn () => $this->applyPage(
                $page, $run, $cursor, $cursorVersion, $connection, $items, $cursors, $mappings, $conflicts, $materializer,
            ));

            $itemsTotal += $applied['items_total'];
            $itemsSucceeded += $applied['items_succeeded'];
            $itemsFailed += $applied['items_failed'];
            $itemsSkipped += $applied['items_skipped'];

            if ($applied['blocked']) {
                $sawBlockingFailure = true;
                break;
            }

            $cursorVersion = $applied['cursor_version'];
            $pageCursor = $applied['next_cursor'];
            // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
            // §1.3; checkpoint2-combined-design.md §2 P-15b): a provider
            // whose continuation token never goes null (e.g. Microsoft
            // Graph delta query's terminal deltaLink) MUST supply
            // 'has_more' => false on its terminal page so this loop stops
            // WITHOUT wiping the just-advanced cursor back to "no prior
            // sync" — see SupportsPullSyncContract::pull()'s docblock.
            // Absent 'has_more', this falls through to today's exact
            // next_cursor-null rule — zero behavior change for
            // TestProvider or any other provider that never sets it.
        } while (($page['has_more'] ?? ($pageCursor !== null)) && ! $this->cancellationRequested($run));

        // TERMINAL — one short transaction.
        $this->runInFirmContext($this->firmId, fn () => $this->finishRun(
            $run,
            $cursor,
            $runs,
            $cursors,
            $itemsTotal,
            $itemsSucceeded,
            $itemsFailed,
            $sawBlockingFailure,
            $cursorInvalidated,
            $sanitizedErrorSummary,
        ));
    }

    /**
     * Everything that can end this run before a single provider request
     * is made, in ONE transaction. Returns null when the run has already
     * been terminated here.
     *
     * @return array{page_cursor: ?string, cursor_version: int}|null
     */
    private function preflightRun(
        IntegrationSyncRun $run,
        IntegrationSyncCursor $cursor,
        FirmIntegration $connection,
        object $provider,
        SyncRunService $runs,
        SyncCursorService $cursors,
        IntegrationCredentialService $credentials,
        HealthStateService $healthState,
    ): ?array {
        $run = $runs->transitionStatus($run, SyncRunStatus::Running);

        if (! $provider instanceof SupportsPullSyncContract) {
            $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_pull');
            $cursors->markFailed($cursor->id);

            return null;
        }

        // Requirement (data-consistency edge case): FirmIntegration.status
        // === Active alone does not prove this connection actually holds
        // usable credential material — a connection whose OAuth
        // credentials have all been revoked/rotated away, but whose
        // status column has not (yet) transitioned off Active, must never
        // be allowed to complete a normal pull using no real credential
        // at all. Mirrors the "does an Active credential of the required
        // type exist" pattern IntegrationCredentialService::findActiveCredential()
        // already establishes (see WebhookConnectionResolverService's
        // identical use for webhook-signing-secret resolution) — reused
        // directly here rather than reimplemented.
        //
        // The existence check below scopes this to connections that have
        // actually had OAuth credentials provisioned at some point: a
        // provider that never requires OAuth credentials at all (e.g.
        // AuthMethod::None/ApiKey-only) legitimately has zero
        // integration_credentials rows of either OAuth type and must not
        // be denied a sync it was always going to run without one.
        $hasProvisionedOauthCredential = IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->whereIn('credential_type', [
                CredentialType::OauthAccessToken->value,
                CredentialType::OauthRefreshToken->value,
                // FirmsVault Live Integrations, Checkpoint 4 addition
                // ("Plaid financial evidence add-on" —
                // checkpoint4-combined-design.md §1.1.2, binding;
                // checkpoint4-design-plaid-provider-core.md §13/§15 item
                // 2). Plaid's access_token is stored under the new,
                // semantically-distinct CredentialType::ProviderAccessToken
                // case (never OauthAccessToken — see that enum case's
                // own docblock), so without this addition a Plaid
                // connection's credential-liveness safety net below
                // would never fire: a Plaid connection whose credential
                // was revoked/rotated away but whose `status` column has
                // not yet caught up would incorrectly be treated as
                // having "no provisioned OAuth-shaped credential at
                // all" and skip straight past the check that exists
                // specifically to catch that case.
                CredentialType::ProviderAccessToken->value,
            ])
            ->exists();

        // FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
        // financial evidence add-on" — checkpoint4-combined-design.md
        // §1.1.2, binding): the safety net's OWN liveness check must be
        // widened alongside the whereIn() list immediately above, not
        // just that list by itself — a Plaid connection never has an
        // Active CredentialType::OauthAccessToken row (its primary
        // credential is always ProviderAccessToken), so checking only
        // for OauthAccessToken here would make every Plaid connection
        // fail this safety net unconditionally, even a perfectly healthy
        // one. Checks for an Active credential of EITHER shape — exactly
        // one of the two is ever relevant for any given connection's
        // provider, so this cannot mask a genuine credential-liveness
        // gap for Microsoft/Google (whichever of the two is irrelevant
        // for a given provider is simply always null for it, changing
        // nothing about that provider's existing behavior).
        $hasActivePrimaryCredential = $credentials->findActiveCredential($connection, CredentialType::OauthAccessToken) !== null
            || $credentials->findActiveCredential($connection, CredentialType::ProviderAccessToken) !== null;

        if ($hasProvisionedOauthCredential && ! $hasActivePrimaryCredential) {
            // Fail safe: no provider call is ever attempted, the cursor
            // is left un-advanced (markFailed(), never advance()), and
            // the sanitized category reused here is 8E's own closed
            // taxonomy value for exactly this class of failure —
            // SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED
            // — formatted identically to the existing pull_failed
            // summary this job already writes from its catch block.
            $runs->transitionStatus(
                $run,
                SyncRunStatus::Failed,
                'pull_failed: '.SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED
            );
            $cursors->markFailed($cursor->id);

            $healthState->recordCredentialError(
                $connection->id,
                $connection->firm_id,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_PULL_SYNC,
                ),
            );

            return null;
        }

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider — checkpoint2-design-sync-webhooks.md §1.2)
        // addition: cursor_value is now encrypted at rest;
        // decryptCursorValue() is the transparent decrypt-on-read layer
        // (returns null unchanged for a fresh/invalidated cursor). The
        // provider's pull() still only ever sees a plaintext cursor
        // string, exactly as SupportsPullSyncContract already documents.
        return [
            'page_cursor' => $cursors->decryptCursorValue($connection, $cursor),
            'cursor_version' => (int) $cursor->cursor_version,
        ];
    }

    /**
     * ONE provider page. Deliberately the only method in this class that
     * performs an outbound call, and it opens no transaction and takes no
     * lock.
     *
     * Returns null — and ONLY null — when the durable gate refused the
     * call because this exact logical page was already requested by an
     * earlier attempt whose local work never committed. See the caller's
     * handling of that case.
     *
     * @param  array<string, mixed>  $providerContext
     * @return array<string, mixed>|null
     */
    private function fetchPage(
        object $provider,
        FirmIntegration $connection,
        IntegrationSyncRun $run,
        array $providerContext,
        ?string $pageCursor,
        int $cursorVersion,
        OutboundProviderHttpClient $httpClient,
    ): ?array {
        // FirmsVault Live Integrations, Checkpoint 4 cost-control
        // wiring pass (checkpoint4-design-cost-control.md §2.1
        // call site #1, resolving Finding 1 of
        // checkpoint4-security-review.md). Additive `instanceof`
        // branch only — every other provider
        // (Microsoft365Provider, GoogleWorkspaceProvider,
        // TestProvider) does not implement
        // RequiresBillableCallPipelineContract and falls straight
        // through to the else branch below, which is the exact,
        // byte-for-byte unchanged `$httpClient->execute(...)`
        // call this file has always made.
        if (! $provider instanceof RequiresBillableCallPipelineContract) {
            return $httpClient->execute(fn () => $provider->pull($providerContext, $this->resourceType, $pageCursor), 'pull');
        }

        $billingProduct = self::PLAID_BILLING_PRODUCT_MAP[$this->resourceType] ?? $this->resourceType;

        $result = app(ProviderBillableCallPipeline::class)->execute(
            providerKey: $provider->key(),
            connection: $connection,
            // Anti-tautology (ProviderBillableCallPipeline's
            // own class docblock, addition #0): $firm is
            // resolved from this job's own scalar
            // $firmId constructor property — the job's
            // independently-dispatched firm context — never
            // from $connection->firm, which would make step
            // 2's ownership check tautological.
            firm: Firm::query()->findOrFail($this->firmId),
            // System/job-triggered call — pipeline step 1's
            // own documented branch. $connection->status has
            // already been re-verified Active in
            // claimSyncOwnership(), before this job ever reaches
            // runBatchLoop().
            actor: null,
            product: $billingProduct,
            billingOperation: 'sync',
            environment: (new ProviderEnvironmentResolver)->modeFor($provider->key()),
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::from($this->resourceType),
            providerCall: fn () => $httpClient->execute(fn () => $provider->pull($providerContext, $this->resourceType, $pageCursor), 'pull'),
            // DETERMINISTIC (double-billing remediation).
            // This used to end in `now()->format('YmdHi')`,
            // so a re-execution of the same logical page
            // fetch that crossed a minute boundary produced a
            // DIFFERENT key, reserved a brand-new row rather
            // than colliding, and billed the provider call
            // twice. Lower-severity than
            // RenewGraphSubscriptionJob's identical weakness
            // (this job is $tries = 1, so Laravel never
            // auto-retries it) but fixed for the same reason:
            // a crashed/redelivered job, or any future
            // increase of $tries, hits it.
            //
            // The replacement identifies THIS specific page
            // of THIS specific sync attempt from durable
            // state that already exists: the sync run's id,
            // the cursor's current version (bumped by
            // SyncCursorService::invalidate(), so a post-410
            // repair run is correctly a different logical
            // operation), and the page cursor being
            // requested. No wall clock, no new column.
            usageIdempotencyKey: 'plaid_pull:'.$connection->id.':'.$this->resourceType.':'.hash('sha256', implode('|', [
                (string) $run->id,
                (string) $cursorVersion,
                (string) ($pageCursor ?? 'initial'),
            ])),
            provider: $provider,
            requiredContractFqcn: SupportsPullSyncContract::class,
            // CHECKPOINT 8.2 (§A6/§A8). Safe recovery evidence only —
            // how many items this page carried and whether more follow,
            // never an item, an account number, or a payload. Enough for
            // an operator to reconcile a page that was fetched but never
            // applied.
            redactResultForRecovery: static fn (mixed $response) => is_array($response)
                ? 'items='.count($response['items'] ?? []).';has_more='.(($response['has_more'] ?? null) ? '1' : '0')
                : null,
            localProcessingState: 'run_'.$run->id.':cursor_version_'.$cursorVersion.':page_'.($pageCursor ?? 'initial'),
        );

        // CHECKPOINT 8.2 (§A5/§A6). The durable gate short-circuited: the
        // provider was NOT called, and no response exists to apply. Both
        // shapes mean the same thing for a sync page — this exact page was
        // already requested by an earlier attempt. Report the outcome on
        // the attempt row so it does not sit unresolved, then tell the
        // caller there is nothing to apply.
        if ($result->outcome->servedWithoutProviderCall() && $result->response === null) {
            $this->recordUnrecoverablePage($result);

            return null;
        }

        return $result->response;
    }

    /**
     * A page the provider already delivered to an earlier attempt cannot
     * be resumed: this system deliberately never stores provider payloads
     * (§A8), so the data is gone. Record that honestly on the durable row
     * — `local_processing_failed`, never `complete` (nothing was applied)
     * and never `reconciliation_required` (no human decision is needed:
     * the next run re-fetches the page under its own logical key).
     */
    private function recordUnrecoverablePage(ProviderBillableCallResult $result): void
    {
        if ($result->operationAttempt === null || $result->operationOwnerToken === null) {
            return;
        }

        if (! $result->mustResumeLocalProcessing()) {
            return;
        }

        app(ProviderOperationAttemptService::class)->markLocalProcessingFailed(
            $result->operationAttempt,
            $result->operationOwnerToken,
            'page_payload_unavailable_deferred_to_new_run',
            $result->operationAttempt->local_processing_state,
        );
    }

    /**
     * ONE page's local writes, in ONE transaction: every item's mapping/
     * conflict/materialization plus the cursor advance that publishes
     * them. Nothing here touches the network.
     *
     * @param  array<string, mixed>  $page
     * @return array{items_total: int, items_succeeded: int, items_failed: int, items_skipped: int, blocked: bool, next_cursor: ?string, cursor_version: int}
     */
    private function applyPage(
        array $page,
        IntegrationSyncRun $run,
        IntegrationSyncCursor $cursor,
        int $cursorVersion,
        FirmIntegration $connection,
        SyncItemService $items,
        SyncCursorService $cursors,
        IntegrationExternalMappingService $mappings,
        IntegrationConflictService $conflicts,
        FinancialEvidenceMaterializerService $materializer,
    ): array {
        $itemsTotal = 0;
        $itemsSucceeded = 0;
        $itemsFailed = 0;
        $itemsSkipped = 0;
        $batchBlocked = false;

        foreach (($page['items'] ?? []) as $externalItem) {
            $itemsTotal++;

            $externalId = (string) ($externalItem['external_id'] ?? '');
            $versionToken = $externalItem['version_token'] ?? null;

            $mapping = IntegrationExternalMapping::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', $this->resourceType)
                ->where('external_id', $externalId)
                ->whereNull('tombstoned_at')
                ->first();

            if ($mapping === null) {
                // FirmsVault Live Integrations, Checkpoint 4 addition
                // ("Plaid financial evidence add-on" —
                // checkpoint4-combined-design.md §1.1.3/§7,
                // implementation ownership reassigned to the Plaid
                // provider-core phase). A narrow, disclosed,
                // Plaid-only widening: for every other provider (and
                // for any Plaid resource type this materializer does
                // not handle, or a `_removed`-marked Transaction item
                // with no prior local mapping to remove), this branch
                // is COMPLETELY UNCHANGED — falls straight through to
                // the exact same Skipped outcome as before.
                $materializableType = $connection->providerKey() === ProviderKey::Plaid
                    ? ResourceType::tryFrom($this->resourceType)
                    : null;

                $isRemovedMarker = is_array($externalItem['raw'] ?? null)
                    && ($externalItem['raw']['_removed'] ?? false) === true;

                if ($materializableType !== null && ! $isRemovedMarker && $externalId !== '') {
                    // Materializes a brand-new local
                    // financial_evidence_* row (this materializer
                    // only ever INSERTs — every table it writes to
                    // is immutable, append-only) and records the
                    // durable local<->external identity bridge in
                    // the SAME pass, so this run's own
                    // recordAttempt() call below has a real
                    // local_type/local_id to attribute this item to.
                    $materialized = $materializer->materialize($connection, $materializableType, $externalItem);

                    $mapping = $mappings->recordMapping(
                        $connection,
                        $this->resourceType,
                        $materialized['local_type'],
                        $materialized['local_id'],
                        $externalId,
                        SyncDirection::Inbound,
                        $versionToken,
                        null,
                    );

                    $status = SyncItemStatus::Succeeded;
                } else {
                    // No FirmsBase-side local record exists for this
                    // external object yet. Creating one is resource-
                    // type-specific business logic this provider-neutral
                    // framework layer does not own (no generic hook
                    // exists in this codebase for it yet) — recorded
                    // Skipped, never a silently-invented local record.
                    $status = SyncItemStatus::Skipped;
                }
            } elseif ($mapping->external_version_token !== null && $mapping->external_version_token !== $versionToken) {
                // The remote object has moved since we last saw it —
                // explicit conflict, never a silent overwrite.
                $conflicts->recordDetection(
                    $connection,
                    $this->resourceType,
                    (string) ($mapping->local_type ?? 'unknown'),
                    (int) ($mapping->local_id ?? 0),
                    'remote_version_changed',
                    externalMappingId: $mapping->id,
                    localVersionToken: $mapping->local_version_token,
                    externalVersionToken: $versionToken,
                );
                $status = SyncItemStatus::Skipped;
            } else {
                $mappings->refreshVersionTokens($mapping, $versionToken, $mapping->local_version_token);
                $status = SyncItemStatus::Succeeded;
            }

            $item = $items->recordAttempt(
                $connection->firm_id,
                $run->id,
                $this->resourceType,
                $mapping?->local_type,
                $mapping?->local_id,
                $externalId === '' ? null : $externalId,
                $status,
                payloadHash: hash('sha256', json_encode($externalItem, JSON_THROW_ON_ERROR)),
            );

            match ($item->status) {
                SyncItemStatus::Succeeded => $itemsSucceeded++,
                SyncItemStatus::Skipped => $itemsSkipped++,
                default => $itemsFailed++,
            };

            if ($item->blocksCursorAdvancement()) {
                $batchBlocked = true;
            }
        }

        $nextCursor = $page['next_cursor'] ?? null;

        if ($batchBlocked) {
            // The cursor is deliberately NOT advanced, and this
            // transaction still commits the item rows that record WHY —
            // the blocked page's evidence must survive, exactly as it did
            // when the whole job shared one transaction.
            return [
                'items_total' => $itemsTotal,
                'items_succeeded' => $itemsSucceeded,
                'items_failed' => $itemsFailed,
                'items_skipped' => $itemsSkipped,
                'blocked' => true,
                'next_cursor' => null,
                'cursor_version' => $cursorVersion,
            ];
        }

        // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
        // §1.2): advance() now requires $connection (needed to
        // encrypt $nextCursor per-firm before persisting). A
        // cursor_version mismatch throws CursorVersionConflictException
        // out of this transaction, rolling back this page's item writes
        // with it — the documented, intended behavior.
        $advanced = $cursors->advance($connection, $cursor->id, $cursorVersion, $nextCursor);

        return [
            'items_total' => $itemsTotal,
            'items_succeeded' => $itemsSucceeded,
            'items_failed' => $itemsFailed,
            'items_skipped' => $itemsSkipped,
            'blocked' => false,
            'next_cursor' => $nextCursor,
            'cursor_version' => (int) $advanced->cursor_version,
        ];
    }

    /**
     * The run's terminal write, in ONE transaction.
     */
    private function finishRun(
        IntegrationSyncRun $run,
        IntegrationSyncCursor $cursor,
        SyncRunService $runs,
        SyncCursorService $cursors,
        int $itemsTotal,
        int $itemsSucceeded,
        int $itemsFailed,
        bool $sawBlockingFailure,
        bool $cursorInvalidated,
        ?string $sanitizedErrorSummary,
    ): void {
        $terminalStatus = $sawBlockingFailure
            ? ($itemsSucceeded > 0 ? SyncRunStatus::PartialFailure : SyncRunStatus::Failed)
            : $runs->determineTerminalStatus($itemsTotal, $itemsSucceeded, $itemsFailed);

        $runs->transitionStatus($run, $terminalStatus, $sanitizedErrorSummary);

        if ($sawBlockingFailure && ! $cursorInvalidated) {
            // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
            // §1.4): skipped when the CATEGORY_CURSOR_EXPIRED branch
            // above already called invalidate() — that call already
            // flipped status/bumped cursor_version; calling markFailed()
            // on top of it would be redundant at best, a version-conflict
            // exception at worst.
            $cursors->markFailed($cursor->id);
        }
    }

    /**
     * Decode the JSON-encoded providerContext string back into an array
     * — see this job's constructor docblock (§ post-checkpoint-12 fix)
     * for why the constructor-declared type is ?string rather than
     * ?array. This parameter is only ever set by test code today (frozen
     * design), so a defensive is_array() fallback to [] on malformed
     * input is sufficient — no need to throw.
     *
     * @return array<string, mixed>
     */
    private function decodeProviderContext(): array
    {
        if ($this->providerContext === null) {
            return [];
        }

        $decoded = json_decode($this->providerContext, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cancellationRequested(IntegrationSyncRun $run): bool
    {
        return $run->fresh()?->cancel_requested_at !== null;
    }
}
