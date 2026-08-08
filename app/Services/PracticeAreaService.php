<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
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
 */
class PracticeAreaService
{
    private const AUDIT_CATEGORY = 'practice_area_catalog';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function create(array $attributes, ?PlatformAdmin $actor = null): PracticeArea
    {
        $code = $attributes['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            throw new InvalidArgumentException('A practice area code is required.');
        }

        $this->assertCodeIsUnique($code);

        $practiceArea = DB::transaction(fn (): PracticeArea => PracticeArea::create(array_merge(
            ['is_active' => true],
            $attributes,
        )));

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_created',
                self::AUDIT_CATEGORY,
                ['practice_area_id' => $practiceArea->id, 'code' => $practiceArea->code],
            );
        }

        return $practiceArea;
    }

    public function update(PracticeArea $practiceArea, array $attributes, ?PlatformAdmin $actor = null): PracticeArea
    {
        if (array_key_exists('code', $attributes)) {
            $newCode = $attributes['code'];

            if (! is_string($newCode) || trim($newCode) === '') {
                throw new InvalidArgumentException('A practice area code is required.');
            }

            if (strcasecmp($newCode, $practiceArea->code ?? '') !== 0) {
                $this->assertCodeIsUnique($newCode, excludingId: $practiceArea->id);
            }
        }

        $updated = DB::transaction(fn (): PracticeArea => tap($practiceArea)->update($attributes)->fresh());

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_updated',
                self::AUDIT_CATEGORY,
                ['practice_area_id' => $updated->id, 'changed_fields' => array_keys($attributes)],
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

    public function deactivate(PracticeArea $practiceArea, ?PlatformAdmin $actor = null): PracticeArea
    {
        $deactivated = tap($practiceArea)->update(['is_active' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'practice_area_deactivated',
                self::AUDIT_CATEGORY,
                ['practice_area_id' => $deactivated->id],
            );
        }

        return $deactivated;
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
