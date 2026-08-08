<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Actions;

use App\Enums\DocumentRequestItemStatus;
use App\Filament\Firm\Resources\DocumentRequestResource\Concerns\MutatesDocumentRequestItem;
use App\Models\DocumentRequestItem;
use App\Services\DocumentRequestAccessPolicyService;
use App\Services\DocumentRequestService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * MarkViewedItemAction — records that staff has confirmed the client
 * opened/looked at this request item. Wired directly to
 * `DocumentRequestService::markViewed()`, never a bare
 * `DocumentRequestItem::update()`. Only visible on a Requested item —
 * `markViewed()` itself is a silent no-op for any other status (see its
 * own docblock), so this Action mirrors that guard at the UI layer
 * rather than letting a user click it and see nothing happen.
 */
class MarkViewedItemAction extends Action
{
    use MutatesDocumentRequestItem;

    public static function getDefaultName(): ?string
    {
        return 'markViewed';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Viewed');
        $this->icon(Heroicon::OutlinedEye);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalDescription('Records that the client has been shown/opened this request item.');

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && $record->status === DocumentRequestItemStatus::Requested;
        });

        $this->action(function (DocumentRequestItem $record): void {
            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->markViewed($firm, $item),
                'Item marked viewed',
            );
        });
    }
}
