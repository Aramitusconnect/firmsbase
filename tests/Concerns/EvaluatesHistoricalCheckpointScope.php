<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * EvaluatesHistoricalCheckpointScope — the single shared source of truth
 * for every historical RLS-rollout / Governance "checkpoint scope
 * firewall" test's own `changedOrUntrackedPaths()`-equivalent check.
 *
 * BACKGROUND. Roughly a hundred test files under
 * tests/Feature/Security/RlsForceRollout and tests/Feature/Governance —
 * each written by a different, already-completed, already-committed
 * historical "checkpoint" or "section" — independently copy-pasted a
 * private helper (or inlined the same git call directly in a test
 * method body) that asked:
 *
 *     git ls-files --modified --others --exclude-standard -- <scope>
 *
 * i.e. "what does the LIVE, CURRENT, UNCOMMITTED working tree differ
 * from HEAD by, right now, under <scope>?" That question only has the
 * intended answer at the moment the checkpoint that asks it is itself
 * being authored — when the live tree genuinely contains nothing but
 * that checkpoint's own in-progress work. Once that checkpoint's own
 * commit lands, the check keeps re-running forever afterward against an
 * ever-changing present it was never designed to see: every later,
 * wholly unrelated uncommitted change in the working tree (for example
 * a completely different, independently reviewed mission's ~111
 * in-progress Firm Workspace files) makes EVERY EARLIER checkpoint's own
 * "did I touch anything outside my declared scope" firewall fail, even
 * though that checkpoint's own historical commit never touched anything
 * outside its declared scope.
 *
 * THE FIX. A checkpoint test file that is itself already committed to
 * this branch's history has a real, discoverable "introducing commit" —
 * the commit that first added that exact test file. Per this repo's own
 * established convention, that commit also carries that checkpoint's own
 * production changes (the migration, factory/service tweaks, etc.) — see
 * `resolveOwnIntroducingCommit()`'s own docblock for how that commit is
 * found. Diffing THAT COMMIT against its parent, scoped to the same
 * `$scope` string the caller already passes, is a fixed, immutable,
 * historical fact — it can never again be perturbed by later, unrelated
 * uncommitted work, because it does not look at the working tree at all.
 *
 * FALLBACK. If a calling test class's own file has no introducing commit
 * yet (i.e. it is itself still uncommitted — someone is actively writing
 * a NEW checkpoint test right now, before its own merge), this trait
 * falls back to the ORIGINAL live-working-tree behavior
 * (`git ls-files --modified --others --exclude-standard`). That is the
 * genuine, still-valid use case the original check was designed for: an
 * in-progress checkpoint whose own commit does not exist yet has no
 * historical commit to diff against, so the only meaningful question
 * left to ask IS "what does the live tree currently differ by" — exactly
 * what the original, single-checkpoint-lifetime version of this check
 * always answered correctly.
 *
 * WHAT THIS TRAIT DOES NOT DO. It does not change what counts as
 * "in scope" or "out of scope" for any checkpoint — every calling test
 * class keeps its own allowlist constant, its own array_diff() step, its
 * own docblocks, and its own assertions untouched. This trait only
 * changes the SOURCE the raw (unfiltered) changed-path list is computed
 * from.
 */
trait EvaluatesHistoricalCheckpointScope
{
    /**
     * Drop-in replacement for the raw
     * `trim((string) shell_exec('git ... ls-files --modified --others
     * --exclude-standard -- '.escapeshellarg($scope)))` expression every
     * affected test file used to compute inline. Returns the exact same
     * SHAPE (a trimmed, newline-joined string of paths relative to
     * base_path(), one per line, empty string when nothing changed) so
     * every existing downstream trim()/preg_split()/array_diff()/
     * assertSame('', ...) call site keeps working completely unmodified.
     *
     * Sourced from the calling test class's own historical introducing
     * commit when one exists; falls back to the live working tree
     * otherwise (see this trait's own top-level docblock).
     */
    protected function changedOrUntrackedPathsRaw(string $scope): string
    {
        $introducingCommit = $this->resolveOwnIntroducingCommit();

        if ($introducingCommit === null) {
            return $this->changedOrUntrackedPathsAgainstLiveWorkingTree($scope);
        }

        return $this->changedPathsInHistoricalCommit($introducingCommit, $scope);
    }

    /**
     * Resolves the OLDEST commit that first added the calling test
     * class's own file — i.e. that checkpoint's own historical
     * "introducing commit" — or null if the file has no commit yet
     * (still uncommitted).
     *
     * Deliberately does NOT use `git log --follow`: this repo's
     * checkpoint test files are never renamed or moved (verified via
     * `git log --diff-filter=R` across both directories — zero
     * results), and `--follow`'s content-similarity rename heuristic
     * produces WRONG results here in practice, because these files share
     * large amounts of near-identical boilerplate (the same allowlist
     * constant, the same docblock phrasing) copy-pasted checkpoint to
     * checkpoint — confirmed empirically: for at least 3 of the 5 files
     * sampled while designing this trait, `--follow` walked history
     * across a completely different, unrelated file's own addition
     * commit purely because of that shared boilerplate, while the plain
     * (non-`--follow`) form correctly resolved each file's own real
     * introducing commit every time.
     */
    protected function resolveOwnIntroducingCommit(): ?string
    {
        $reflection = new \ReflectionClass(static::class);
        $absolutePath = $reflection->getFileName();

        if ($absolutePath === false) {
            return null;
        }

        return $this->resolveIntroducingCommitForPath($this->pathRelativeToBase($absolutePath));
    }

    /**
     * Extracted from resolveOwnIntroducingCommit() so it can be exercised
     * directly, against an arbitrary already-known relative path, by
     * EvaluatesHistoricalCheckpointScopeTest — proving the git resolution
     * logic itself is correct against real, known checkpoint commits
     * without depending on reflection identity tricks.
     */
    protected function resolveIntroducingCommitForPath(string $relativePath): ?string
    {
        $output = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path())
            .' log --diff-filter=A --format=%H -- '.escapeshellarg($relativePath)
        ));

        if ($output === '') {
            return null;
        }

        $commits = array_values(array_filter(preg_split('/\R/', $output) ?: []));

        if ($commits === []) {
            return null;
        }

        // `git log` lists newest-first; the introducing commit is the
        // OLDEST (last) entry — the first time this exact path was ever
        // added.
        return end($commits);
    }

    /**
     * Diffs a single historical commit against its parent, scoped to
     * $scope, returning the same trimmed/newline-joined string shape as
     * the original live-tree shell_exec() call.
     */
    protected function changedPathsInHistoricalCommit(string $commit, string $scope): string
    {
        return trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path())
            .' diff-tree --no-commit-id --name-only -r '.escapeshellarg($commit)
            .' -- '.escapeshellarg($scope)
        ));
    }

    /**
     * The ORIGINAL behavior, preserved verbatim as the fallback for a
     * checkpoint test file that is not yet committed (see this trait's
     * own top-level docblock).
     */
    protected function changedOrUntrackedPathsAgainstLiveWorkingTree(string $scope): string
    {
        return trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path())
            .' ls-files --modified --others --exclude-standard -- '.escapeshellarg($scope)
        ));
    }

    /**
     * Reflection gives an absolute path; every downstream consumer of
     * these paths (allowlist constants, git pathspecs) works in
     * base_path()-relative terms.
     */
    protected function pathRelativeToBase(string $absolutePath): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($absolutePath, $base)
            ? substr($absolutePath, strlen($base))
            : $absolutePath;
    }
}
