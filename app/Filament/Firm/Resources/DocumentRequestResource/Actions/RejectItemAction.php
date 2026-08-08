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
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * RejectItemAction — wired directly to
 * `DocumentRequestService::reject()`, never a bare
 * `DocumentRequestItem::update()`. A reason is required — `reject()`
 * itself type-hints `string $reason` (no default), matching this
 * Action's own required Textarea.
 */
class RejectItemAction extends Action
{
    use MutatesDocumentRequestItem;

    private const FROM_STATUSES = [
        DocumentRequestItemStatus::Submitted,
        DocumentRequestItemStatus::UnderReview,
    ];

    public static function getDefaultName(): ?string
    {
        return 'rejectItem';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reject');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');
        $this->requiresConfirmation();
        $this->modalHeading('Reject this document');
        $this->modalDescription('Marks this document as rejected. This is terminal for the item — if the client needs to try again, use "Request Replacement" instead.');
        $this->modalSubmitActionLabel('Reject');

        $this->schema([
            Textarea::make('reason')->label('Reason')->required()->rows(3),
        ]);

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && in_array($record->status, self::FROM_STATUSES, true);
        });

        $this->action(function (array $data, DocumentRequestItem $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if (! filled($data['reason'] ?? null)) {
                Notification::make()->title('A reason is required to reject a document.')->danger()->send();

                return;
            }

            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->reject($firm, $item, $firmUser->user, (string) $data['reason']),
                'Item rejected',
            );
        });
    }
}
