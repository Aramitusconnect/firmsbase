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
 * ApproveItemAction — wired directly to
 * `DocumentRequestService::approve()`, never a bare
 * `DocumentRequestItem::update()`. `approve()` itself has no status
 * guard (confirmed by direct source read), so this Action's own
 * visible() is the safety net that keeps the transition sensible
 * (Submitted/UnderReview only) — this Action always passes the acting
 * FirmUser's own `user` as the `$reviewer`, never a form-typed value.
 */
class ApproveItemAction extends Action
{
    use MutatesDocumentRequestItem;

    private const FROM_STATUSES = [
        DocumentRequestItemStatus::Submitted,
        DocumentRequestItemStatus::UnderReview,
    ];

    public static function getDefaultName(): ?string
    {
        return 'approveItem';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Approve');
        $this->icon(Heroicon::OutlinedCheckCircle);
        $this->color('success');
        $this->requiresConfirmation();
        $this->modalDescription('Marks this document as approved. This is terminal — the item will no longer be chased for a reminder.');

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && in_array($record->status, self::FROM_STATUSES, true);
        });

        $this->action(function (DocumentRequestItem $record): void {
            $firmUser = Auth::user()?->activeFirmUser();

            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->approve($firm, $item, $firmUser->user),
                'Item approved',
            );
        });
    }
}
