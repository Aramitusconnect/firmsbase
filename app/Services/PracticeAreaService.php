<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaCanonicalizationService;
use App\Services\Configuration\PracticeAreaDependencyAnalysisService;
use App\ValueObjects\Configuration\PracticeAreaDuplicateCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PracticeAreaService — the only place `practice_areas` rows are
 * created or edited (mirrors PlanService's own "the only place Plan
 * rows are created or have their lifecycle status changed" discipline).
 * PracticeArea is GLOBAL platform reference data (no firm_id, no RLS —
 * see that model's own docblock), edited by platform admins only.
 *
 * Deactivation is a soft state flip (`is_active = false`), never a
 * hard delete — a practice area already referenced by a Matter, a
 * FirmPracticeArea enablement row, or a TemplatePack must remain a
 * valid foreign key target forever; only the catalog UI ceases to
 * offer it for NEW selections (MatterCreationService's/AddMatterAction's
 * `->where('is_active', true)` filters already enforce that).
 *
 * CONFIGURATION CONTROL PLANE (mission sections 28/33) additions —
 * both enforced HERE, at the canonical service, not only in the
 * Filament action, so no future call site can bypass them:
 *
 *   DUPLICATE GOVERNANCE. The DB's own `code` unique index only stops
 *   byte-identical codes; it happily accepts `civil-litigation`
 *   alongside `civil_litigation`, which is exactly how this catalog
 *   accumulated its current duplicate pairs. create()/update() now
 *   consult PracticeAreaCanonicalizationService and REFUSE a write
 *   that normalizes onto an existing practice area unless the caller
 *   supplies an explicit override reason — the same
 *   detect → require-justification → audit shape
 *   DirectoryFirmAdministrationService::create() already uses for
 *   marketplace listings, deliberately reused rather than reinvented.
 *   Uniqueness constraints are never weakened to permit the override.
 *
 *   CANONICAL CODE SAFETY. `code` is the stable identity other tables
 *   point at. update() refuses to change it once the practice area has
 *   real references, because no canonical rename/migration service
 *   exists in this codebase to carry those references across.
 */
class PracticeAreaService
{
    private const AUDIT_CATEGORY = 'practice_area_catalog';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
        private readonly PracticeAreaCanonicalizationService $canonicalization = new PracticeAreaCanonicalizationService,
        private readonly PracticeAreaDependencyAnalysisService $dependencies = new PracticeAreaDependencyAnalysisService,
    ) {}

    /**
     * @param  ?string  $duplicateOverrideReason  Required only when the proposed values normalize onto an existing practice area. Recorded in the audit trail.
     */
    public function create(array $attributes, ?PlatformAdmin $actor = null, ?string $duplicateOverrideReason = null): PracticeArea
    {
        $code = $attributes['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            throw new InvalidArgumentException('A practice area code is required.');
        }

        $this->assertCodeIsUnique($code);

        $candidates = $this->canonicalization->duplicateCandidatesFor(
            name: is_string($attributes['name'] ?? null) ? $attributes['name'] : null,
            code: $code,
            slug: is_string($attributes['slug'] ?? null) ? $attributes['slug'] : null,
            aliases: is_array($attributes['synonyms'] ?? null) ? array_values(array_filter($attributes['synonyms'], 'is_string')) : [],
        );

        $this->assertDuplicatesAcknowledged($candidates, $duplicateOverrideReason);

        $practiceArea = DB::transaction(fn (): PracticeArea => PracticeArea::create(array_merge(
            ['is_active' => true],
            $attributes,
        )));

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_created',
                self::AUDIT_CATEGORY,
                array_filter([
                    'practice_area_id' => $practiceArea->id,
                    'code' => $practiceArea->code,
                    // Only present when the operator deliberately created
                    // past a detected duplicate — an exceptional action,
                    // audited as such (mission section 28).
                    'duplicate_override_reason' => $candidates->isNotEmpty() ? $duplicateOverrideReason : null,
                    'duplicate_candidate_ids' => $candidates->isNotEmpty()
                        ? $candidates->map(fn ($c): int => $c->practiceArea->id)->all()
                        : null,
                ], fn ($value) => $value !== null),
            );
        }

        return $practiceArea;
    }

    /**
     * @param  ?string  $duplicateOverrideReason  Required only when the proposed values normalize onto a DIFFERENT existing practice area.
     */
    public function update(
        PracticeArea $practiceArea,
        array $attributes,
        ?PlatformAdmin $actor = null,
        ?string $duplicateOverrideReason = null,
    ): PracticeArea {
        $codeChanged = false;

        if (array_key_exists('code', $attributes)) {
            $newCode = $attributes['code'];

            if (! is_string($newCode) || trim($newCode) === '') {
                throw new InvalidArgumentException('A practice area code is required.');
            }

            if (strcasecmp($newCode, $practiceArea->code ?? '') !== 0) {
                $this->assertCodeIsUnique($newCode, excludingId: $practiceArea->id);
                $this->assertCodeMayBeChanged($practiceArea);
                $codeChanged = true;
            }
        }

        $candidates = $this->canonicalization->duplicateCandidatesFor(
            name: array_key_exists('name', $attributes) && is_string($attributes['name']) ? $attributes['name'] : $practiceArea->name,
            code: array_key_exists('code', $attributes) && is_string($attributes['code']) ? $attributes['code'] : $practiceArea->code,
            slug: array_key_exists('slug', $attributes) && is_string($attributes['slug']) ? $attributes['slug'] : $practiceArea->slug,
            aliases: array_key_exists('synonyms', $attributes) && is_array($attributes['synonyms'])
                ? array_values(array_filter($attributes['synonyms'], 'is_string'))
                : $this->canonicalization->aliasesOf($practiceArea),
            excludingId: $practiceArea->id,
        );

        $this->assertDuplicatesAcknowledged($candidates, $duplicateOverrideReason);

        $updated = DB::transaction(fn (): PracticeArea => tap($practiceArea)->update($attributes)->fresh());

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_updated',
                self::AUDIT_CATEGORY,
                array_filter([
                    'practice_area_id' => $updated->id,
                    'changed_fields' => array_keys($attributes),
                    'canonical_code_changed' => $codeChanged ?: null,
                    'duplicate_override_reason' => $candidates->isNotEmpty() ? $duplicateOverrideReason : null,
                    'duplicate_candidate_ids' => $candidates->isNotEmpty()
                        ? $candidates->map(fn ($c): int => $c->practiceArea->id)->all()
                        : null,
                ], fn ($value) => $value !== null),
            );
        }

        return $updated;
    }

    public function activate(PracticeArea $practiceArea, ?PlatformAdmin $actor = null): PracticeArea
    {
        $activated = tap($practiceArea)->update(['is_active' => true])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_activated',
                self::AUDIT_CATEGORY,
                ['practice_area_id' => $activated->id],
            );
        }

        return $activated;
    }

    /**
     * @param  ?string  $reason  Operator justification, folded into the existing audit row rather than opening a second write path.
     */
    public function deactivate(PracticeArea $practiceArea, ?PlatformAdmin $actor = null, ?string $reason = null): PracticeArea
    {
        $deactivated = tap($practiceArea)->update(['is_active' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_deactivated',
                self::AUDIT_CATEGORY,
                array_filter([
                    'practice_area_id' => $deactivated->id,
                    'code' => $deactivated->code,
                    'reason' => $reason,
                ], fn ($value) => $value !== null),
            );
        }

        return $deactivated;
    }

    /**
     * Refuses a write that normalizes onto an existing practice area
     * unless the caller supplied an explicit justification. The
     * exception message carries the EVIDENCE (which record, and what
     * matched) rather than a bare "duplicate" — mission section 28
     * requires the operator to see what they are overriding.
     *
     * Deliberately does NOT auto-merge, auto-rename, or relax the
     * `code` unique index; the only two outcomes are "refused" and
     * "allowed with an audited reason".
     *
     * @param  Collection<int, PracticeAreaDuplicateCandidate>  $candidates
     */
    private function assertDuplicatesAcknowledged(Collection $candidates, ?string $overrideReason): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        if (is_string($overrideReason) && trim($overrideReason) !== '') {
            return;
        }

        $evidence = $candidates
            ->map(fn ($candidate): string => $candidate->summaryLine().' — '.implode('; ', $candidate->matchReasons))
            ->implode(' | ');

        throw new InvalidArgumentException(
            'This normalizes onto an existing practice area, so it may be a duplicate: '.$evidence.'. '
            .'Review the existing entry first. If this is genuinely distinct taxonomy, supply a reason to proceed.'
        );
    }

    /**
     * Mission section 33. A canonical code is the identity other
     * records point at; changing it once real references exist would
     * silently orphan whatever resolves a practice area by code. No
     * canonical rename/migration service exists in this codebase to
     * carry those references across, so the safe behavior is to refuse
     * rather than to perform a half-migration.
     *
     * Uses only GLOBAL reference tables, which can be counted exactly
     * from a platform session. Tenant-owned references are invisible
     * here (FORCE RLS), so this check is deliberately treated as
     * sufficient-to-refuse but never as proof that a change is safe —
     * see PracticeAreaDependencyAnalysisService's own docblock.
     */
    private function assertCodeMayBeChanged(PracticeArea $practiceArea): void
    {
        if ($this->dependencies->hasGlobalReferences($practiceArea)) {
            throw new InvalidArgumentException(
                'The canonical code of a referenced practice area cannot be changed. '
                .'Other records already point at this practice area, and this codebase has no '
                .'canonical rename/migration service to carry those references across. '
                .'Create a new practice area and deactivate this one instead.'
            );
        }
    }

    private function assertCodeIsUnique(string $code, ?int $excludingId = null): void
    {
        $query = PracticeArea::query()->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("A practice area with code \"{$code}\" already exists.");
        }
    }
}
