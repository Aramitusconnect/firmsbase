<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Marketplace\Enums\CorrectionType;
use App\Marketplace\Models\DirectoryCorrectionRequest;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceCorrectionService;
use App\Models\PlatformAdmin;
use App\Services\PlatformAdminAuditEventRecorder;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CreateInternalCorrectionRequestAction — MyAttorney SuperAdmin console
 * professionalization mission (MYAT5). "Create Request" per this
 * mission's own spec section 7D — a genuine, narrow need: a
 * SuperAdmin/support agent who receives a correction report by phone
 * or email needs a way to record it so it enters the same reviewed
 * workflow as a self-service report, rather than editing the listing
 * directly with no review trail. Reuses
 * MarketplaceCorrectionService::submit() as-is (no new domain code) —
 * the only difference from a self-service submission is that
 * reporter_name is stamped "Admin/Internal (<platform admin name>)"
 * so the source is never disguised as a public report, and the
 * creation itself is audited (submit()'s own no-tenant-context
 * fallback path, since a SuperAdmin creating this has no FirmUser
 * reporter to attribute it to).
 */
class CreateInternalCorrectionRequestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createInternalCorrectionRequest';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create Request');
        $this->icon(Heroicon::OutlinedPlus);
        $this->schema([
            Select::make('directory_firm_id')
                ->label('Listing')
                ->options(fn (): array => DirectoryFirm::query()->orderBy('display_name')->limit(200)->pluck('display_name', 'id')->all())
                ->searchable()
                ->required(),
            Select::make('correction_type')
                ->label('Type')
                ->options(collect(CorrectionType::cases())->mapWithKeys(fn (CorrectionType $t) => [$t->value => $t->label()])->all())
                ->required()
                ->native(false),
            Textarea::make('description')->label('Description')->required()->rows(3),
        ]);
        $this->modalDescription('Records an offline/phone/email report on the requester\'s behalf. Marked as Source = Admin/Internal and audited under your name.');
        $this->requiresConfirmation();

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, MarketplaceCorrectionService $corrections): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $decision = $accessPolicy->canManageMarketplaceGovernance($actor);
            if (! $decision->allowed) {
                Notification::make()->title('Not permitted')->body($decision->reason)->danger()->send();

                return;
            }

            $firm = DirectoryFirm::query()->find($data['directory_firm_id']);
            if ($firm === null) {
                Notification::make()->title('That listing could not be found.')->danger()->send();

                return;
            }

            $request = $corrections->submit(
                $firm,
                CorrectionType::from($data['correction_type']),
                $data['description'],
                reporterName: 'Admin/Internal ('.$actor->name.')',
            );

            $this->auditManualCreation($request, $actor);

            Notification::make()->title('Correction request created')->success()->send();
        });
    }

    private function auditManualCreation(DirectoryCorrectionRequest $request, PlatformAdmin $actor): void
    {
        app(PlatformAdminAuditEventRecorder::class)->recordPlatformEvent(
            $actor,
            'marketplace_correction_created_internally',
            'marketplace_correction',
            ['directory_correction_request_id' => $request->id, 'directory_firm_id' => $request->directory_firm_id],
        );
    }
}
