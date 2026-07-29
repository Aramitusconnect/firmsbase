<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Integrations\Billing\ProviderBillableCallPipeline;
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
 * an already-proven one: the connection lock is
 * App\Integrations\Services\IntegrationCredentialService::withRefreshLock()'s
 * exact lockForUpdate() shape; the cursor claim is
 * App\Integrations\Services\SyncCursorService::claim(); resume-vs-start
 * is SyncRunService::startRun()'s own attempt-then-catch discipline.
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

        $this->runInFirmContext($this->firmId, function () use ($runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState, $materializer) {
            // Requirement 2: re-verify fresh, past-dispatch-time —
            // never trust anything carried in the job payload itself.
            // ->lockForUpdate() here does double duty: fresh-read
            // re-validation AND requirement 3's connection lock, the
            // SAME shape IntegrationCredentialService::withRefreshLock()
            // already uses.
            $connection = FirmIntegration::query()
                ->where('id', $this->firmIntegrationId)
                ->where('firm_id', $this->firmId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            $cursor = $cursors->firstOrCreate($connection, $this->resourceType, SyncDirection::Inbound);

            if ($cursor->status === CursorStatus::Invalid && $this->retriedRunId === null) {
                // An Incremental run must refuse to claim an Invalid
                // cursor at all — fail closed rather than starting a
                // Pending run for a scope that structurally cannot
                // proceed as an ordinary incremental pull.
                return;
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
                return;
            }

            $this->runBatchLoop($run, $claimedCursor, $connection, $runs, $items, $cursors, $mappings, $conflicts, $registry, $httpClient, $credentials, $healthState, $materializer);
        });
    }

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
        $run = $runs->transitionStatus($run, SyncRunStatus::Running);

        $provider = $registry->get(ProviderKey::from($connection->integrationProvider->code));

        if (! $provider instanceof SupportsPullSyncContract) {
            $runs->transitionStatus($run, SyncRunStatus::Failed, 'provider_does_not_support_pull');
            $cursors->markFailed($cursor->id);

            return;
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
            // summary this method already writes from the catch block
            // below.
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

            return;
        }

        // Decode the JSON-encoded providerContext string back into an
        // array for the provider call below — see this job's
        // constructor docblock (§ post-checkpoint-12 fix) for why the
        // constructor-declared type is ?string rather than ?array. This
        // parameter is only ever set by test code today (frozen
        // design), so a defensive is_array() fallback to [] on
        // malformed input is sufficient — no need to throw.
        $providerContext = [];
        if ($this->providerContext !== null) {
            $decoded = json_decode($this->providerContext, true);
            $providerContext = is_array($decoded) ? $decoded : [];
        }

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider) fix: a real provider's pull() must reach
        // ProviderRequestExecutor::send(), which requires a full
        // FirmIntegration object — the pre-Checkpoint-2 $providerContext
        // (test-only, JSON-encoded scalar bag) never carried one. Merged
        // in unconditionally, after decoding, so any caller-supplied
        // test keys are preserved and 'connection' is always present.
        $providerContext['connection'] = $connection;

        // Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365
        // provider — checkpoint2-design-sync-webhooks.md §1.2)
        // addition: cursor_value is now encrypted at rest;
        // decryptCursorValue() is the transparent decrypt-on-read layer
        // (returns null unchanged for a fresh/invalidated cursor). The
        // provider's pull() still only ever sees a plaintext cursor
        // string, exactly as SupportsPullSyncContract already documents.
        $pageCursor = $cursors->decryptCursorValue($connection, $cursor);
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
            try {
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
                if ($provider instanceof RequiresBillableCallPipelineContract) {
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
                        // already been re-verified Active earlier in
                        // handle(), before this job ever reaches
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
                            (string) $cursor->cursor_version,
                            (string) ($pageCursor ?? 'initial'),
                        ])),
                        provider: $provider,
                        requiredContractFqcn: SupportsPullSyncContract::class,
                    );

                    $page = $result->response;
                } else {
                    $page = $httpClient->execute(fn () => $provider->pull($providerContext, $this->resourceType, $pageCursor), 'pull');
                }
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
                    $cursor = $cursors->invalidate($cursor->id, $cursor->cursor_version);
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

            if (! $batchBlocked) {
                // Checkpoint 2 addition (checkpoint2-design-sync-webhooks.md
                // §1.2): advance() now requires $connection (needed to
                // encrypt $nextCursor per-firm before persisting).
                $cursor = $cursors->advance($connection, $cursor->id, $cursor->cursor_version, $nextCursor);
            } else {
                $sawBlockingFailure = true;
                break;
            }

            $pageCursor = $nextCursor;
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

    private function cancellationRequested(IntegrationSyncRun $run): bool
    {
        return $run->fresh()?->cancel_requested_at !== null;
    }
}
