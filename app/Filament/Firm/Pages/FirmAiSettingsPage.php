<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Enums\AiProviderKeyStatus;
use App\Filament\Support\StepUp\StepUpAuthentication;
use App\Models\Firm;
use App\Services\AiProviderConnectionTestService;
use App\Services\AiProviderKeyService;
use App\Services\FirmAiConfigurationService;
use App\Services\FirmSettingsAccessPolicyService;
use App\Services\TenantContextService;
use App\ValueObjects\FirmAiConfigurationStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Settings → AI & Automation. Where a firm brings its own OpenAI credential,
 * proves it works, and sees exactly why AI is or is not running.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT OFFER:
 *
 *   - PlatformManaged mode. It would mean "FirmsVault supplies the key";
 *     FirmsVault holds none. Offering it would let a firm switch AI "on" and
 *     watch every intake silently degrade. FirmAiConfigurationService refuses
 *     the value as well, so hiding it here is presentation, not the guarantee.
 *   - Providers other than OpenAI. AiProvider has four cases; one has an
 *     adapter. A stored Anthropic key would be a credential nothing can use.
 *   - Any way to read a stored key back. The plaintext exists only in transit
 *     to AiProviderKeyService::import(); this page shows status, label and
 *     date, never key material — not even a masked prefix.
 *
 * WHY THE STATUS BLOCK IS AS LONG AS IT IS. Six independent things can stop
 * AI: entitlement, the platform kill switch, mode, credential, the intake
 * toggle, and budget. A firm whose intake stopped using AI needs to know WHICH
 * one, not just that "AI is off" — so the page reports every gate plus the one
 * that is currently blocking, read from the same service the runtime uses.
 *
 * AUTHORIZATION. Viewing is FirmSettingsAccessPolicyService::canView (all
 * active staff roles — credential STATUS is not a secret). Every mutation is
 * canManage (FirmOwner only), re-checked inside the handler rather than only
 * on ->visible(). Credential mutations additionally require step-up
 * authentication through the canonical StepUpAuthentication helper: an
 * authenticated-but-stolen session must not be able to swap the key an
 * intake's AI calls are billed to. Test Connection is manage-gated but not
 * step-up-gated — it changes nothing and reveals nothing about the credential
 * beyond whether OpenAI accepted it.
 *
 * TENANT CONTEXT. Every read and write goes through a service that wraps
 * itself in runWithFirmContext(); firm_settings, firm_ai_settings and
 * firm_ai_provider_keys all carry permanent FORCE ROW LEVEL SECURITY, and a
 * Livewire submit handler carries no ambient app.current_firm_id.
 */
class FirmAiSettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI & Automation';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 25;

    protected static ?string $title = 'AI & Automation';

    protected static ?string $slug = 'ai-automation';

    public ?array $data = [];

    /**
     * The result of the most recent Test Connection click, held for this
     * component's lifetime only. Never persisted: a stale "connection OK" from
     * last week would be worse than no answer at all.
     */
    public ?bool $connectionSucceeded = null;

    public ?string $connectionMessage = null;

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canManageAi(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canManage($firmUser->role);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $status = $this->status();

        $this->form->fill([
            'ai_mode' => $status->configuredMode->value,
            'intake_ai_assist_enabled' => $status->intakeAssistEnabled,
            'model' => $status->model,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                // Safe as a string handler: save() takes no arguments and needs
                // no modal. Every action that DOES need either one lives in
                // getHeaderActions() below — see the note there.
                Action::make('save')
                    ->label('Save AI Settings')
                    ->action('save')
                    ->visible(fn (): bool => static::canManageAi()),
            ]),
        ]);
    }

    /**
     * The credential actions, as header actions with closure handlers.
     *
     * Both details matter and neither is stylistic:
     *
     * A STRING passed to Action::action() is returned verbatim as the button's
     * wire:click handler (Filament\Actions\Action::getLivewireClickHandler()
     * short-circuits on is_string). The click then calls that Livewire method
     * DIRECTLY — no action mounting, and therefore no modal. For these actions
     * that meant the step-up password field was never rendered and never
     * validated, and a handler expecting the modal's $data crashed on an empty
     * argument list. A closure keeps the action on Filament's mount → fill →
     * validate → call path, which is where step-up actually runs.
     *
     * The handlers are closures rather than public methods for the same
     * reason: every public method on a Livewire component is callable from the
     * browser by name. A public revokeApiKey() would be reachable directly,
     * skipping the confirmation modal that carries the step-up check.
     */
    protected function getHeaderActions(): array
    {
        return [
            StepUpAuthentication::mergeInto(
                Action::make('addApiKey')
                    ->label(fn (): string => $this->status()->credentialStatus === AiProviderKeyStatus::Active
                        ? 'Replace API Key'
                        : 'Add API Key')
                    ->modalDescription('The key is encrypted with this firm\'s own encryption key and can never be read back from this page.')
                    ->modalSubmitActionLabel('Save API key')
                    ->action(function (array $data): void {
                        $this->storeCredential($data, 'API key saved');
                    })
                    ->visible(fn (): bool => static::canManageAi()),
                [
                    TextInput::make('apiKey')
                        ->label('OpenAI API key')
                        ->password()
                        ->required()
                        ->autocomplete('off')
                        // Lives in the action's own $data for the duration of
                        // the call. Never dehydrated into $this->data, so it
                        // never reaches the component's Livewire snapshot.
                        ->helperText('Starts with sk-. FirmsVault stores it encrypted and never displays it again.'),
                    TextInput::make('label')
                        ->label('Label (optional)')
                        ->maxLength(255)
                        ->helperText('Only to help you recognise this key later. Never sent to OpenAI.'),
                ],
                'web',
            ),

            Action::make('testConnection')
                ->label('Test Connection')
                ->action(function (): void {
                    $this->performTestConnection();
                })
                ->visible(fn (): bool => static::canManageAi()),

            StepUpAuthentication::mergeInto(
                Action::make('rotateApiKey')
                    ->label('Rotate API Key')
                    ->modalDescription('Stores a new key and marks the current one Rotated. The old key is kept for audit history but is never used again.')
                    ->modalSubmitActionLabel('Rotate key')
                    ->action(function (array $data): void {
                        // Rotation IS an import: import() marks the previous
                        // Active key Rotated and inserts the replacement in one
                        // firm-scoped call. A separate rotate path would be a
                        // second way to reach the same invariant.
                        $this->storeCredential($data, 'API key rotated');
                    })
                    ->visible(fn (): bool => static::canManageAi() && $this->status()->credentialStatus === AiProviderKeyStatus::Active),
                [
                    TextInput::make('apiKey')
                        ->label('New OpenAI API key')
                        ->password()
                        ->required()
                        ->autocomplete('off'),
                ],
                'web',
            ),

            StepUpAuthentication::protect(
                Action::make('revokeApiKey')
                    ->label('Revoke API Key')
                    ->color('danger')
                    ->modalDescription('Turns AI off for this firm with no replacement. Intake continues without AI assistance. You can add a new key at any time.')
                    ->modalSubmitActionLabel('Revoke key')
                    ->action(function (): void {
                        $this->performRevoke();
                    })
                    ->visible(fn (): bool => static::canManageAi() && $this->status()->credentialStatus === AiProviderKeyStatus::Active),
                'web',
            ),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('AI Mode')
                    ->description('FirmsVault does not supply an AI credential. AI runs on this firm\'s own OpenAI key, or not at all.')
                    ->columns(2)
                    ->schema([
                        Select::make('ai_mode')
                            ->label('AI Mode')
                            ->options([
                                AiMode::Disabled->value => 'Disabled',
                                AiMode::FirmOwned->value => 'Firm Owned',
                            ])
                            ->required()
                            ->disabled(fn (): bool => ! static::canManageAi()),
                        Toggle::make('intake_ai_assist_enabled')
                            ->label('AI-assisted intake')
                            ->helperText('When off, the public intake questionnaire still works — it simply never calls OpenAI.')
                            ->disabled(fn (): bool => ! static::canManageAi()),
                        Select::make('model')
                            ->label('Model')
                            // Options come from the firm's OWN credential: an
                            // OpenAI key is scoped to a project, and a project
                            // is granted specific models. A fixed list in
                            // config would offer firms models they cannot call
                            // — which is exactly how this firm ended up
                            // configured for a model its project had no access
                            // to, and only found out at Test Connection.
                            ->options(fn (): array => $this->modelOptions())
                            ->helperText('Only the models this firm\'s API key can actually use are listed. Add a key first to populate this.')
                            ->disabled(fn (): bool => ! static::canManageAi())
                            ->native(false),
                    ]),

                Section::make('Provider')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => 'Provider: '.($this->status()->providerLabel ?? 'None')),
                        Text::make(fn (): string => 'Model: '.($this->status()->model ?? '—')),
                    ]),

                Section::make('Credential')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => 'Credential: '.$this->credentialSummary()),
                        Text::make(fn (): string => 'Credential status: '.($this->status()->credentialStatus?->name ?? 'None stored')),
                        Text::make(fn (): string => 'Label: '.($this->status()->credentialLabel ?? '—')),
                        Text::make(fn (): string => 'Added: '.($this->status()->credentialAddedAt?->format('Y-m-d H:i') ?? '—')),
                    ]),

                Section::make('Connection')
                    ->description('Sends a fixed diagnostic string to OpenAI. No client, matter or intake data is included.')
                    ->schema([
                        Text::make(fn (): string => $this->connectionMessage ?? 'Not tested in this session.'),
                    ]),

                Section::make('Entitlement & Platform Policy')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => 'AI entitlement: '.($this->status()->entitlementEnabled ? 'Enabled' : 'Not enabled')),
                        Text::make(fn (): string => 'Platform policy: '.($this->status()->platformKillSwitchEngaged
                            ? 'AI paused platform-wide by FirmsVault'
                            : 'AI permitted platform-wide')),
                    ]),

                Section::make('Budget')
                    ->description('Counts both firm-user AI and public intake AI for this firm, in the current calendar month.')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => 'Token limit: '.($this->status()->tokenLimitPerPeriod === null
                            ? 'No limit set'
                            : number_format($this->status()->tokenLimitPerPeriod))),
                        Text::make(fn (): string => 'Used this period: '.number_format($this->status()->tokensUsedThisPeriod)),
                    ]),

                Section::make('Status')
                    ->schema([
                        Text::make(fn (): string => $this->status()->aiWouldRun()
                            ? 'AI-assisted intake is active for this firm.'
                            : 'AI is not running: '.($this->status()->blockingReason() ?? 'unknown reason.')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $firm = $this->authorizedFirmForManagement();
        $state = $this->form->getState();

        $mode = AiMode::tryFrom((string) ($state['ai_mode'] ?? ''));

        // tryFrom() plus the selectable-mode check in the service: a forged
        // 'platform_managed' in the payload is rejected twice, and an unknown
        // string never reaches the service at all.
        abort_if($mode === null, 422);

        app(FirmAiConfigurationService::class)->setMode($firm, $mode);
        app(FirmAiConfigurationService::class)->setIntakeAssistEnabled($firm, (bool) ($state['intake_ai_assist_enabled'] ?? false));

        $model = is_string($state['model'] ?? null) ? trim($state['model']) : '';

        if ($model !== '') {
            app(FirmAiConfigurationService::class)->setModel($firm, $model);
        }

        $this->forgetStatus();

        Notification::make()->title('AI settings saved')->success()->send();
    }

    private function performRevoke(): void
    {
        $firm = $this->authorizedFirmForManagement();

        app(AiProviderKeyService::class)->revoke($firm, AiProvider::OpenAi);

        $this->forgetStatus();
        $this->connectionSucceeded = null;
        $this->connectionMessage = null;

        Notification::make()
            ->title('API key revoked')
            ->body('AI is now off for this firm. Intake continues without AI assistance.')
            ->success()
            ->send();
    }

    private function performTestConnection(): void
    {
        $firm = $this->authorizedFirmForManagement();
        $actor = Auth::user();

        $result = app(AiProviderConnectionTestService::class)->test($firm, $actor);

        $this->connectionSucceeded = $result->succeeded;
        $this->connectionMessage = $result->message;

        $notification = Notification::make()->title($result->succeeded ? 'Connection succeeded' : 'Connection failed')->body($result->message);

        $result->succeeded ? $notification->success()->send() : $notification->danger()->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCredential(array $data, string $successTitle): void
    {
        $firm = $this->authorizedFirmForManagement();

        $secret = is_string($data['apiKey'] ?? null) ? $data['apiKey'] : '';
        $label = is_string($data['label'] ?? null) && $data['label'] !== '' ? $data['label'] : null;

        try {
            app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, $secret, Auth::user(), $label);
        } catch (\InvalidArgumentException $e) {
            // Argument errors are about the SHAPE of the input (empty, wrong
            // provider) and are safe to show. Anything else is not.
            Notification::make()->title('Could not save the API key')->body($e->getMessage())->danger()->send();

            return;
        } catch (\Throwable) {
            Notification::make()->title('Could not save the API key')->body('Nothing was changed.')->danger()->send();

            return;
        }

        // A newly stored key invalidates whatever the last test said.
        $this->forgetStatus();
        $this->connectionSucceeded = null;
        $this->connectionMessage = null;

        Notification::make()->title($successTitle)->success()->send();
    }

    private function credentialSummary(): string
    {
        $status = $this->status();

        return match ($status->credentialStatus) {
            AiProviderKeyStatus::Active => 'Stored and active',
            AiProviderKeyStatus::Rotated => 'Superseded by a newer key',
            AiProviderKeyStatus::Revoked => 'Revoked — no replacement stored',
            default => 'None stored',
        };
    }

    /**
     * Memoised for the render pass. Every Text component below asks for the
     * status, and each call is several tenant-scoped queries — without this the
     * page would re-derive the same answer a dozen times per render. Cleared
     * by anything that changes the answer.
     */
    private ?FirmAiConfigurationStatus $statusCache = null;

    private function status(): FirmAiConfigurationStatus
    {
        return $this->statusCache ??= app(FirmAiConfigurationService::class)->status($this->currentFirm());
    }

    private function forgetStatus(): void
    {
        $this->statusCache = null;
        $this->modelOptionsCache = null;
    }

    /**
     * @var array<string, string>|null
     */
    private ?array $modelOptionsCache = null;

    /**
     * @return array<string, string>
     */
    private function modelOptions(): array
    {
        if ($this->modelOptionsCache !== null) {
            return $this->modelOptionsCache;
        }

        // Only ask OpenAI for the catalog on behalf of someone who could act
        // on it. Every other role sees the field disabled, and a read-only
        // page view should not reach out to a third party.
        $models = static::canManageAi()
            ? app(FirmAiConfigurationService::class)->availableModels($this->currentFirm())
            : [];

        // Whatever the firm is configured for stays selectable even if the
        // lookup failed or the project no longer grants it — otherwise saving
        // any other field would silently blank the model.
        $current = $this->status()->model;

        if ($current !== null && ! in_array($current, $models, true)) {
            $models[] = $current;
        }

        sort($models);

        return $this->modelOptionsCache = array_combine($models, $models) ?: [];
    }

    private function currentFirm(): Firm
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_if($firmUser === null, 403);

        return app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): Firm => Firm::query()->findOrFail($firmUser->firm_id),
        );
    }

    private function authorizedFirmForManagement(): Firm
    {
        abort_unless(static::canManageAi(), 403);

        return $this->currentFirm();
    }
}
