<?php

namespace App\Services;

use App\Enums\MaintenanceWindowStatus;
use App\Models\Firm;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MaintenanceWindowService — the only place maintenance_windows rows
 * are created or transitioned. reschedule() creates a NEW row and
 * marks the OLD row Cancelled with rescheduled_from_id pointing back —
 * mirrors Phase 3's PaymentPlan::renegotiate() supersede pattern
 * exactly, never mutates an already-scheduled window's own dates in
 * place.
 *
 * hotfix 01: scheduled_starts_at/scheduled_ends_at are normalized to
 * whole-second precision (startOfSecond()) before being persisted.
 * PostgreSQL's timestamp columns and PHP's DateTime/Carbon can carry
 * sub-second microsecond precision that a caller's in-memory
 * \DateTimeInterface value may not exactly reproduce byte-for-byte
 * after a round trip through the database — normalizing to the
 * second removes that ambiguity entirely, and second-level precision
 * is more than sufficient for a maintenance schedule.
 */
class MaintenanceWindowService
{
    public function schedule(
        ?Firm $firm,
        string $title,
        \DateTimeInterface $scheduledStartsAt,
        \DateTimeInterface $scheduledEndsAt,
        array $affectedComponents = [],
        ?string $publicMessage = null,
        ?string $privateMessage = null,
        ?User $createdBy = null,
    ): MaintenanceWindow {
        $create = fn () => MaintenanceWindow::create([
            'firm_id' => $firm?->id,
            'title' => $title,
            'status' => MaintenanceWindowStatus::Scheduled,
            'scheduled_starts_at' => $this->normalize($scheduledStartsAt),
            'scheduled_ends_at' => $this->normalize($scheduledEndsAt),
            'affected_components' => $affectedComponents,
            'public_message' => $publicMessage,
            'private_message' => $privateMessage,
            'created_by' => $createdBy?->id,
        ]);

        $tenantContext = app(TenantContextService::class);

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $create)
            : $tenantContext->runWithoutFirmContext($create);
    }

    public function start(MaintenanceWindow $window): MaintenanceWindow
    {
        if ($window->status !== MaintenanceWindowStatus::Scheduled) {
            throw new \RuntimeException('Only a scheduled maintenance window can be started.');
        }

        $tenantContext = app(TenantContextService::class);
        $firmId = $window->firm_id;

        $body = function () use ($window) {
            $window->update(['status' => MaintenanceWindowStatus::InProgress, 'actual_starts_at' => now()]);

            return $window->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function complete(MaintenanceWindow $window): MaintenanceWindow
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $window->firm_id;

        $body = function () use ($window) {
            $window->update(['status' => MaintenanceWindowStatus::Completed, 'actual_ends_at' => now()]);

            return $window->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function cancel(MaintenanceWindow $window, ?string $reason = null): MaintenanceWindow
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $window->firm_id;

        $body = function () use ($window, $reason) {
            $window->update([
                'status' => MaintenanceWindowStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $window->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    /**
     * Creates a NEW maintenance window row for the new schedule and
     * marks $window Cancelled + Rescheduled, linked via
     * rescheduled_from_id — never edits $window's own scheduled dates.
     */
    public function reschedule(
        MaintenanceWindow $window,
        \DateTimeInterface $newScheduledStartsAt,
        \DateTimeInterface $newScheduledEndsAt,
    ): MaintenanceWindow {
        $tenantContext = app(TenantContextService::class);
        $firmId = $window->firm_id;

        $body = function () use ($window, $newScheduledStartsAt, $newScheduledEndsAt) {
            return DB::transaction(function () use ($window, $newScheduledStartsAt, $newScheduledEndsAt) {
                $window->update([
                    'status' => MaintenanceWindowStatus::Rescheduled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Rescheduled',
                ]);

                return MaintenanceWindow::create([
                    'firm_id' => $window->firm_id,
                    'title' => $window->title,
                    'status' => MaintenanceWindowStatus::Scheduled,
                    'scheduled_starts_at' => $this->normalize($newScheduledStartsAt),
                    'scheduled_ends_at' => $this->normalize($newScheduledEndsAt),
                    'affected_components' => $window->affected_components,
                    'public_message' => $window->public_message,
                    'private_message' => $window->private_message,
                    'rescheduled_from_id' => $window->id,
                    'created_by' => $window->created_by,
                ]);
            });
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function markCustomerNotificationSent(MaintenanceWindow $window): MaintenanceWindow
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $window->firm_id;

        $body = function () use ($window) {
            $window->update(['customer_notification_sent_at' => now()]);

            return $window->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    private function normalize(\DateTimeInterface $dateTime): Carbon
    {
        return Carbon::instance($dateTime)->startOfSecond();
    }
}
