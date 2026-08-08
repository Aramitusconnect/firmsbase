<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Actions;

use App\Enums\DocumentRequestItemStatus;
use App\Filament\Firm\Resources\DocumentRequestResource\Concerns\MutatesDocumentRequestItem;
use App\Models\DocumentRequestItem;
use App\Services\DocumentRequestAccessPolicyService;
use App\Services\DocumentRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * WaiveItemAction — this mission's other required action ("Waive").
 * Wired directly to `DocumentRequestService::waive()`, never a bare
 * `DocumentRequestItem::update()`. `waive()` marks the item terminal
 * (`isChaseEligibleStatus()` excludes Waived — see DocumentRequestItem's
 * own docblock: "client reminders stop when approved, waived, expired,
 * or paused by staff") and — per this mission's storage-optional rule —
 * never requires a `documents()` row to exist first: waiving means the
 * firm has decided this item does not need to be provided at all, so
 * there is nothing to have received in the first place. Visible on any
 * non-terminal status.
 */
class WaiveItemAction extends Action
{
    use MutatesDocumentRequestItem;

    private const FROM_STATUSES = [
        DocumentRequestItemStatus::Requested,
        DocumentRequestItemStatus::Viewed,
        DocumentRequestItemStatus::Submitted,
        DocumentRequestItemStatus::UnderReview,
        DocumentRequestItemStatus::NeedsReplacement,
    ];

    public static function getDefaultName(): ?string
    {
        return 'waiveItem';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Waive');
        $this->icon(Heroicon::OutlinedNoSymbol);
        $this->color('gray');
        $this->requiresConfirmation();
        $this->modalHeading('Waive this document');
        $this->modalDescription('Marks this document as no longer required. This is terminal — the item will no longer be chased for a reminder.');
        $this->modalSubmitActionLabel('Waive');

        $this->schema([
            Textarea::make('reason')->label('Reason (optional)')->rows(3),
        ]);

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && in_array($record->status, self::FROM_STATUSES, true);
        });

        $this->action(function (array $data, DocumentRequestItem $record): void {
            $firmUser = Auth::user()?->activeFirmUser();
            $reason = filled($data['reason'] ?? null) ? (string) $data['reason'] : null;

            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->waive($firm, $item, $firmUser->user, $reason),
                'Item waived',
            );
        });
    }
}
