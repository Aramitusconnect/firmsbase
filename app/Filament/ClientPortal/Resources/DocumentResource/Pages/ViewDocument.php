<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources\DocumentResource\Pages;

use App\Filament\ClientPortal\Resources\DocumentResource;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Services\DocumentSecurityService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ViewDocument (Client Portal) — Follow-up 1 (Client Portal
 * Documents). Per-record authorization boundary — the REAL gate, not
 * DocumentResource::getEloquentQuery()'s list-level filter alone.
 * Mirrors ViewMatter's (Client Portal) own "resolveRecord() re-checks
 * the real policy service directly" shape exactly, here re-checking
 * DocumentSecurityService::canBeViewedInPortalBy() — the same
 * canonical composed boundary (client_visible AND isUsable() AND a
 * live matter grant) getEloquentQuery() draws from, never trusting
 * the list query's own filter as the actual boundary.
 *
 * The "Download" header action resolves to the real,
 * session-authenticated `client-portal.documents.download` route —
 * DocumentSecurityService::canBeViewedInPortalBy() is the actual
 * authorization boundary there too (re-checked independently by
 * App\Http\Controllers\ClientPortal\DocumentDownloadController), not
 * this action's own visibility (a UX-level convenience only — this
 * page is already unreachable for a document the client cannot view,
 * per resolveRecord() above).
 *
 * Field allowlist: original filename, MIME type, uploaded date, and
 * the matter it belongs to only — identical to DocumentResource's own
 * table columns. Deliberately exposes nothing else off Document — no
 * uploaded_by, approved_by/approved_at, rejected_reason, scan_status/
 * scan_result_detail/scanned_at, file_hash, storage_disk/storage_path,
 * encryption_key_id, document_request_item_id, or
 * marketplace_intake_id.
 */
class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Document $record */
        $record = parent::resolveRecord($key);

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        abort_unless(
            $portalUser !== null && app(DocumentSecurityService::class)->canBeViewedInPortalBy($record, $portalUser),
            403,
        );

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Download')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (Document $record): string => route('client-portal.documents.download', $record))
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document')
                ->columns(2)
                ->schema([
                    TextEntry::make('original_filename')->label('File'),
                    TextEntry::make('matter.stage')->label('Matter')->placeholder('—'),
                    TextEntry::make('mime_type')->label('Type')->placeholder('—'),
                    TextEntry::make('created_at')->label('Uploaded')->dateTime(),
                ]),
        ]);
    }
}
