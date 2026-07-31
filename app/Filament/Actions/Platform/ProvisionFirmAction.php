<?php

declare(strict_types=1);

namespace App\Filament\Actions\Platform;

use App\Enums\CustomerType;
use App\Enums\DeploymentMode;
use App\Enums\FirmOrganizationProvisioningMode;
use App\Enums\FirmProvisioningStatus;
use App\Enums\PlanStatus;
use App\Exceptions\ExistingUserReviewRequiredException;
use App\Exceptions\FirmProvisioningRequestChangedException;
use App\Exceptions\PlatformAdminIdentityCollisionException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\FirmProvisioningService;
use App\Services\PlatformStaffAccessPolicyService;
use App\ValueObjects\FirmProvisioningInput;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * ProvisionFirmAction — the ONLY way a Platform Admin creates a new firm
 * tenant. A reviewed multi-step wizard, never a generic Filament
 * CreateAction/Create page — a bare Create form bound directly to the
 * Firm model would let a partial firm (no owner, no license, no
 * encryption key) be saved, which is exactly what this mission
 * forbids. Every field collected here maps to a real column/enum;
 * nothing is invented. All actual provisioning logic lives in
 * FirmProvisioningService — this class only collects/validates input,
 * checks authorization, and reports the outcome.
 *
 * Authorization is enforced entirely INSIDE the action closure below
 * (never only via ->visible()), matching this codebase's own
 * established convention (see e.g. AuthorizeProviderOperationRetryAction) —
 * `->visible()` here is UX only, never the real boundary. A
 * ReadOnlyAuditor or an unauthorized PlatformAdmin invoking this action
 * directly (bypassing the button) is still refused by the identical
 * check inside the closure.
 */
class ProvisionFirmAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'provisionFirm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Provision firm');
        $this->icon(Heroicon::OutlinedPlusCircle);
        $this->color('primary');

        $this->visible(function (): bool {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                return false;
            }

            $accessPolicy = app(PlatformStaffAccessPolicyService::class);

            return $accessPolicy->canManageFirms($admin)->allowed
                && $accessPolicy->canMutate($admin)->allowed;
        });

        $this->steps([
            Step::make('Firm')
                ->schema([
                    // Generated once when the wizard's form is first
                    // filled (Filament evaluates a field ->default()
                    // closure exactly once per form fill, not on every
                    // re-render) — a double-click or a resubmit within
                    // this same wizard session always carries the
                    // identical key.
                    Hidden::make('idempotency_key')
                        ->default(fn (): string => (string) Str::uuid())
                        ->dehydrated(),

                    TextInput::make('firm_name')
                        ->label('Firm name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('legal_name')
                        ->label('Legal name')
                        ->maxLength(255),

                    Select::make('customer_type')
                        ->label('Customer type')
                        ->options(collect(CustomerType::cases())->mapWithKeys(
                            fn (CustomerType $type): array => [$type->value => ucfirst(str_replace('_', ' ', $type->value))]
                        )->all())
                        ->required()
                        ->native(false),

                    Select::make('deployment_mode')
                        ->label('Deployment mode')
                        ->options(collect(DeploymentMode::cases())->mapWithKeys(
                            fn (DeploymentMode $mode): array => [$mode->value => ucfirst(str_replace('_', ' ', $mode->value))]
                        )->all())
                        ->default(DeploymentMode::Saas->value)
                        ->required()
                        ->native(false),
                ]),

            Step::make('Organization')
                ->schema([
                    Select::make('organization_mode')
                        ->label('Organization')
                        ->options([
                            FirmOrganizationProvisioningMode::None->value => 'No organization (standalone firm)',
                            FirmOrganizationProvisioningMode::CreateNew->value => 'Create a new organization',
                            FirmOrganizationProvisioningMode::UseExisting->value => 'Select an existing organization',
                        ])
                        ->default(FirmOrganizationProvisioningMode::None->value)
                        ->required()
                        ->native(false)
                        ->live(),

                    TextInput::make('new_organization_name')
                        ->label('New organization name')
                        ->required()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('organization_mode') === FirmOrganizationProvisioningMode::CreateNew->value),

                    // Searchable select over real, existing Organization
                    // rows — never a free-text internal id field.
                    Select::make('organization_id')
                        ->label('Existing organization')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Organization::query()
                            ->where('name', 'ilike', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Organization::query()->find($value)?->name)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('organization_mode') === FirmOrganizationProvisioningMode::UseExisting->value),
                ]),

            Step::make('Owner')
                ->schema([
                    TextInput::make('owner_name')
                        ->label('Owner full name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('owner_email')
                        ->label('Owner email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true),

                    TextEntry::make('existing_user_notice')
                        ->hiddenLabel()
                        ->color('warning')
                        ->state(function (Get $get): ?string {
                            $email = $get('owner_email');

                            if (! filled($email)) {
                                return null;
                            }

                            return User::query()->where('email', $email)->exists()
                                ? 'An account with this email already exists. You must explicitly confirm reuse below, or use a different email.'
                                : null;
                        })
                        ->visible(fn (Get $get): bool => filled($get('owner_email')) && User::query()->where('email', $get('owner_email'))->exists()),

                    Toggle::make('reuse_existing_user')
                        ->label('Reuse the existing account as this firm\'s owner')
                        ->default(false)
                        ->visible(fn (Get $get): bool => filled($get('owner_email')) && User::query()->where('email', $get('owner_email'))->exists()),
                ]),

            Step::make('Plan')
                ->schema([
                    // Searchable select over real, active Plan rows only
                    // — an archived/draft plan can never be newly
                    // assigned (PlanStatus's own docblock).
                    Select::make('plan_id')
                        ->label('Plan')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Plan::query()
                            ->where('status', PlanStatus::Active->value)
                            ->where('name', 'ilike', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Plan::query()->find($value)?->name)
                        ->live()
                        ->helperText('Optional — a firm can be provisioned without a plan and assigned one later.'),

                    TextInput::make('trial_days_override')
                        ->label('Trial length override (days)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText(fn (Get $get): string => filled($get('plan_id'))
                            ? 'Leave blank to use the plan\'s own trial length ('.(Plan::query()->find($get('plan_id'))?->trial_days ?? '—').' days).'
                            : 'Select a plan first.')
                        ->visible(fn (Get $get): bool => filled($get('plan_id'))),

                    Textarea::make('note')
                        ->label('Internal provisioning note (optional)')
                        ->maxLength(1000)
                        ->rows(2),
                ]),

            Step::make('Review')
                ->schema([
                    TextEntry::make('summary')
                        ->hiddenLabel()
                        ->state(function (Get $get): array {
                            $lines = [
                                'Firm: '.(string) $get('firm_name'),
                                'Customer type: '.(string) $get('customer_type'),
                                'Deployment mode: '.(string) $get('deployment_mode'),
                            ];

                            $lines[] = match ($get('organization_mode')) {
                                FirmOrganizationProvisioningMode::CreateNew->value => 'Organization: new — '.(string) $get('new_organization_name'),
                                FirmOrganizationProvisioningMode::UseExisting->value => 'Organization: '.(Organization::query()->find($get('organization_id'))?->name ?? '—'),
                                default => 'Organization: none (standalone firm)',
                            };

                            $lines[] = 'Owner: '.(string) $get('owner_name').' <'.(string) $get('owner_email').'>';

                            if ($get('reuse_existing_user')) {
                                $lines[] = 'This will reuse an EXISTING user account as the owner.';
                            }

                            $lines[] = filled($get('plan_id'))
                                ? 'Plan: '.(Plan::query()->find($get('plan_id'))?->name ?? '—')
                                : 'Plan: none selected';

                            $lines[] = 'The firm will start in Onboarding — not Activated.';
                            $lines[] = 'No password will be shown here — the owner receives a one-time setup link by email once this is submitted.';

                            return $lines;
                        })
                        ->listWithLineBreaks(),
                ]),
        ]);

        $this->modalHeading('Provision a new firm');
        $this->modalSubmitActionLabel('Provision firm');
        $this->modalWidth('2xl');

        $this->action(function (array $data): void {
            $admin = Auth::guard('platform_admin')->user();

            if (! $admin instanceof PlatformAdmin) {
                Notification::make()->title('You are not signed in as a platform admin.')->danger()->send();

                return;
            }

            $accessPolicy = app(PlatformStaffAccessPolicyService::class);
            $manageDecision = $accessPolicy->canManageFirms($admin);

            if (! $manageDecision->allowed) {
                Notification::make()->title('Not permitted')->body($manageDecision->reason)->danger()->send();

                return;
            }

            $mutateDecision = $accessPolicy->canMutate($admin);

            if (! $mutateDecision->allowed) {
                Notification::make()->title('Not permitted')->body($mutateDecision->reason)->danger()->send();

                return;
            }

            $organizationMode = FirmOrganizationProvisioningMode::from($data['organization_mode'] ?? FirmOrganizationProvisioningMode::None->value);

            $input = new FirmProvisioningInput(
                idempotencyKey: (string) $data['idempotency_key'],
                firmName: (string) $data['firm_name'],
                legalName: filled($data['legal_name'] ?? null) ? (string) $data['legal_name'] : null,
                organizationMode: $organizationMode,
                organizationId: $organizationMode === FirmOrganizationProvisioningMode::UseExisting ? (int) $data['organization_id'] : null,
                newOrganizationName: $organizationMode === FirmOrganizationProvisioningMode::CreateNew ? (string) $data['new_organization_name'] : null,
                ownerName: (string) $data['owner_name'],
                ownerEmail: (string) $data['owner_email'],
                reuseExistingUser: (bool) ($data['reuse_existing_user'] ?? false),
                customerType: CustomerType::from($data['customer_type']),
                deploymentMode: DeploymentMode::from($data['deployment_mode']),
                planId: filled($data['plan_id'] ?? null) ? (int) $data['plan_id'] : null,
                trialDaysOverride: filled($data['trial_days_override'] ?? null) ? (int) $data['trial_days_override'] : null,
                note: filled($data['note'] ?? null) ? (string) $data['note'] : null,
            );

            try {
                $result = app(FirmProvisioningService::class)->provision($input, $admin);
            } catch (PlatformAdminIdentityCollisionException|ExistingUserReviewRequiredException|FirmProvisioningRequestChangedException $e) {
                Notification::make()->title('Could not provision firm')->body($e->getMessage())->danger()->send();

                return;
            } catch (Throwable $e) {
                Notification::make()
                    ->title('Could not provision firm')
                    ->body('An unexpected error occurred. No partial firm was left behind.')
                    ->danger()
                    ->send();

                report($e);

                return;
            }

            if ($result->status === FirmProvisioningStatus::InvitationFailed) {
                Notification::make()
                    ->title('Firm provisioned, but the invitation email failed to send')
                    ->body('Use "Resend owner invitation" from the firm\'s page once the issue is resolved.')
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Firm provisioned')
                ->body($result->firm->name.' is ready in Onboarding. The owner has been sent a setup link.')
                ->success()
                ->send();
        });
    }
}
