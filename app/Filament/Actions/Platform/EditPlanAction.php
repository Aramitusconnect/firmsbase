<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Services\PlanService;
use App\Services\PlatformStaffAccessPolicyService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * EditPlanAction — FIRMSVAULT STAGING ADMIN STABILIZATION. A record
 * action on the Plans table, purpose-built (not Filament's generic
 * EditAction) so every mutation routes through PlanService::update(),
 * which enforces the financial-terms-lock (price_cents/billing_interval/
 * code become read-only once any firm license or platform subscription
 * references the plan — see that method's own docblock). The form
 * itself does not attempt to know in advance whether those fields are
 * locked; it always submits whatever changed, and PlanService reports
 * a clean validation error if the plan turns out to be in use, rather
 * than duplicating that business rule in two places.
 */
class EditPlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'editPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Edit');
        $this->icon(Heroicon::OutlinedPencilSquare);
        $this->color('gray');

        $this->fillForm(fn (Plan $record): array => [
            'name' => $record->name,
            'code' => $record->code,
            'price_cents' => $record->price_cents,
            'billing_interval' => $record->billing_interval->value,
            'support_access_level' => $record->support_access_level,
            'trial_days' => $record->trial_days,
            'trial_requires_card' => $record->trial_requires_card,
            'description' => $record->description,
        ]);

        $this->schema([
            TextInput::make('name')
                ->label('Plan name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Plan code')
                ->helperText('Cannot be changed once the plan is assigned to any firm license or platform subscription.')
                ->required()
                ->maxLength(255)
                ->alphaDash(),
            TextInput::make('price_cents')
                ->label('Price (integer cents, USD)')
                ->helperText('Cannot be changed once the plan is in use.')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
            Select::make('billing_interval')
                ->label('Billing interval')
                ->helperText('Cannot be changed once the plan is in use.')
                ->options(collect(BillingInterval::cases())
                    ->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => Str::headline($interval->value)])
                    ->all())
                ->required()
                ->native(false),
            TextInput::make('support_access_level')
                ->label('Support access level')
                ->maxLength(255),
            TextInput::make('trial_days')
                ->label('Trial days')
                ->numeric()
                ->integer()
                ->minValue(0),
            Toggle::make('trial_requires_card')
                ->label('Trial requires card'),
            Textarea::make('description')
                ->label('Internal description')
                ->maxLength(2000),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Edit plan');

        /**
         * Billing & Commercial Control Plane pass — impact preview.
         * Before a consequential catalog change, state how far this
         * plan already reaches and what that means for the financial
         * fields on the form. The numbers come from two withCount
         * aggregates on one record, not from loading subscriptions.
         *
         * This preview does not enforce anything — PlanService::update()
         * remains the single place the financial-terms lock is decided.
         * It exists so an operator learns "23 firms are on this" BEFORE
         * submitting, rather than from a rejection afterwards.
         */
        $this->modalDescription(function (Plan $record): string {
            $reach = Plan::query()
                ->whereKey($record->getKey())
                ->withCount(['platformSubscriptions as subscriptions_count', 'firmLicenses as firm_licenses_count'])
                ->firstOrFail();

            $subscriptions = (int) $reach->subscriptions_count;
            $licences = (int) $reach->firm_licenses_count;

            if ($subscriptions === 0 && $licences === 0) {
                return 'Nothing references this plan yet, so every field including price, billing interval, and '.
                    'plan code can still be changed. They lock permanently as soon as the first subscription or '.
                    'firm licence is created against it — there is no plan versioning or effective dating to '.
                    'unwind that later.';
            }

            return 'This plan is already in use: '.$subscriptions.' platform subscription'.
                ($subscriptions === 1 ? '' : 's').' and '.$licences.' firm licence'.($licences === 1 ? '' : 's').
                ' reference it. Price, billing interval, and plan code are therefore locked and a change to them '.
                'will be rejected — a subscription stores no price of its own, so editing them would '.
                'retroactively change what these subscribers are understood to be paying. Name, description, '.
                'support access level, and trial settings can still be changed, and take effect immediately for '.
                'everyone on the plan.';
        });

        $this->action(function (array $data, Plan $record, PlatformStaffAccessPolicyService $accessPolicy, PlanService $planService): void {
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

            $target = Plan::query()->find($record->getKey());

            if ($target === null) {
                Notification::make()->title('That plan could not be found.')->danger()->send();

                return;
            }

            try {
                $planService->update($target, [
                    'name' => $data['name'],
                    'code' => $data['code'],
                    'price_cents' => (int) $data['price_cents'],
                    'billing_interval' => $data['billing_interval'],
                    'support_access_level' => $data['support_access_level'] ?? null,
                    'trial_days' => $data['trial_days'] !== null && $data['trial_days'] !== '' ? (int) $data['trial_days'] : null,
                    'trial_requires_card' => (bool) ($data['trial_requires_card'] ?? false),
                    'description' => $data['description'] ?? null,
                ], $actor);
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Could not update plan')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Plan updated')->success()->send();
        });
    }
}
