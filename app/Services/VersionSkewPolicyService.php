<?php

namespace App\Services;

use App\ValueObjects\VersionSkewCheckResult;

/**
 * VersionSkewPolicyService — enforces "at most one minor version
 * behind SaaS, same major version" (project rule 10 / approved
 * design). A DIFFERENT major version fails unconditionally, regardless
 * of minor distance ("different major fails unless explicitly treated
 * as invalid" — this service treats it as invalid, full stop, since no
 * caller in Phase 16 asks for a different policy). An instance AHEAD
 * of the SaaS release (negative skew) is also treated as failing — a
 * dedicated/private instance should never be newer than the SaaS
 * release it tracks.
 */
class VersionSkewPolicyService
{
    private const MAX_MINOR_VERSIONS_BEHIND = 1;

    public function check(string $instanceVersion, string $saasVersion): VersionSkewCheckResult
    {
        [$instanceMajor, $instanceMinor] = $this->parse($instanceVersion);
        [$saasMajor, $saasMinor] = $this->parse($saasVersion);

        if ($instanceMajor !== $saasMajor) {
            return VersionSkewCheckResult::fail(
                "Major version mismatch: instance is {$instanceMajor}.x, SaaS is {$saasMajor}.x."
            );
        }

        $minorsBehind = $saasMinor - $instanceMinor;

        if ($minorsBehind < 0) {
            return VersionSkewCheckResult::fail(
                "Instance version {$instanceVersion} is ahead of SaaS version {$saasVersion}.",
                $minorsBehind,
            );
        }

        if ($minorsBehind > self::MAX_MINOR_VERSIONS_BEHIND) {
            return VersionSkewCheckResult::fail(
                "Instance is {$minorsBehind} minor versions behind SaaS; maximum allowed is ".self::MAX_MINOR_VERSIONS_BEHIND.'.',
                $minorsBehind,
            );
        }

        return VersionSkewCheckResult::pass($minorsBehind);
    }

    /**
     * @return array{0: int, 1: int} [major, minor]
     */
    private function parse(string $version): array
    {
        $parts = explode('.', $version);

        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            throw new \InvalidArgumentException("Version string '{$version}' must be in major.minor(.patch) format.");
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
