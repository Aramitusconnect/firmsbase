<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlatformAdmin;

/**
 * PlanService — the only place Plan rows are created or have their
 * lifecycle status changed. Plans are global reference/commercial data
 * (no firm_id), edited by platform admins only.
 *
 * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
 * Commercial Administration") addition: activate()/archive() now
 * accept an optional PlatformAdmin $actor and, when one is supplied,
 * record a PlatformAdminAuditEventRecorder::recordPlatformEvent() row
 * (the firm-less variant — a Plan is not tied to one firm). When
 * $actor is null (every existing caller — no app-level call site
 * currently passes one; only tests call these methods directly today)
 * behavior is byte-for-byte unchanged from before this addition.
 */
class PlanService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    public function __construct(
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function create(array $attributes): Plan
    {
        return Plan::create(array_merge(['status' => PlanStatus::Draft, 'is_active' => true], $attributes));
    }

    public function update(Plan $plan, array $attributes): Plan
    {
        return tap($plan)->update($attributes)->fresh();
    }

    public function activate(Plan $plan, ?PlatformAdmin $actor = null): Plan
    {
        $activated = tap($plan)->update(['status' => PlanStatus::Active])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_activated',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $activated->id,
                    'resulting_status' => $activated->status->value,
                ],
            );
        }

        return $activated;
    }

    public function archive(Plan $plan, ?PlatformAdmin $actor = null): Plan
    {
        $archived = tap($plan)->update(['status' => PlanStatus::Archived, 'is_active' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_archived',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $archived->id,
                    'resulting_status' => $archived->status->value,
                ],
            );
        }

        return $archived;
    }
}
