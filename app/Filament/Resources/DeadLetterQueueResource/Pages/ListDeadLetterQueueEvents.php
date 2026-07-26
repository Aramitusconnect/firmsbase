<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeadLetterQueueResource\Pages;

use App\Filament\Resources\DeadLetterQueueResource;
use App\Models\PlatformAdmin;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * ListDeadLetterQueueEvents — adds a single read-only retention-policy
 * banner above the table (see DeadLetterQueueResource's own docblock:
 * "global retention flag" requirement) — no mutation control, purely
 * informational, computed fresh on every render, never cached.
 */
class ListDeadLetterQueueEvents extends ListRecords
{
    protected static string $resource = DeadLetterQueueResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn (): string => $this->retentionNotice())->color('gray'),
            EmbeddedTable::make(),
        ]);
    }

    private function retentionNotice(): string
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return '';
        }

        $days = DeadLetterQueueResource::deadLetteredRetentionDays($admin);

        return $days === null
            ? 'Retention policy: not permitted to view, or not configured.'
            : "Dead-lettered events are retained for {$days} days (config('integrations.outbox.dead_lettered_retention_days')) — read-only, this console does not change it.";
    }
}
