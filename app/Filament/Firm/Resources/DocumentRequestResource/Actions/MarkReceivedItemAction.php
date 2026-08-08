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
 * MarkReceivedItemAction — this mission's required "Mark Received"
 * action. There is no real file-upload/storage pipeline anywhere in
 * this codebase (Firm Feature Manifest cross-cutting finding #6), so
 * this does NOT represent a client uploading a file — it records that
 * staff has received this document by some out-of-band means (mail,
 * email, in person, fax) and is tracking that fact, exactly matching
 * `DocumentRequestItemStatus::Submitted`'s real meaning
 * ("submitted" in DocumentRequestService's own vocabulary). Wired
 * directly to `DocumentRequestService::markSubmitted()`, never a bare
 * `DocumentRequestItem::update()`. Visible only when the service's own
 * guard would accept the transition (Requested/Viewed/NeedsReplacement)
 * — `markSubmitted()` throws a RuntimeException for any other status.
 */
class MarkReceivedItemAction extends Action
{
    use MutatesDocumentRequestItem;

    private const FROM_STATUSES = [
        DocumentRequestItemStatus::Requested,
        DocumentRequestItemStatus::Viewed,
        DocumentRequestItemStatus::NeedsReplacement,
    ];

    public static function getDefaultName(): ?string
    {
        return 'markReceived';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Mark Received');
        $this->icon(Heroicon::OutlinedInbox);
        $this->color('primary');
        $this->requiresConfirmation();
        $this->modalHeading('Mark document received');
        $this->modalDescription('Records that this document has been received by the firm (e.g. by mail, email, or in person). This does not upload or store a file — this application has no document storage today.');

        $this->visible(function (DocumentRequestItem $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            return $firmUser !== null
                && app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)
                && in_array($record->status, self::FROM_STATUSES, true);
        });

        $this->action(function (DocumentRequestItem $record): void {
            $this->performItemTransition(
                $record,
                fn ($firm, DocumentRequestItem $item) => app(DocumentRequestService::class)->markSubmitted($firm, $item),
                'Item marked received',
            );
        });
    }
}
