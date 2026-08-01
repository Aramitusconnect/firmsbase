<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\BillingInterval;
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
 * CreatePlanAction — FIRMSVAULT STAGING ADMIN STABILIZATION. A header
 * action on the Plans list, purpose-built (not Filament's generic
 * CreateAction) so every mutation still routes through PlanService,
 * not a bare Eloquent model form. Collects only real `plans` columns
 * (see PlanService's own docblock for the code/description columns
 * this pass added). Price is collected directly in integer cents —
 * deliberately not a dollars TextInput converted client-side — so
 * there is no floating-point amount anywhere between the form and
 * Plan::price_cents. Currency is not collected: MoneyDisplay's own
 * docblock documents every platform-billing amount in this schema as
 * USD-only by prior approved decision; this form does not introduce a
 * second currency column or a false choice.
 */
class CreatePlanAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'createPlan';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Create plan');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->schema([
            TextInput::make('name')
                ->label('Plan name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Plan code')
                ->helperText('A unique, stable identifier, e.g. "solo-practice". Cannot be changed once the plan is in use.')
                ->required()
                ->maxLength(255)
                ->alphaDash(),
            TextInput::make('price_cents')
                ->label('Price (integer cents, USD)')
                ->helperText('Enter the amount in cents — e.g. 9900 for $99.00. This schema stores no floating-point amounts.')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
            Select::make('billing_interval')
                ->label('Billing interval')
                ->options(collect(BillingInterval::cases())
                    ->mapWithKeys(fn (BillingInterval $interval): array => [$interval->value => Str::headline($interval->value)])
                    ->all())
                ->required()
                ->native(false),
            TextInput::make('support_access_level')
                ->label('Support access level')
                ->helperText('A free-form categorical label, e.g. "standard", "priority", "dedicated".')
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
                ->helperText('Safe internal notes only — never real customer or financial data.')
                ->maxLength(2000),
        ]);

        $this->requiresConfirmation();
        $this->modalHeading('Create plan');

        $this->action(function (array $data, PlatformStaffAccessPolicyService $accessPolicy, PlanService $planService): void {
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

            try {
                $planService->create([
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
                Notification::make()->title('Could not create plan')->body($e->getMessage())->danger()->send();

                return;
            }

            Notification::make()->title('Plan created')->success()->send();
        });
    }
}
