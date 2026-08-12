<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\ConflictCheckService;
use App\Services\TenantContextService;
use Illuminate\Support\Collection;

/**
 * MarketplaceIntakeConflictCheckService — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 8. The pre-conversion,
 * pre-Matter conflict signal: no Party/Client/Matter rows exist for a
 * prospect yet (that only happens at conversion, checkpoint 11), so
 * this is deliberately lighter than the existing Matter-level
 * ConflictCheckService::run() — it never persists a
 * ConflictCheckRun/ConflictCheckResult row, and it only ever gates
 * this intake's own status (UnderReview <-> ConflictReviewRequired),
 * never a Matter's open/closed state.
 *
 * Opposing-party names are captured as an ordinary Textarea-typed
 * intake question under the reserved question_code
 * OPPOSING_PARTIES_QUESTION_CODE (a Firm's own template may or may not
 * include this question — a template with no such question simply
 * never has anything to check, matching this mission's existing
 * "the browser/AI is never authoritative, and a missing signal is
 * never treated as a false positive" convention). Split into individual
 * names the same way RunConflictCheckAction already parses its own
 * free-text opposing-party field, so both surfaces treat "one name per
 * line" identically.
 *
 * Every call here is an EXPLICIT Firm action, mirroring
 * MatterOpeningService::requestConflictCheck()'s own "a human/service
 * deliberately triggers this, never automatic" precedent — nothing in
 * MarketplaceIntakeService's own transitions (markUnderReview(), etc.)
 * calls into this class implicitly.
 */
class MarketplaceIntakeConflictCheckService
{
    public const OPPOSING_PARTIES_QUESTION_CODE = 'opposing_parties';

    public function __construct(
        private readonly ConflictCheckService $conflictCheck,
        private readonly MarketplaceIntakeService $intakeService = new MarketplaceIntakeService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * Read-only — never mutates the intake. Firm-context-only: the
     * matched entity's name/type/id is confidential existing-client
     * data and must never reach the anonymous visitor's own side of
     * this intake.
     *
     * @return Collection<int, array{type: string, id: int, value: string}>
     */
    public function possibleMatches(Firm $firm, MarketplaceIntake $intake): Collection
    {
        $this->assertBelongsToFirm($firm, $intake);

        $names = $this->opposingPartyNames($intake);

        if ($names === []) {
            return collect();
        }

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn () => $this->conflictCheck->searchForPossibleMatches($firm, $names),
        );
    }

    /**
     * Runs possibleMatches() and, if anything was found, transitions
     * the intake to ConflictReviewRequired via
     * MarketplaceIntakeService::markConflictReviewRequired() (the sole
     * writer of marketplace_intake_events — this class never writes
     * one directly). Returns the intake untouched (still UnderReview)
     * when nothing was found — a clean intake is never forced through
     * an extra transition it doesn't need.
     *
     * @throws \RuntimeException if the intake is not currently UnderReview.
     */
    public function evaluate(Firm $firm, MarketplaceIntake $intake, ?FirmUser $actor = null): MarketplaceIntake
    {
        $this->assertBelongsToFirm($firm, $intake);

        // Enforced here explicitly, not left to only be caught inside
        // markConflictReviewRequired() below — a zero-match intake
        // (e.g. no opposing_parties answer at all) must still reject a
        // non-UnderReview intake rather than silently no-op-ing and
        // returning it untouched.
        if ($intake->status !== MarketplaceIntakeStatus::UnderReview) {
            throw new \RuntimeException('Only an UnderReview intake can be evaluated for conflicts.');
        }

        $matches = $this->possibleMatches($firm, $intake);

        if ($matches->isEmpty()) {
            return $intake;
        }

        return $this->intakeService->markConflictReviewRequired($firm, $intake, $actor, $matches->count());
    }

    /**
     * @return array<int, string>
     */
    private function opposingPartyNames(MarketplaceIntake $intake): array
    {
        $raw = $intake->structured_data[self::OPPOSING_PARTIES_QUESTION_CODE] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\R/', $raw) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '',
        ));
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
