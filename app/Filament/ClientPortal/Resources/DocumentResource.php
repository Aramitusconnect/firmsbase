<?php

declare(strict_types=1);

namespace App\Filament\ClientPortal\Resources;

use App\Enums\DocumentScanStatus;
use App\Enums\DocumentStatus;
use App\Filament\ClientPortal\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\ClientPortal\Resources\DocumentResource\Pages\ViewDocument;
use App\Models\ClientPortalUser;
use App\Models\Document;
use App\Services\ClientPortalMatterAccessPolicyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * DocumentResource (Client Portal) — Follow-up 1 (Client Portal
 * Documents), the missing client-facing read side of Mission 3's
 * `client_visible` flag / `DocumentSecurityService::
 * canBeViewedInPortalBy()` boundary. Read-only: List + View only,
 * mirroring `MatterResource`'s (Client Portal) exact "no ad-hoc
 * Create/Edit" discipline for the identical underlying reason — a
 * client never manages documents through this resource, only views
 * what the firm has explicitly shared with them.
 *
 * Standalone resource rather than nested under Matter's own View page:
 * a client's shared documents may span several granted matters, and
 * Filament's existing Client Portal structure here (MatterResource /
 * InvoiceResource, both top-level) has no established relation-manager
 * convention to extend — a flat, top-level "Documents" list is the
 * simpler, more coherent addition, exactly the "keep it simple" call
 * this follow-up's own scope invites.
 *
 * Scoping is composed from THREE independent conditions, mirroring
 * `DocumentSecurityService::canBeViewedInPortalBy()` exactly (the
 * canonical boundary that method, the Firm-side share toggle, and this
 * resource must never drift from):
 *   1. `client_visible = true` (an explicit firm "share with client"
 *      decision — never inferred),
 *   2. `matter_id` IN `ClientPortalMatterAccessPolicyService::
 *      grantedMatterIds()` (an explicit, active matter grant — never
 *      inferred from `Document.client_id` alone), and
 *   3. `isUsable()` (`scan_status = Clean` AND `status != Rejected`) —
 *      the Follow-up 1 hardening fix applied to
 *      `canBeViewedInPortalBy()` itself, so a still-scanning or
 *      rejected/infected document can never become portal-visible even
 *      if a firm user attempted to share it.
 *
 * `getEloquentQuery()` here is the list-level UX filter only —
 * `ViewDocument::resolveRecord()` re-checks `DocumentSecurityService::
 * canBeViewedInPortalBy()` directly as the real per-record boundary,
 * the identical "list is UX filter, resolve step is the boundary"
 * split `MatterResource`/`ViewMatter` (Client Portal) already draws.
 *
 * Field allowlist (enforced in the table, not here): original
 * filename, MIME type, uploaded date, and the matter it belongs to.
 * Never uploaded_by, approved_by/approved_at, rejected_reason,
 * scan_status/scan_result_detail/scanned_at, file_hash,
 * storage_disk/storage_path, encryption_key_id,
 * document_request_item_id, marketplace_intake_id, or any other
 * internal lifecycle/scan/storage field off `Document`.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $slug = 'documents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Documents';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'original_filename';

    public static function canAccess(): bool
    {
        return Auth::guard('client')->check() && parent::canAccess();
    }

    /**
     * The list-level UX filter — a query-time restriction (not a
     * post-load check), so a document failing any of the three
     * conditions is never fetched into memory or rendered in the
     * first place. Composes ClientPortalMatterAccessPolicyService::
     * grantedMatterIds() exactly like MatterResource::
     * getEloquentQuery() already does.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var ClientPortalUser|null $portalUser */
        $portalUser = Auth::guard('client')->user();

        if ($portalUser === null) {
            return $query->whereRaw('1 = 0');
        }

        $grantedMatterIds = app(ClientPortalMatterAccessPolicyService::class)->grantedMatterIds($portalUser);

        if ($grantedMatterIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('client_visible', true)
            ->whereIn('matter_id', $grantedMatterIds)
            ->where('scan_status', DocumentScanStatus::Clean)
            ->where('status', '!=', DocumentStatus::Rejected);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')->label('File')->searchable(),
                TextColumn::make('matter.stage')->label('Matter')->placeholder('—'),
                TextColumn::make('mime_type')->label('Type')->placeholder('—'),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No documents shared with you yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }
}
