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
 * RequestReplacementItemAction — wired directly to
 * `DocumentRequestService::requestReplacement()`, never a bare
 * `DocumentRequestItem::update()`. Moves the item back to
 * `needs_replacement`, which is itself one of `isChaseEligibleStatus()`'s
 * three eligible statuses (see `DocumentRequestItem`'s own docblock) —
 * i.e. this item becomes chase-eligible again, matching the real
 * domain rule. A reason is required — `requestReplacement()` itself
 * type-hints `string $reason` (no default).
 */
class RequestReplacementItemAction extends Action
{
    use MutatesDocumentRequestItem;

    private const FROM_STATUSES = [
        DocumentRequestItemStatus::Submitted,
        DocumentRequestItemStatus::UnderReview,
        DocumentRequestItemStatus::Rejected,
    ];

    public static function getDefaultName(): ?string
    {
        return 'requestReplacementItem';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request Replacement');
        $this->icon(Heroicon::OutlinedArrowPath);
        $this->color('warning');
        $this->requiresConfirmation();
        $this->modalHeading('Request a replacement document');
        $this->modalDescription('Asks the client to provide this document again (e.g. it was incomplete, illegible, or the wrong document).');
        $this->modalSubmitActionLabel('Request Replacement');

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
                Notification::make()->title('A reason is required to request a replacement.')->danger()->send();

                return;
            }

            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->requestReplacement($firm, $item, $firmUser->user, (string) $data['reason']),
                'Replacement requested',
            );
        });
    }
}
