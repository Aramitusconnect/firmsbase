<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\PlatformSubscriptionStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformSubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * CancelSubscriptionAction — Phase 3 (FirmsVault Platform Admin Control
 * Center, "Billing and Commercial Administration"). The one mutating
 * action on PlatformSubscriptionResource — routes exclusively through
 * PlatformSubscriptionService::cancel(), the sole writer of
 * platform_subscriptions rows (see that service's own docblock).
 *
 * TOCTOU-safe, mirroring TogglePlatformAdminActiveStatusAction/
 * AssignPlatformAdminRoleAction's established shape exactly: the acting
 * admin is re-resolved fresh from the auth guard inside the closure
 * (never trusted from page-load time), the target record is re-fetched
 * fresh by primary key, and BOTH canManagePlatformBilling() (the
 * Phase 3 backend-foundations "manage" gate) and the blanket canMutate()
 * rule are checked explicitly before calling the service — matching
 * RetrySyncFailureAction's precedent for combining a narrow "manage"
 * gate with the blanket mutation rule at the UI layer.
 *
 * The choice between "cancel at period end" and "cancel immediately" is
 * a required Radio field with an explanatory helper text for each
 * option (per this pass's own dispatch instructions: "not a bare toggle
 * with no explanation") — passed straight through as
 * cancel()'s own $atPeriodEnd boolean parameter, which already branches
 * on it internally (cancel_at_period_end flag vs. an immediate
 * Cancelled status + cancelled_at timestamp).
 */
class CancelSubscriptionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'cancelSubscription';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Cancel');
        $this->icon(Heroicon::OutlinedXCircle);
        $this->color('danger');

        $this->schema([
            Radio::make('at_period_end')
                ->label('Cancellation timing')
                ->boolean()
                ->default(true)
                ->descriptions([
                    '1' => 'Cancel at period end — the subscription keeps its current access through the end of the already-paid billing period, then stops renewing. Nothing changes immediately.',
                    '0' => 'Cancel immediately — the subscription is marked Cancelled right now, before the current billing period ends.',
                ])
                ->required(),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Cancel Subscription');
        $this->modalDescription('Choose whether this subscription keeps access through the end of its current billing period, or is cancelled right now.');
        $this->modalSubmitActionLabel('Cancel Subscription');

        // Already-cancelled/expired subscriptions have nothing left to
        // do here — offering the action on a terminal-status row would
        // just no-op against cancel()'s own idempotent update.
        $this->visible(fn (PlatformSubscription $record): bool => ! in_array(
            $record->status,
            [PlatformSubscriptionStatus::Cancelled, PlatformSubscriptionStatus::Expired],
            true,
        ));

        $this->action(function (PlatformSubscription $record, array $data, PlatformStaffAccessPolicyService $accessPolicy, PlatformSubscriptionService $subscriptionService): void {
            $actor = Auth::guard('platform_admin')->user();

            if (! $actor instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $manageDecision = $accessPolicy->canManagePlatformBilling($actor);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($actor);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $target = PlatformSubscription::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That subscription could not be found.')->danger()->send();

                return;
            }

            $atPeriodEnd = (bool) ($data['at_period_end'] ?? true);

            $cancelled = $subscriptionService->cancel($target, $atPeriodEnd, $actor);

            Notification::make()
                ->title('Subscription cancelled')
                ->body($atPeriodEnd
                    ? 'Cancellation scheduled for the end of the current billing period.'
                    : "Cancelled immediately. Status: {$cancelled->status->value}.")
                ->success()
                ->send();
        });
    }
}
