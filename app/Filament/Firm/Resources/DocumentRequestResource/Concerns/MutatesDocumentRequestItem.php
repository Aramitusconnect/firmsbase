<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Concerns;

use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Services\DocumentRequestAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * MutatesDocumentRequestItem — the ONE place every DocumentRequestResource\
 * Actions\*ItemAction turns a table-row Action click into a call to a
 * DocumentRequestService method, mirroring CapturesConsent's exact
 * shape for this module.
 *
 * Tenant-context discipline: `document_request_items` carries no
 * firm_id of its own (scoped transitively through `document_request_id`
 * — see DocumentRequestItem's own model docblock), so the owning
 * DocumentRequest must be resolved fresh, by primary key, INSIDE an
 * explicit runWithFirmContext() wrap (TOCTOU discipline, matching
 * RecordsManualPayment/CapturesConsent) BEFORE calling any
 * DocumentRequestService method — each of those methods establishes its
 * own separate runWithFirmContext() wrap for the actual write, which is
 * safe/re-entrant nested inside this one (TenantContextService::
 * runWithFirmContext()'s own documented behavior).
 */
trait MutatesDocumentRequestItem
{
    /**
     * @param  callable(Firm, DocumentRequestItem): void  $mutate  Receives the fresh Firm and a fresh, firm-verified DocumentRequestItem (with its documentRequest relation loaded); must call exactly one DocumentRequestService method.
     */
    protected function performItemTransition(DocumentRequestItem $record, callable $mutate, string $successTitle, string $deniedTitle = 'Not permitted', string $deniedBody = 'Your role may not manage document requests.'): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null || ! app(DocumentRequestAccessPolicyService::class)->canManageRequest($firmUser->role)) {
            Notification::make()->title($deniedTitle)->body($deniedBody)->danger()->send();

            return;
        }

        $result = app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($record, $firmUser, $mutate): array {
                /** @var DocumentRequestItem|null $fresh */
                $fresh = DocumentRequestItem::query()->with('documentRequest')->where('id', $record->id)->first();

                if ($fresh === null || $fresh->documentRequest === null || (int) $fresh->documentRequest->firm_id !== (int) $firmUser->firm_id) {
                    return ['ok' => false, 'message' => 'This document request item could not be found for your firm.'];
                }

                $firm = $firmUser->firm;

                try {
                    $mutate($firm, $fresh);
                } catch (\RuntimeException $e) {
                    return ['ok' => false, 'message' => $e->getMessage()];
                }

                return ['ok' => true, 'message' => null];
            },
        );

        if (! $result['ok']) {
            Notification::make()->title('Could not update this item')->body($result['message'])->danger()->send();

            return;
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
