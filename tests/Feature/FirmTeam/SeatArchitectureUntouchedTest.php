<?php

declare(strict_types=1);

namespace Tests\Feature\FirmTeam;

use Tests\TestCase;

/**
 * SeatArchitectureUntouchedTest — Firm Feature Manifest §12's flat
 * per-firm seat model was deliberately built as a NEW, separate concern
 * (`FirmSeatCapacityService`) rather than a modification of the
 * existing per-`SeatClass` (Attorney/Staff/ReadOnly) architecture. A
 * fresh source-wide re-scan (done before implementing this feature)
 * confirmed `SeatEnforcementService::usageFor()`/`canInvite()` was the
 * ONLY production consumer of `seat_allocations` data, and that
 * consumer is itself only reachable from
 * `DowngradeEvaluationService::evaluate()` — a read-only computation
 * with no real production caller of its own. This test PROVES, rather
 * than merely asserts in a docblock, that none of the files backing
 * that architecture were modified by this pass: at the moment this
 * test file itself was authored (still uncommitted, in the same
 * working session as the rest of this feature), `git status
 * --porcelain` for each listed path must report nothing — neither a
 * modification to a tracked file nor a new untracked one at that exact
 * path. This mirrors the same "diff the live working tree" technique
 * `EvaluatesHistoricalCheckpointScope`'s own docblock documents as the
 * correct check for a still-in-progress, not-yet-committed change (see
 * that trait's "FALLBACK" section) — this feature's own commit had not
 * yet been created when this test was written and first run.
 */
final class SeatArchitectureUntouchedTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const UNTOUCHED_PATHS = [
        'app/Models/SeatAllocation.php',
        'app/Models/SeatPool.php',
        'app/Services/SeatAllocationService.php',
        'app/Services/SeatPoolService.php',
        'app/Services/SeatEnforcementService.php',
        'app/Enums/SeatClass.php',
        'app/Enums/SeatAllocationStatus.php',
        'app/Enums/SeatPoolStatus.php',
        'app/ValueObjects/SeatUsageSnapshot.php',
    ];

    public function test_the_per_class_seat_architecture_files_were_not_modified(): void
    {
        foreach (self::UNTOUCHED_PATHS as $path) {
            $status = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path())
                .' status --porcelain -- '.escapeshellarg($path)
            ));

            $this->assertSame(
                '',
                $status,
                "Expected '{$path}' to be completely untouched by the flat per-firm seat model addition, but git reports: {$status}",
            );
        }
    }

    /**
     * Complementary positive check: proves the paths above are real,
     * still-existing, tracked files — not merely "absent, so trivially
     * unmodified." A silently-deleted or renamed file would report an
     * empty `git status --porcelain` too if git considered it fully
     * untracked either way, so this asserts each file is still present
     * on disk and still known to git.
     */
    public function test_the_per_class_seat_architecture_files_still_exist_and_are_tracked(): void
    {
        foreach (self::UNTOUCHED_PATHS as $path) {
            $this->assertFileExists(base_path($path), "Expected '{$path}' to still exist.");

            $tracked = trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path())
                .' ls-files --error-unmatch -- '.escapeshellarg($path).' 2>&1'
            ));

            $this->assertSame($path, $tracked, "Expected '{$path}' to be a tracked file known to git.");
        }
    }

    /**
     * FirmSeatCapacityService — the new flat-model service this feature
     * introduced — must never call into any of the per-class seat
     * infrastructure it is deliberately kept separate from. This checks
     * real PHP CODE only (comments/docblocks stripped via
     * token_get_all()) — the class's own docblock legitimately mentions
     * SeatClass/SeatAllocation/etc. by name to EXPLAIN why they are not
     * used, which must not itself trip this check.
     */
    public function test_the_new_flat_capacity_service_does_not_reference_the_per_class_seat_architecture_in_code(): void
    {
        $codeOnly = $this->stripCommentsAndDocblocks(app_path('Services/FirmSeatCapacityService.php'));

        foreach (['SeatAllocation', 'SeatPool', 'SeatClass', 'SeatEnforcementService'] as $forbiddenToken) {
            $this->assertStringNotContainsString(
                $forbiddenToken,
                $codeOnly,
                "FirmSeatCapacityService must never reference {$forbiddenToken} in real code (outside comments/docblocks).",
            );
        }
    }

    /**
     * Same real-code-only check for FirmUserInvitationService::invite()
     * — this feature rewired it away from SeatEnforcementService onto
     * FirmSeatCapacityService, and it must not have kept a stray
     * reference to the old per-class check.
     */
    public function test_the_invitation_service_does_not_reference_the_per_class_seat_architecture_in_code(): void
    {
        $codeOnly = $this->stripCommentsAndDocblocks(app_path('Services/FirmUserInvitationService.php'));

        foreach (['SeatAllocation', 'SeatPool', 'SeatClass', 'SeatEnforcementService'] as $forbiddenToken) {
            $this->assertStringNotContainsString(
                $forbiddenToken,
                $codeOnly,
                "FirmUserInvitationService must never reference {$forbiddenToken} in real code (outside comments/docblocks).",
            );
        }
    }

    private function stripCommentsAndDocblocks(string $path): string
    {
        $source = file_get_contents($path);
        $this->assertNotFalse($source, "Could not read {$path}");

        $tokens = token_get_all($source);
        $codeOnly = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $codeOnly .= $token[1];
            } else {
                $codeOnly .= $token;
            }
        }

        return $codeOnly;
    }
}
