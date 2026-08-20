<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\OpenAi\OpenAiProviderException;
use App\Enums\AiUsageActionType;
use App\Models\Firm;
use App\Models\User;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderConnectionTestResult;

/**
 * "Test Connection" — proves the firm's stored credential actually reaches
 * OpenAI, before a prospect's intake depends on it.
 *
 * Without this, a firm learns its key is wrong from a silently degraded
 * intake. The request deliberately walks the SAME path a real turn walks —
 * firm → mode → active credential → resolver → adapter → Responses API — so a
 * pass here means the real path works, not that a parallel diagnostic path
 * works.
 *
 * What is sent: a fixed diagnostic string. No client data, no matter data, no
 * intake data, no prospect text, and no schema. Nothing about the firm's
 * clients leaves the system to satisfy a settings-page button.
 *
 * What is returned: a closed vocabulary of codes and firm-facing messages.
 * Provider response bodies are never surfaced — OpenAiFailureReason already
 * classifies 401/403/404/429/5xx/timeout/malformed into secret-free copy, and
 * this service reuses that taxonomy rather than inventing a second one.
 */
final readonly class AiProviderConnectionTestService
{
    /**
     * The entire user-role content of a connection test. Fixed, so it cannot
     * accidentally grow to include firm or client context.
     */
    private const PROBE_TEXT = 'ping';

    private const PROBE_INSTRUCTION = 'FirmsVault connection test. Reply with the single word: ok.';

    public function __construct(
        private AiProviderResolver $resolver,
        private AiBudgetEnforcementService $budgetEnforcement,
        private AiUsageRecorderService $usageRecorder,
        private TenantContextService $tenantContext,
    ) {}

    public function test(Firm $firm, ?User $actor = null): AiProviderConnectionTestResult
    {
        $provider = $this->resolver->providerFor($firm);

        if ($provider === null) {
            return AiProviderConnectionTestResult::failure(
                'not_firm_owned',
                'AI is not set to Firm Owned for this firm, so there is no credential to test.',
            );
        }

        if (! $this->resolver->hasActiveCredential($firm)) {
            // Covers both "never added" and "revoked/rotated away" — from the
            // firm's point of view there is nothing active to test either way,
            // and the settings page shows the credential's own status
            // separately.
            return AiProviderConnectionTestResult::failure(
                'no_active_credential',
                'This firm has no active API key. Add one before testing the connection.',
            );
        }

        $adapter = $this->resolver->adapterFor($firm);

        if ($adapter === null) {
            return AiProviderConnectionTestResult::failure(
                'no_active_credential',
                'This firm has no active API key. Add one before testing the connection.',
            );
        }

        $model = $adapter->model();

        // A firm at its ceiling must not be able to spend more by clicking a
        // diagnostic button repeatedly. Same conservative reservation the
        // intake path uses.
        [$periodStartsAt, $periodEndsAt] = $this->currentPeriod();

        $reservedTokens = (int) ceil(
            mb_strlen(self::PROBE_INSTRUCTION.self::PROBE_TEXT) / max(1, (int) config('ai.openai.estimated_chars_per_token'))
        ) + $adapter->maxOutputTokens();

        $budget = $this->tenantContext->runWithFirmContextWithoutTransaction(
            $firm,
            fn () => $this->budgetEnforcement->checkFirmBudget($firm, $reservedTokens, 0, $periodStartsAt, $periodEndsAt),
        );

        if (! $budget->allowed()) {
            return AiProviderConnectionTestResult::failure(
                'budget_exceeded',
                'This firm has reached its AI budget for the current period. Raise the limit before testing the connection.',
                $model,
            );
        }

        $request = new AiPromptRequest(
            provider: $adapter->provider(),
            model: $model,
            actionType: AiUsageActionType::ConnectionTest,
            instructionText: self::PROBE_INSTRUCTION,
            documentDerivedText: self::PROBE_TEXT,
            matterIds: [],
            allowToolActions: false,
            responseSchemaKey: null,
        );

        try {
            $response = $adapter->generate($request);
        } catch (OpenAiProviderException $e) {
            // Already classified and secret-free.
            return AiProviderConnectionTestResult::failure($e->reason->value, $e->getMessage(), $model, $e->status);
        } catch (\Throwable) {
            // Anything unclassified stays unclassified on the way out: an
            // arbitrary exception message can contain the request, and the
            // request carries the Authorization header.
            return AiProviderConnectionTestResult::failure(
                'provider_error',
                'The connection test could not be completed. Nothing was changed.',
                $model,
            );
        }

        // The probe really did spend tokens, so it is recorded against the same
        // budget everything else is. Recording is best-effort on purpose: the
        // connection demonstrably worked, and a bookkeeping failure must not be
        // reported to the firm as a failed credential.
        if ($actor !== null) {
            try {
                $this->usageRecorder->record($firm, $actor, $request, $response);
            } catch (\Throwable) {
                // Intentionally swallowed — see above.
            }
        }

        return AiProviderConnectionTestResult::success($model);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function currentPeriod(): array
    {
        return [
            new \DateTimeImmutable('first day of this month 00:00:00'),
            new \DateTimeImmutable('last day of this month 23:59:59'),
        ];
    }
}
