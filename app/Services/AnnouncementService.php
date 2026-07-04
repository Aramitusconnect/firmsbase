<?php

namespace App\Services;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * AnnouncementService — the only writer of announcements. Targeting
 * columns (organization_id/firm_id/plan_id/module_code) live directly
 * on the row; null = broadcast/global (approved decision, no
 * announcement_targets table). severity is the announcement's own
 * level; min_severity is an optional targeting/filter threshold —
 * visibleTo() below applies both independently.
 */
class AnnouncementService
{
    private const SEVERITY_ORDER = [
        AnnouncementSeverity::Info->value => 0,
        AnnouncementSeverity::Warning->value => 1,
        AnnouncementSeverity::Critical->value => 2,
    ];

    public function create(array $attributes, ?PlatformAdmin $createdBy = null): Announcement
    {
        return Announcement::create([
            'organization_id' => $attributes['organization_id'] ?? null,
            'firm_id' => $attributes['firm_id'] ?? null,
            'plan_id' => $attributes['plan_id'] ?? null,
            'module_code' => $attributes['module_code'] ?? null,
            'min_severity' => $attributes['min_severity'] ?? null,
            'type' => $attributes['type'],
            'severity' => $attributes['severity'],
            'status' => AnnouncementStatus::Draft,
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function publish(Announcement $announcement): Announcement
    {
        $announcement->update(['status' => AnnouncementStatus::Published]);

        return $announcement->fresh();
    }

    public function activate(Announcement $announcement): Announcement
    {
        $announcement->update(['status' => AnnouncementStatus::Active]);

        return $announcement->fresh();
    }

    public function archive(Announcement $announcement): Announcement
    {
        $announcement->update(['status' => AnnouncementStatus::Archived]);

        return $announcement->fresh();
    }

    /**
     * Returns every announcement whose targeting matches the given
     * viewer context. A null targeting column on the row always
     * matches (broadcast); a non-null column must equal the viewer's
     * corresponding id. min_severity (if set on the row) is compared
     * against the viewer's $viewerMinSeverity threshold.
     */
    public function targetedFor(
        ?int $organizationId,
        ?int $firmId,
        ?int $planId,
        ?string $moduleCode,
        AnnouncementSeverity $viewerMinSeverity = AnnouncementSeverity::Info,
    ): Collection {
        return Announcement::query()
            ->whereIn('status', [AnnouncementStatus::Published->value, AnnouncementStatus::Active->value])
            ->where(fn (Builder $q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->where(fn (Builder $q) => $q->whereNull('firm_id')->orWhere('firm_id', $firmId))
            ->where(fn (Builder $q) => $q->whereNull('plan_id')->orWhere('plan_id', $planId))
            ->where(fn (Builder $q) => $q->whereNull('module_code')->orWhere('module_code', $moduleCode))
            ->get()
            ->filter(function (Announcement $announcement) use ($viewerMinSeverity) {
                if ($announcement->min_severity === null) {
                    return true;
                }

                return self::SEVERITY_ORDER[$viewerMinSeverity->value] >= self::SEVERITY_ORDER[$announcement->min_severity->value];
            })
            ->values();
    }
}
