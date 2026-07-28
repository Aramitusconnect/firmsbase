<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\ProviderFirmOperationPolicy;
use App\Integrations\Services\FinancialIntegrationAccessPolicyService;
use App\Services\PlaidEntitlementPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlaidUsagePolicyPage — FirmsVault Live Integrations, Checkpoint 4
 * ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §2). The one legitimate
 * Create/Edit-shaped surface in this whole design — edits the firm's
 * own row in `provider_firm_operation_policies` (§1.8's coordinator-
 * resolved split), per-product optional-operation suspension only —
 * NEVER kill switches (platform-only). Gated by
 * `FinancialIntegrationAccessPolicyService::canApprove()` (dual-approval
 * tier — policy changes affect cost exposure, so only the APPROVER role
 * ceiling, not merely `canRequest()`, may write here).
 */
class PlaidUsagePolicyPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Plaid Usage Policy';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $title = 'Plaid Usage Policy';

    private const PRODUCTS = ['balance', 'transactions', 'auth', 'identity', 'income', 'liabilities', 'investments', 'statements'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return false;
        }

        return app(PlaidEntitlementPolicyService::class)->isEnabled($firmUser->firm)
            && app(FinancialIntegrationAccessPolicyService::class)->canApprove($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            $this->form->fill([]);

            return;
        }

        $existing = (new TenantContextService)->runWithFirmContext($firmUser->firm_id, fn () => ProviderFirmOperationPolicy::query()
            ->where('firm_id', $firmUser->firm_id)
            ->where('provider_key', ProviderKey::Plaid->value)
            ->get()
            ->keyBy('product'));

        $this->form->fill([
            'products' => collect(self::PRODUCTS)->mapWithKeys(fn (string $p) => [
                $p => (bool) ($existing->get($p)?->optional_operation_suspended ?? false),
            ])->all(),
            'reason' => '',
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('save')->label('Save')->action('save'),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components(collect(self::PRODUCTS)->map(
                fn (string $product) => Toggle::make("products.{$product}")->label(ucfirst($product).' — suspend optional calls for this firm'),
            )->push(Textarea::make('reason')->label('Reason for change')->rows(2))->all());
    }

    public function save(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        if ($firmUser === null) {
            return;
        }

        app(FinancialIntegrationAccessPolicyService::class)->assertCanApprove($firmUser);

        $state = $this->form->getState();

        (new TenantContextService)->runWithFirmContext($firmUser->firm_id, function () use ($firmUser, $state) {
            foreach ($state['products'] ?? [] as $product => $suspended) {
                ProviderFirmOperationPolicy::query()->updateOrCreate(
                    [
                        'firm_id' => $firmUser->firm_id,
                        'provider_key' => ProviderKey::Plaid->value,
                        'product' => $product,
                        'environment' => config('integrations.provider_environments.plaid.mode', 'sandbox'),
                    ],
                    [
                        'optional_operation_suspended' => (bool) $suspended,
                        'updated_by_firm_user_id' => $firmUser->id,
                        'reason' => $state['reason'] ?? null,
                    ],
                );
            }
        });

        Notification::make()->title('Usage policy saved')->success()->send();
    }
}
