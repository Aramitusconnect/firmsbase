<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Models\PlatformAdmin;
use App\Models\PracticeArea;
use App\Services\Configuration\PracticeAreaDependencyAnalysisService;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PracticeAreaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * DeactivatePracticeAreaAction — routes exclusively through
 * PracticeAreaService::deactivate(). A soft state flip, never a
 * destructive delete — see PracticeAreaService's own docblock. Visible
 * only for an already-active row.
 *
 * Configuration Control Plane (mission section 34): the confirmation
 * now carries a real IMPACT PREVIEW built from
 * PracticeAreaDependencyAnalysisService, plus a required reason.
 *
 * The preview shows exact counts for globally-countable references and
 * explicitly reports tenant-owned references (matters, firm
 * enablements, budget templates, marketplace intakes) as NOT COUNTED
 * rather than as zero. Those tables are FORCE-RLS protected and a
 * platform-admin session sees none of their rows, so printing "0
 * matters affected" here would be a fabricated safety signal on the
 * exact screen where an operator decides whether deactivation is safe
 * (mission sections 24 and 77). Counting them would require an
 * O(number of firms) per-firm context scan, which is offered on the
 * View page as an explicit on-demand action rather than run silently
 * inside a confirmation modal.
 */
class DeactivatePracticeAreaAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'deactivatePracticeArea';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Deactivate');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->requiresConfirmation();
        $this->modalHeading('Deactivate practice area');
        $this->modalDescription('This removes the practice area from selection on new matters, clients, and leads. Existing records that already reference it are unaffected — deactivation is a soft state flip and never deletes anything.');

        $this->schema([
            Placeholder::make('impact_preview')
                ->label('Impact preview')
                ->content(fn (PracticeArea $record): string => self::impactPreviewFor($record)),
            Textarea::make('reason')
                ->label('Reason for deactivating')
                ->rows(2)
                ->maxLength(500)
                ->required(),
        ]);

        $this->visible(fn (PracticeArea $record): bool => $record->is_active);

        $this->action(function (array $data, PracticeArea $record, PlatformStaffAccessPolicyService $accessPolicy, PracticeAreaService $practiceAreaService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManagePracticeAreaCatalog($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $target = PracticeArea::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That practice area could not be found.')->danger()->send();

                return;
            }

            $practiceAreaService->deactivate($target, $actor, reason: $data['reason'] ?? null);

            Notification::make()->title('Practice area deactivated')->success()->send();
        });
    }

    /**
     * Renders the section 34 impact preview. Global counts are exact;
     * tenant-scoped dependencies are named and explicitly reported as
     * not counted, never as zero.
     */
    private static function impactPreviewFor(PracticeArea $record): string
    {
        $analysis = app(PracticeAreaDependencyAnalysisService::class);

        $lines = [];

        foreach ($analysis->globalDependencies($record) as $row) {
            $lines[] = sprintf('%s: %d', $row['label'], $row['count']);
        }

        $tenantLabels = array_map(
            fn (array $row): string => $row['label'],
            $analysis->tenantDependenciesUnscanned(),
        );

        $lines[] = 'Not counted here (tenant-scoped, protected by row-level security): '
            .implode(', ', $tenantLabels)
            .'. Deactivation does not modify any of them.';

        return implode(' • ', $lines);
    }
}
