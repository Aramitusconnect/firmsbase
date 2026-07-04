<?php

namespace App\Services;

use App\Enums\ReleaseNoteStatus;
use App\Models\PlatformAdmin;
use App\Models\ReleaseNote;
use Illuminate\Database\Eloquent\Collection;

/**
 * ReleaseNoteService — the only writer of release_notes. Platform-level
 * only — no method here accepts an organization/firm/plan parameter,
 * matching the model's own deliberate lack of those columns.
 */
class ReleaseNoteService
{
    public function create(array $attributes, ?PlatformAdmin $createdBy = null): ReleaseNote
    {
        return ReleaseNote::create([
            'version' => $attributes['version'] ?? null,
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'status' => ReleaseNoteStatus::Draft,
            'created_by' => $createdBy?->id,
        ]);
    }

    public function publish(ReleaseNote $releaseNote): ReleaseNote
    {
        $releaseNote->update([
            'status' => ReleaseNoteStatus::Published,
            'published_at' => now(),
        ]);

        return $releaseNote->fresh();
    }

    public function archive(ReleaseNote $releaseNote): ReleaseNote
    {
        $releaseNote->update(['status' => ReleaseNoteStatus::Archived]);

        return $releaseNote->fresh();
    }

    public function listPublished(): Collection
    {
        return ReleaseNote::query()
            ->where('status', ReleaseNoteStatus::Published->value)
            ->orderByDesc('published_at')
            ->get();
    }
}
