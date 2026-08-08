<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MatterType;
use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MatterTypeService — the only place `matter_types` rows are created or
 * edited. MatterType is GLOBAL platform reference data scoped under a
 * PracticeArea (no firm_id, no RLS — see that model's own docblock),
 * managed nested under PracticeAreaResource (Practice Area → Matter
 * Types), never as an independent top-level Filament resource.
 *
 * Deactivation is a soft state flip (`is_active = false`), never a hard
 * delete — mirrors PracticeAreaService's own discipline exactly, for
 * the same reason (a matter type already referenced by a real Matter
 * must remain a valid foreign key target; only new selections stop
 * offering it, enforced by AddMatterAction's own
 * `->where('is_active', true)` filter).
 */
class MatterTypeService
{
    private const AUDIT_CATEGORY = 'practice_area_catalog';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function create(PracticeArea $practiceArea, array $attributes, ?PlatformAdmin $actor = null): MatterType
    {
        $code = $attributes['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            throw new InvalidArgumentException('A matter type code is required.');
        }

        $this->assertCodeIsUniqueWithinPracticeArea($practiceArea, $code);

        $matterType = DB::transaction(fn (): MatterType => MatterType::create(array_merge(
            ['practice_area_id' => $practiceArea->id, 'is_active' => true],
            $attributes,
        )));

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'matter_type_created',
                self::AUDIT_CATEGORY,
                ['matter_type_id' => $matterType->id, 'practice_area_id' => $practiceArea->id, 'code' => $matterType->code],
            );
        }

        return $matterType;
    }

    public function update(MatterType $matterType, array $attributes, ?PlatformAdmin $actor = null): MatterType
    {
        if (array_key_exists('code', $attributes)) {
            $newCode = $attributes['code'];

            if (! is_string($newCode) || trim($newCode) === '') {
                throw new InvalidArgumentException('A matter type code is required.');
            }

            if (strcasecmp($newCode, $matterType->code ?? '') !== 0) {
                $this->assertCodeIsUniqueWithinPracticeArea($matterType->practiceArea, $newCode, excludingId: $matterType->id);
            }
        }

        $updated = DB::transaction(fn (): MatterType => tap($matterType)->update($attributes)->fresh());

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'matter_type_updated',
                self::AUDIT_CATEGORY,
                ['matter_type_id' => $updated->id, 'changed_fields' => array_keys($attributes)],
            );
        }

        return $updated;
    }

    public function activate(MatterType $matterType, ?PlatformAdmin $actor = null): MatterType
    {
        $activated = tap($matterType)->update(['is_active' => true])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'matter_type_activated',
                self::AUDIT_CATEGORY,
                ['matter_type_id' => $activated->id],
            );
        }

        return $activated;
    }

    public function deactivate(MatterType $matterType, ?PlatformAdmin $actor = null): MatterType
    {
        $deactivated = tap($matterType)->update(['is_active' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'matter_type_deactivated',
                self::AUDIT_CATEGORY,
                ['matter_type_id' => $deactivated->id],
            );
        }

        return $deactivated;
    }

    private function assertCodeIsUniqueWithinPracticeArea(PracticeArea $practiceArea, string $code, ?int $excludingId = null): void
    {
        $query = MatterType::query()
            ->where('practice_area_id', $practiceArea->id)
            ->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($excludingId !== null) {
            $query->whereKeyNot($excludingId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("A matter type with code \"{$code}\" already exists under \"{$practiceArea->name}\".");
        }
    }
}
