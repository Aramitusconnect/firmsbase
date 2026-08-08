<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\DocumentRequestResource\Pages;

use App\Filament\Firm\Resources\DocumentRequestResource;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Firm;
use App\Models\Matter;
use App\Services\DocumentRequestService;
use App\Services\TenantContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateDocumentRequest — the ONLY UI path that may create a
 * DocumentRequest row; calls `DocumentRequestService::create()`
 * directly, NEVER a bare `DocumentRequest::create()` (mirrors
 * CreateExpense/CreateDeadline's exact discipline for this panel).
 * `DocumentRequestService::create()` is transactional and creates the
 * parent row AND every requested item together — a bare model create
 * would skip item creation entirely and leave `status` unset by the
 * service's own aggregate logic.
 *
 * The form's `items` Repeater rows are converted to
 * `DocumentRequestService::create()`'s own
 * `array<int, array{label:string, is_required?:bool}>` shape here.
 *
 * Tenant-context wrap matches every other create page in this panel —
 * `DocumentRequestService::create()`'s own internal
 * `runWithFirmContext()` call is safe/re-entrant nested inside this
 * one.
 */
class CreateDocumentRequest extends CreateRecord
{
    protected static string $resource = DocumentRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($data, $firmUser): DocumentRequest {
                $firm = Firm::query()->findOrFail($firmUser->firm_id);
                $client = Client::query()->where('id', $data['client_id'])->firstOrFail();
                $matter = isset($data['matter_id']) && $data['matter_id'] !== null
                    ? Matter::query()->where('id', $data['matter_id'])->first()
                    : null;

                $items = collect($data['items'] ?? [])
                    ->map(fn (array $item): array => [
                        'label' => (string) $item['label'],
                        'is_required' => (bool) ($item['is_required'] ?? true),
                    ])
                    ->all();

                return app(DocumentRequestService::class)->create(
                    firm: $firm,
                    client: $client,
                    items: $items,
                    matter: $matter,
                    title: $data['title'] ?? 'Document request',
                    instructions: $data['instructions'] ?? null,
                    dueAt: isset($data['due_at']) && $data['due_at'] !== null ? Carbon::parse($data['due_at']) : null,
                    createdBy: $firmUser->user,
                );
            },
        );
    }
}
