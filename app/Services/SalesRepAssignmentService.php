<?php

namespace App\Services;

use App\Enums\SalesAssignmentStatus;
use App\Models\PlatformAdmin;
use App\Models\SalesRepAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * SalesRepAssignmentService — assigns a PlatformLead or Opportunity to a
 * PlatformAdmin (sales rep). Polymorphic over $assignable, mirroring
 * PlatformSalesTaskService's own polymorphic pattern.
 */
class SalesRepAssignmentService
{
    public function assign(Model $assignable, PlatformAdmin $admin): SalesRepAssignment
    {
        return SalesRepAssignment::create([
            'assignable_type' => $assignable::class,
            'assignable_id' => $assignable->id,
            'platform_admin_id' => $admin->id,
            'status' => SalesAssignmentStatus::Active,
            'assigned_at' => now(),
        ]);
    }

    public function reassign(SalesRepAssignment $assignment, PlatformAdmin $newAdmin): SalesRepAssignment
    {
        $assignment->update([
            'status' => SalesAssignmentStatus::Reassigned,
            'reassigned_at' => now(),
        ]);

        return $this->assign($assignment->assignable, $newAdmin);
    }

    public function close(SalesRepAssignment $assignment): SalesRepAssignment
    {
        $assignment->update([
            'status' => SalesAssignmentStatus::Closed,
            'closed_at' => now(),
        ]);

        return $assignment->fresh();
    }
}
