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
 * MoveToReviewItemAction — wired directly to
 * `DocumentRequestService::markUnderReview()`, never a bare
 * `DocumentRequestItem::update()`. Only visible on a Submitted item —
 * `markUnderReview()` itself throws a RuntimeException for any other
 * status.
 */
class MoveToReviewItemAction extends Action
{
    use MutatesDocumentRequestItem;

    public static function getDefaultName(): ?string
    {
        return 'moveToReview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Move to Review');
        $this->icon(Heroicon::OutlinedMagnifyingGlass);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalDescription('Moves this item into staff review before approving, rejecting, or requesting a replacement.');

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && $record->status === DocumentRequestItemStatus::Submitted;
        });

        $this->action(function (DocumentRequestItem $record): void {
            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->markUnderReview($firm, $item),
                'Item moved to review',
            );
        });
    }
}
