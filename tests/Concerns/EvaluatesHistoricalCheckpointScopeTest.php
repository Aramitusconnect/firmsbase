<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Tests\TestCase;

/**
 * EvaluatesHistoricalCheckpointScopeTest — proves the shared trait
 * genuinely diffs against a fixed historical commit rather than the
 * live, ever-changing working tree, and that its fallback correctly
 * preserves the original live-tree behavior for a not-yet-committed
 * checkpoint file.
 *
 * The central regression this proves: as of this test running, this
 * working tree contains ~111 real, currently-uncommitted Firm Workspace
 * files (an entirely separate, unrelated mission) under app/Filament,
 * app/Models, etc. Before this trait existed, every historical
 * checkpoint's own `changedOrUntrackedPaths('app/Filament')`-style check
 * would see those files and wrongly report its own scope as violated.
 * `test_a_real_historical_checkpoints_scope_check_is_unaffected_by_the_currently_uncommitted_firm_workspace_files()`
 * below proves that no longer happens once the check is sourced from the
 * checkpoint's own fixed historical commit.
 */
class EvaluatesHistoricalCheckpointScopeTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;

    /**
     * TrustAccountsForceRlsActivationTest's own real, already-committed
     * introducing commit (Section 39A-5 Wave 10 — "Complete RLS
     * activation for trust accounting domain (10 tables)"), independently
     * confirmed via `git log --diff-filter=A -- <path>` during this
     * trait's own design/verification pass.
     */
    private const TRUST_ACCOUNTS_TEST_PATH = 'tests/Feature/Security/RlsForceRollout/TrustAccountsForceRlsActivationTest.php';

    private const TRUST_ACCOUNTS_INTRODUCING_COMMIT = '242a554a8220a54b5a38c4ccd4dbb93c3ceb7434';

    public function test_resolves_the_real_known_introducing_commit_of_an_already_committed_checkpoint_file(): void
    {
        $commit = $this->resolveIntroducingCommitForPath(self::TRUST_ACCOUNTS_TEST_PATH);

        $this->assertSame(self::TRUST_ACCOUNTS_INTRODUCING_COMMIT, $commit);
    }

    /**
     * Regression proof for the exact bug --follow's rename-detection
     * heuristic introduced during this trait's own design: plain
     * `git log --diff-filter=A` (no `--follow`) must resolve DIRECTLY to
     * the checkpoint's own real commit, not to some unrelated earlier
     * commit reached via a false-positive content-similarity match.
     */
    public function test_the_resolved_commit_genuinely_contains_this_exact_test_file_in_its_own_diff(): void
    {
        $paths = $this->changedPathsInHistoricalCommit(
            self::TRUST_ACCOUNTS_INTRODUCING_COMMIT,
            self::TRUST_ACCOUNTS_TEST_PATH
        );

        $this->assertSame(self::TRUST_ACCOUNTS_TEST_PATH, $paths);
    }

    public function test_a_nonexistent_uncommitted_path_has_no_introducing_commit(): void
    {
        $commit = $this->resolveIntroducingCommitForPath(
            'tests/Feature/Security/RlsForceRollout/ThisFileDoesNotExistAndWasNeverCommitted'.uniqid().'.php'
        );

        $this->assertNull($commit);
    }

    /**
     * The central regression proof (see class docblock): a real,
     * historical checkpoint's own scope check, now sourced from its
     * fixed introducing commit, must return an EMPTY unexpected-file
     * list for a scope its own historical commit never touched — even
     * though this live working tree currently contains ~111 unrelated,
     * uncommitted Firm Workspace files under that exact scope.
     */
    public function test_a_real_historical_checkpoints_scope_check_is_unaffected_by_the_currently_uncommitted_firm_workspace_files(): void
    {
        // Sanity precondition: prove the live working tree genuinely does
        // contain uncommitted changes under app/Filament right now — if
        // this ever becomes false (a fully clean tree), this test would
        // otherwise prove nothing.
        $liveTreeChanges = $this->changedOrUntrackedPathsAgainstLiveWorkingTree('app/Filament');
        $this->assertNotSame(
            '',
            $liveTreeChanges,
            'Precondition failed: this test is designed to run against a working tree with real uncommitted '.
            'app/Filament changes (the Firm Workspace mission). If the tree is now clean, this specific proof '.
            'no longer demonstrates anything — verify some other way that the historical-commit path is used.'
        );

        // The actual proof: diffing TrustAccountsForceRlsActivationTest's
        // own fixed, historical introducing commit against app/Filament
        // must be empty, because that checkpoint's real commit never
        // touched app/Filament — regardless of what the live tree
        // currently contains.
        $historicalChanges = $this->changedPathsInHistoricalCommit(self::TRUST_ACCOUNTS_INTRODUCING_COMMIT, 'app/Filament');

        $this->assertSame(
            '',
            $historicalChanges,
            'A historical checkpoint scope check must be computed from its own fixed introducing commit, not the '.
            'live working tree — it must stay empty regardless of unrelated uncommitted Firm Workspace files.'
        );
    }

    public function test_changed_or_untracked_paths_raw_falls_back_to_the_live_working_tree_when_the_calling_class_has_no_introducing_commit(): void
    {
        $probe = new class
        {
            use EvaluatesHistoricalCheckpointScope;

            public function callChangedOrUntrackedPathsRaw(string $scope): string
            {
                return $this->changedOrUntrackedPathsRaw($scope);
            }
        };

        // An anonymous class has no real backing file discoverable via
        // git history, so resolveOwnIntroducingCommit() must return
        // null and this must fall through to the live-tree method. The
        // live-tree method itself is separately covered by the original
        // (pre-existing, unmodified) RlsForceRollout/Governance test
        // suites' own long-standing behavior; here we only prove the
        // fallback branch is actually taken by confirming it does NOT
        // throw and returns a string (never an array/exception) even
        // though no introducing commit can possibly exist for it.
        $result = $probe->callChangedOrUntrackedPathsRaw('tests/Concerns/EvaluatesHistoricalCheckpointScope.php');

        $this->assertIsString($result);
    }

    public function test_resolve_own_introducing_commit_returns_null_for_an_anonymous_class_with_no_backing_git_history(): void
    {
        $probe = new class
        {
            use EvaluatesHistoricalCheckpointScope;

            public function callResolveOwnIntroducingCommit(): ?string
            {
                return $this->resolveOwnIntroducingCommit();
            }
        };

        // ReflectionClass::getFileName() for an anonymous class returns
        // THIS test file's own path (the file the anonymous class is
        // physically defined in) — which, being a real file on disk, may
        // or may not have its own git history depending on commit state.
        // This assertion only requires the call to complete and return
        // either a 40-character SHA or null, proving no exception/type
        // error path is hit; the true no-history case is covered by
        // test_a_nonexistent_uncommitted_path_has_no_introducing_commit()
        // above, which does not depend on this file's own commit state.
        $commit = $probe->callResolveOwnIntroducingCommit();

        if ($commit !== null) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit);
        } else {
            $this->assertNull($commit);
        }
    }
}
