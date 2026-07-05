<?php

namespace App\Services;

use App\Enums\FormEditionWatchStatus;
use App\Models\FormEditionWatchItem;
use App\Models\FormTemplate;
use App\Models\PlatformAdmin;

/**
 * FormEditionWatchService — content-ops watch tracking. Platform-admin
 * actor only; no firm ever creates or reads these rows (no firm_id
 * column exists on the table at all).
 */
class FormEditionWatchService
{
    public function startWatching(FormTemplate $formTemplate, PlatformAdmin $actor): FormEditionWatchItem
    {
        return FormEditionWatchItem::create([
            'form_template_id' => $formTemplate->id,
            'watch_status' => FormEditionWatchStatus::Watching,
            'created_by_platform_admin_id' => $actor->id,
        ]);
    }

    public function markNewEditionDetected(FormEditionWatchItem $item, string $detectedEditionDate, ?string $notes = null): FormEditionWatchItem
    {
        $item->update([
            'watch_status' => FormEditionWatchStatus::NewEditionDetected,
            'detected_edition_date' => $detectedEditionDate,
            'notes' => $notes,
        ]);

        return $item->fresh();
    }

    public function markInReview(FormEditionWatchItem $item): FormEditionWatchItem
    {
        $item->update(['watch_status' => FormEditionWatchStatus::InReview]);

        return $item->fresh();
    }

    public function markUpdated(FormEditionWatchItem $item): FormEditionWatchItem
    {
        $item->update(['watch_status' => FormEditionWatchStatus::Updated]);

        return $item->fresh();
    }

    public function markNoActionNeeded(FormEditionWatchItem $item): FormEditionWatchItem
    {
        $item->update(['watch_status' => FormEditionWatchStatus::NoActionNeeded]);

        return $item->fresh();
    }
}
