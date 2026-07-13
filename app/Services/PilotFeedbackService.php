<?php

namespace App\Services;

use App\Enums\PilotFeedbackCategory;
use App\Enums\PilotFeedbackPriority;
use App\Enums\PilotFeedbackSource;
use App\Enums\PilotFeedbackStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Matter;
use App\Models\PilotFeedbackItem;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Support\Carbon;

/**
 * PilotFeedbackService — the only place pilot_feedback_items rows are
 * created or transitioned.
 *
 * hotfix 01: scheduleFollowUp() normalizes follow_up_at to whole-second
 * precision (startOfSecond()) before persisting, for the same reason
 * MaintenanceWindowService normalizes its scheduled dates — a
 * \DateTimeInterface value with microsecond precision is not
 * guaranteed to compare equal to what actually round-trips through the
 * database column.
 *
 * Section 39A-3L Phase B6: submit() wraps its create() call in the
 * caller-supplied firm's context (or explicit no-context for internal
 * feedback). Each of the six transition methods derives its own context
 * from the already-hydrated $item->firm_id and wraps the whole
 * update()+fresh() round trip in a single context — not just the
 * update() call — so a firm-scoped item's trailing fresh() re-read
 * still runs under that firm's context rather than seeing context
 * already cleared (same fix already proven for MaintenanceWindowService).
 */
class PilotFeedbackService
{
    public function submit(
        PilotFeedbackSource $source,
        PilotFeedbackCategory $category,
        string $title,
        string $description,
        ?Firm $firm = null,
        ?Client $client = null,
        ?Matter $matter = null,
        ?User $user = null,
        PilotFeedbackPriority $priority = PilotFeedbackPriority::Medium,
        ?User $createdBy = null,
    ): PilotFeedbackItem {
        if ($firm !== null && $client !== null && $client->firm_id !== $firm->id) {
            throw new \RuntimeException(
                'Client does not belong to the same firm as the pilot feedback item.'
            );
        }

        if ($firm !== null && $matter !== null && $matter->firm_id !== $firm->id) {
            throw new \RuntimeException(
                'Matter does not belong to the same firm as the pilot feedback item.'
            );
        }

        $create = fn () => PilotFeedbackItem::create([
            'firm_id' => $firm?->id,
            'client_id' => $client?->id,
            'matter_id' => $matter?->id,
            'user_id' => $user?->id,
            'source' => $source,
            'category' => $category,
            'priority' => $priority,
            'status' => PilotFeedbackStatus::New,
            'title' => $title,
            'description' => $description,
            'created_by' => $createdBy?->id,
        ]);

        $tenantContext = app(TenantContextService::class);

        return $firm
            ? $tenantContext->runWithFirmContext($firm, $create)
            : $tenantContext->runWithoutFirmContext($create);
    }

    public function triage(PilotFeedbackItem $item, PilotFeedbackPriority $priority): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item, $priority) {
            $item->update(['status' => PilotFeedbackStatus::Triaged, 'priority' => $priority]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function startProgress(PilotFeedbackItem $item): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item) {
            $item->update(['status' => PilotFeedbackStatus::InProgress]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function resolve(PilotFeedbackItem $item, string $resolutionNotes): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item, $resolutionNotes) {
            $item->update([
                'status' => PilotFeedbackStatus::Resolved,
                'resolution_notes' => $resolutionNotes,
                'resolved_at' => now(),
            ]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function markWontFix(PilotFeedbackItem $item, string $reason): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item, $reason) {
            $item->update(['status' => PilotFeedbackStatus::WontFix, 'resolution_notes' => $reason]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function markDuplicate(PilotFeedbackItem $item): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item) {
            $item->update(['status' => PilotFeedbackStatus::Duplicate]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }

    public function scheduleFollowUp(PilotFeedbackItem $item, \DateTimeInterface $followUpAt): PilotFeedbackItem
    {
        $tenantContext = app(TenantContextService::class);
        $firmId = $item->firm_id;

        $body = function () use ($item, $followUpAt) {
            $item->update([
                'follow_up_required' => true,
                'follow_up_at' => Carbon::instance($followUpAt)->startOfSecond(),
            ]);

            return $item->fresh();
        };

        return $firmId !== null
            ? $tenantContext->runWithFirmContext($firmId, $body)
            : $tenantContext->runWithoutFirmContext($body);
    }
}
