<?php

declare(strict_types=1);

namespace App\Filament\Firm\Resources\MarketplaceIntakeResource\Actions;

use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeSummaryService;
use App\Models\Firm;
use App\Services\ClientCrmAccessPolicyService;
use App\Services\TenantContextService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * GenerateAiSummaryAction — Mission 3 (MyAttorney Conversion + AI
 * Intake), checkpoint 9. Calls
 * MarketplaceIntakeSummaryService::generate() — the first real
 * Firm-User-authenticated AiUsageRecorderService::record() call in
 * this codebase, so this action inherits every existing gate that
 * service already enforces (AI mode/entitlement/budget) without
 * needing to re-check them itself. The summary is always a disposable
 * aid: the notification/modal deliberately restates that it is not
 * legal advice and must be verified, matching
 * MarketplaceIntakeSummaryService's own instructionText.
 */
class GenerateAiSummaryAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generateAiSummary';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Generate AI Summary');
        $this->icon(Heroicon::OutlinedSparkles);
        $this->color('gray');
        $this->modalHeading('Generate AI Summary');
        $this->modalDescription('Summarizes this prospect\'s own captured answers for a faster first read. This is a proposal only — never legal advice — and must be verified against the answers themselves before acting on it.');
        $this->modalSubmitActionLabel('Generate');
        $this->requiresConfirmation();

        $this->visible(function (MarketplaceIntake $record): bool {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || (int) $firmUser->firm_id !== (int) $record->firm_id) {
                return false;
            }

            return app(ClientCrmAccessPolicyService::class)->canView($firmUser->role);
        });

        $this->action(function (MarketplaceIntake $record, MarketplaceIntakeSummaryService $summaries): void {
            $firmUser = Auth::user()?->activeFirmUser();

            if ($firmUser === null || ! app(ClientCrmAccessPolicyService::class)->canView($firmUser->role)) {
                Notification::make()->title('Not permitted')->danger()->send();

                return;
            }

            try {
                app(TenantContextService::class)->runWithFirmContext(
                    (int) $firmUser->firm_id,
                    function () use ($record, $firmUser, $summaries): void {
                        $firm = Firm::query()->findOrFail($firmUser->firm_id);
                        $fresh = MarketplaceIntake::query()->where('id', $record->id)->firstOrFail();

                        $summaries->generate($firm, $fresh, $firmUser->user);
                    },
                );
            } catch (RuntimeException $e) {
                Notification::make()->title('Could not generate summary')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Summary generated')->body('Not legal advice — verify against the answers themselves.')->success()->send();
        });
    }
}
