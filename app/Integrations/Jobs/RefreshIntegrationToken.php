<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\ProviderConnectionService;
use App\Support\TenantAwareJobContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * RefreshIntegrationToken — CHECKPOINT 8 promotion to a real,
 * production-dispatchable ShouldQueue job
 * (agent-8h-architecture-security-review.md §1 item 4 / §2 item 14;
 * agent-8d-token-refresh-concurrency-design.md §2/§14). Was previously
 * a plain, synchronously-invokable wrapper per Checkpoint 5's explicit
 * directive ("Checkpoint 8 owns production dispatch") — this checkpoint
 * is that dispatch wiring, implemented as a direct promotion of this
 * exact class (not a second parallel job class), per the frozen file
 * allowlist's exact filename.
 *
 * Constructor carries two bare, non-secret integer FKs ONLY — never a
 * token, never a credential ID, never a hydrated FirmIntegration model.
 * $firmId is included deliberately (not a violation of "connection ID
 * only"): firm_integrations is FORCE-RLS'd, so a fresh worker process
 * with zero context cannot safely read it to discover which firm owns
 * $firmIntegrationId — that would itself be the "pre-context lookup
 * against a FORCE-RLS table to discover the firm ID needed to read that
 * same table" checkpoint-00 §15 forbids by name.
 *
 * Gate 1 (below, before acquiring any lock) + Gate 2 (inside
 * ProviderConnectionService::refreshConnectionToken()'s withRefreshLock()
 * callback) + the category-split catch block in that same method +
 * markRefreshExhausted() (called only from failed() below) are ONE
 * coherent unit, required together — shipping any subset leaves either
 * the TOCTOU window open or every non-invalid_grant failure
 * permanently un-retryable.
 */
final class RefreshIntegrationToken implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJobContext;

    /**
     * Matches WebhookRetryPolicyService::DEFAULT_RETRY_POLICY['max_attempts'].
     */
    public int $tries = 5;

    public function __construct(
        public readonly int $firmIntegrationId,
        public readonly int $firmId,
    ) {}

    /**
     * Fixed schedule (base_delay_seconds=30, multiplier=2) — reuses
     * WebhookRetryPolicyService's proven formula's shape without calling
     * it (this is Laravel's native backoff() mechanism, a fixed array,
     * not a jitter/category-aware computation — no interaction with
     * BoundedJitter is required here, per agent-8e §5's own note).
     */
    public function backoff(): array
    {
        return [30, 60, 120, 240];
    }

    public function handle(
        ProviderConnectionService $connectionService,
        HealthStateService $healthStateService,
    ): void {
        $this->runInFirmContext($this->firmId, function () use ($connectionService, $healthStateService) {
            $connection = FirmIntegration::query()->find($this->firmIntegrationId);

            if ($connection === null) {
                // Connection deleted since dispatch (e.g. cascade-deleted
                // with its firm) — nothing to do, no error, never
                // counted against $tries.
                return;
            }

            if ((int) $connection->firm_id !== $this->firmId) {
                // Should be structurally impossible once real tenant
                // context is active (RLS would already exclude the row)
                // — kept as an explicit, cheap defense-in-depth
                // assertion, never trusting a single layer alone.
                throw new RuntimeException(
                    "Connection {$this->firmIntegrationId} does not belong to firm {$this->firmId}."
                );
            }

            // GATE 1 (checkpoint-00-final-specification.md §15: "re-
            // resolves the connection fresh from the database as its
            // first action and exits if status is not Active") — before
            // acquiring any lock at all. Silent no-op, never an
            // exception, never counted against $tries. Gate 2 (inside
            // ProviderConnectionService::refreshConnectionToken()'s
            // withRefreshLock() callback) closes the remaining TOCTOU
            // window between this read and the lock's acquisition.
            if ($connection->status !== ConnectionStatus::Active) {
                return;
            }

            // Checkpoint 1 (FirmsVault Live Integrations) addition
            // (checkpoint1-design-http-ratelimit-usage.md §2.6):
            // threads Laravel's own per-job attempt counter into
            // refreshConnectionToken()'s new optional trailing param —
            // available via InteractsWithQueue, already `use`d by this
            // class.
            $result = $connectionService->refreshConnectionToken($connection, $this->attempts());

            if (! $result->successful) {
                // refreshConnectionToken() only ever returns a
                // non-throwing, non-successful result for the
                // invalid_grant-terminal path (swallow-and-transition to
                // ReauthorizationRequired, $result->status ===
                // ReauthorizationRequired) or a Gate 2 no-op ($result->status
                // left at whatever it already was — which, in the exact
                // race this guards against, can ALSO already be
                // ReauthorizationRequired if a different, earlier call
                // already made that transition) — every other (transient)
                // category is rethrown by that method and handled by the
                // catch block below.
                //
                // $result->status alone cannot distinguish "this call's
                // own attempt just failed with invalid_grant" from "this
                // call was a no-op because the connection was already
                // ReauthorizationRequired (or otherwise non-Active) before
                // it started" — both shapes are $successful === false,
                // $status === ReauthorizationRequired. $transitionedThisCall
                // is the explicit discriminator: only call
                // recordCredentialError() for the former, never for a
                // no-op (bugfix, diff-review §5 item 5).
                if ($result->status === ConnectionStatus::ReauthorizationRequired && $result->transitionedThisCall) {
                    $healthStateService->recordCredentialError(
                        $connection->id,
                        $this->firmId,
                        new SanitizedHealthDiagnostic(
                            SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                            SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH,
                        ),
                    );
                }

                // Gate 1/Gate 2/already-fresh no-op: deliberately no
                // health-state call — the mechanism correctly declined to
                // run at all (or already confirmed a fresh, usable
                // credential), this is not a health signal about the
                // refresh mechanism itself, and must never inflate
                // consecutive_failures / reset next_retry_at for a
                // non-event.
                return;
            }

            $healthStateService->recordSuccess($connection->id, $this->firmId);
        });
    }

    /**
     * Reached only once $tries is exhausted for a transient
     * (non-invalid_grant) category — the exception that reaches here is
     * always a SanitizedProviderHttpException of one of those
     * categories, since refreshConnectionToken() only ever rethrows for
     * them (invalid_grant is swallowed internally and never reaches
     * this hook). Runs outside handle()'s own transaction, so tenant
     * context is re-established fresh.
     */
    public function failed(?Throwable $exception): void
    {
        $this->runInFirmContext($this->firmId, function () use ($exception) {
            $connection = FirmIntegration::query()->find($this->firmIntegrationId);

            if ($connection === null || $connection->status !== ConnectionStatus::Active) {
                // Already handled/moved on by the time all retries
                // exhausted (reconnected, disconnected, or already
                // transitioned by a concurrent operation).
                return;
            }

            $category = $exception instanceof SanitizedProviderHttpException
                ? $exception->category()
                : SanitizedProviderHttpException::CATEGORY_UNKNOWN;

            // markRefreshExhausted() keeps ProviderConnectionService the
            // sole writer of firm_integrations.status — this job never
            // writes that column directly.
            app(ProviderConnectionService::class)->markRefreshExhausted($connection, $category);

            app(HealthStateService::class)->recordProviderError(
                $connection->id,
                $this->firmId,
                new SanitizedHealthDiagnostic(
                    SanitizedHealthDiagnostic::CATEGORY_PROVIDER_ERROR,
                    SanitizedHealthDiagnostic::OPERATION_TOKEN_REFRESH,
                ),
            );
        });
    }
}
