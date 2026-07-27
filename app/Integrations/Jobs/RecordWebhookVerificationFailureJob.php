<?php

declare(strict_types=1);

namespace App\Integrations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * RecordWebhookVerificationFailureJob — CHECKPOINT 1 addition
 * (FirmsVault Live Integrations, checkpoint1-design-health-sandbox.md
 * §A.3.3; checkpoint1-combined-design.md §2.1; checkpoint1-security-review.md
 * Finding 5). The ONLY writer of `integration_webhook_verification_failures`
 * (a plain `DB::table()` write, no Eloquent model — this table is a
 * write-only, no-RLS, platform-owned counter table, mirroring how
 * App\Integrations\Models\IntegrationWebhookReceipt's own table is
 * handled, minus the model layer since nothing here ever needs to read
 * a single row back).
 *
 * REQUIRED (security review Finding 5): App\Integrations\Http\Controllers\InboundWebhookController
 * dispatches this job from every rejection branch that returns the
 * collapsed `401 {"status":"rejected"}` response (plus the malformed-
 * payload `400` branch) — but NEVER performs a synchronous, blocking
 * `INSERT` directly on that timing-critical request path itself. This
 * job carries the actual database write off of the request/response
 * cycle entirely, so this table's write latency can never regress
 * InboundWebhookTimingInvarianceTest's response-time-invariance
 * guarantee.
 *
 * $providerCode/$failureReason are both plain, non-secret, closed-
 * vocabulary strings (the same rejection-reason vocabulary
 * InboundWebhookController already branches on internally) — never a
 * raw header, token, signature, or request body fragment. See
 * database/migrations/..._create_integration_webhook_verification_failures_table.php
 * for the exact closed set enforced by this table's own CHECK
 * constraint (this job additionally validates client-side, so a bad
 * value fails loudly in the job's own failed() hook rather than as an
 * opaque database constraint violation).
 */
final class RecordWebhookVerificationFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Mirrors the exact closed set enforced by this table's own CHECK
     * constraint — see this table's create migration.
     *
     * @var string[]
     */
    public const VALID_FAILURE_REASONS = [
        'signature_mismatch',
        'missing_headers',
        'malformed_payload',
        'unknown_routing_token',
        'disconnected_event_rejected',
    ];

    public int $tries = 3;

    public function __construct(
        public readonly string $providerCode,
        public readonly string $failureReason,
    ) {
        if (! in_array($failureReason, self::VALID_FAILURE_REASONS, true)) {
            throw new InvalidArgumentException(
                "RecordWebhookVerificationFailureJob refuses unknown failure reason '{$failureReason}' — ".
                'only the closed set in self::VALID_FAILURE_REASONS may be recorded.'
            );
        }
    }

    public function handle(): void
    {
        DB::table('integration_webhook_verification_failures')->insert([
            'provider_code' => $this->providerCode,
            'failure_reason' => $this->failureReason,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RecordWebhookVerificationFailureJob: failed to record a webhook verification failure.', [
            'provider_code' => $this->providerCode,
            'failure_reason' => $this->failureReason,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
