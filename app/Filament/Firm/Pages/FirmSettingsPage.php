<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Models\Firm;
use App\Models\FirmSettings;
use App\Services\FirmSettingsAccessPolicyService;
use App\Services\TenantContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Str;

/**
 * FirmSettingsPage — Firm Feature Manifest §13 ("Firm Settings"):
 * "PARTIAL backend, zero UI — genuinely unbuilt, not hidden." A
 * singleton settings Page (deliberately NOT a Resource — there is
 * exactly one `Firm` row and exactly one `FirmSettings` row per tenant
 * context, matching the manifest's own "(3) A singleton Filament Page
 * (not a Resource) bound to the firm's own FirmSettings row" mandate),
 * modeled directly on `PlaidUsagePolicyPage`'s established
 * "InteractsWithSchemas + a single `save()` Action wired to the schema's
 * `EmbeddedSchema::make('form')`" shape.
 *
 * TWO DIFFERENT MODELS, ONE FORM. This page edits `Firm` (legal_name,
 * primary_country, primary_state, default_timezone, default_currency,
 * plus the five new address/phone columns added by this same change —
 * see that migration's own docblock) AND `FirmSettings`
 * (default_language, state_jurisdiction, plus the small optional
 * `branding_settings_json` sub-schema below) side by side. Every field
 * name below is unique across both models (no collision), so a single
 * flat `statePath('data')` is safe — `save()` explicitly whitelists
 * which keys go to which model's `update()` call, never a blind
 * "dump the whole state array at one model" write.
 *
 * DELIBERATELY EXCLUDED FROM THIS FORM — SCOPE BOUNDARY, NOT AN
 * OVERSIGHT:
 *
 *   - `firm_user_2fa_mode` / `client_2fa_mode`: a SEPARATE, not-yet-
 *     built 2FA enrollment task owns these. Toggling either to Required
 *     today, with no enrollment/recovery UI built yet, would
 *     permanently lock users out. Neither column is bound to a form
 *     field, an Action, or even a `TextEntry`/`Text` display component
 *     anywhere below — the one line that even acknowledges they exist
 *     ("2FA policy: managed separately") is plain, non-interactive
 *     `Text`, not bound to any model attribute.
 *
 *   - `payment_mode` / `trust_iolta_protection` / `ai_mode`: real
 *     downstream effects on other gated services (Trust eligibility,
 *     AI mode resolution — see `Firm`'s own Phase 13/15 docblock
 *     notes). Shown as plain-text `Text` display components inside a
 *     read-only Section with a "Contact platform support to change
 *     this" note — never a form field, never part of `$this->data`,
 *     never read by `save()`. A forged/injected value for any of these
 *     three keys in a submitted payload is structurally inert: `save()`
 *     only ever reads the specific whitelisted keys below off
 *     `$this->form->getState()`, so there is no code path that could
 *     apply a `payment_mode`/`trust_iolta_protection`/`ai_mode` value
 *     from form state even if one were somehow present in it.
 *
 *   - `security_settings_json`: manifest §13 confirms this is an
 *     "unused placeholder JSON column — no consumer anywhere, do not
 *     build UI on these until their shape is defined." Left completely
 *     untouched — no field, no display, no reference anywhere in this
 *     file.
 *
 * BRANDING (`branding_settings_json`) — included, narrowly. The
 * manifest allows this only if a "small, genuinely safe, non-file-
 * upload schema" can be defined; this page defines exactly two keys —
 * `display_name_override` (a plain string, no different in kind from
 * `legal_name`) and `primary_color` (a `#rrggbb` hex string, validated
 * server-side by the TextInput's own `->regex()` rule) — stored under
 * `branding_settings_json.display_name_override` /
 * `branding_settings_json.primary_color`. No logo/file field exists
 * anywhere in this codebase's storage layer (manifest confirmed), so no
 * upload field is offered.
 *
 * TENANT CONTEXT. Both `mount()` (a read) and `save()` (a write) wrap
 * their `FirmSettings` access in `TenantContextService::
 * runWithFirmContext()` — `firm_settings` carries permanent FORCE ROW
 * LEVEL SECURITY (see `2026_08_25_930018_force_rls_on_firm_settings_
 * table.php`), and per `WrapsRecordMutationInFirmContext`'s own
 * docblock, a Livewire AJAX submit handler (which is exactly what this
 * page's `save()` is) runs with NO ambient `app.current_firm_id`
 * session setting — only `FirmPanelProvider`'s `authMiddleware` (a
 * page-LOAD-only code path) establishes that. `firms` itself carries NO
 * FORCE RLS (it is the tenancy boundary table, not a `BelongsToTenant`
 * model — see `Firm`'s own class docblock), so wrapping its query in
 * the same block is not strictly required for RLS reasons, but is done
 * anyway for a single atomic save across both models and for `Firm::
 * query()->where('id', ...)` TOCTOU-safe re-fetch discipline, matching
 * this mission's established convention.
 *
 * AUTHORIZATION. `canAccess()`/`shouldRegisterNavigation()` gate on
 * `FirmSettingsAccessPolicyService::canView()` (every active role — see
 * that service's own docblock for the full reasoning). The `save`
 * Action is `->visible()`-gated AND re-checked inside `save()` itself
 * (defense-in-depth, matching every other Action in this panel) on
 * `FirmSettingsAccessPolicyService::canManage()` — FirmOwner only.
 */
class FirmSettingsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Firm Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Firm Settings';

    protected static ?string $slug = 'firm-settings';

    public ?array $data = [];

    /**
     * Plain-scalar snapshots of the three platform-managed FirmSettings
     * columns, captured once in mount() — never re-derived per Text
     * closure render, and never stored as a hydrated Eloquent model on
     * this Livewire component (keeps hydration simple and avoids a
     * second, redundant runWithFirmContext() call per render pass).
     * Never read by save() — display only.
     */
    public ?string $paymentModeDisplay = null;

    public bool $trustIoltaProtectionDisplay = false;

    public ?string $aiModeDisplay = null;

    public static function canAccess(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canView($firmUser->role);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canView($firmUser->role), 403);

        [$firm, $settings] = app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            fn (): array => [
                Firm::query()->findOrFail($firmUser->firm_id),
                FirmSettings::query()->where('firm_id', $firmUser->firm_id)->first(),
            ],
        );

        $branding = $settings?->branding_settings_json ?? [];

        $this->paymentModeDisplay = $settings?->payment_mode?->value;
        $this->trustIoltaProtectionDisplay = (bool) $settings?->trust_iolta_protection;
        $this->aiModeDisplay = $settings?->ai_mode?->value;

        $this->form->fill([
            // Firm — profile fields.
            'legal_name' => $firm->legal_name,
            'primary_country' => $firm->primary_country,
            'primary_state' => $firm->primary_state,
            'default_timezone' => $firm->default_timezone,
            'default_currency' => $firm->default_currency,
            // Firm — address/phone fields (this change's new columns).
            'address_line1' => $firm->address_line1,
            'address_line2' => $firm->address_line2,
            'city' => $firm->city,
            'postal_code' => $firm->postal_code,
            'phone_number' => $firm->phone_number,
            // FirmSettings — editable fields.
            'default_language' => $settings?->default_language,
            'state_jurisdiction' => $settings?->state_jurisdiction,
            // FirmSettings.branding_settings_json — narrow, safe subset.
            'branding_display_name_override' => $branding['display_name_override'] ?? null,
            'branding_primary_color' => $branding['primary_color'] ?? null,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('save')
                    ->label('Save Settings')
                    ->action('save')
                    ->visible(fn (): bool => static::canManageSettings()),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Firm Profile')
                    ->description('These fields live on the firm itself, not on Firm Settings.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')->label('Legal Name')->maxLength(255)->nullable(),
                        TextInput::make('primary_country')->label('Country')->maxLength(2)->nullable()
                            ->helperText('2-letter ISO country code, e.g. US.'),
                        TextInput::make('primary_state')->label('State/Province')->maxLength(255)->nullable(),
                        TextInput::make('default_timezone')->label('Default Timezone')->maxLength(255)->nullable()
                            ->helperText('e.g. America/New_York.'),
                        TextInput::make('default_currency')->label('Default Currency')->maxLength(3)->nullable()
                            ->helperText('3-letter ISO currency code, e.g. USD.'),
                    ]),
                Section::make('Address & Phone')
                    ->description('Newly added — no address/phone field existed anywhere for this firm before.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address_line1')->label('Address Line 1')->maxLength(255)->nullable(),
                        TextInput::make('address_line2')->label('Address Line 2')->maxLength(255)->nullable(),
                        TextInput::make('city')->label('City')->maxLength(255)->nullable(),
                        TextInput::make('postal_code')->label('Postal Code')->maxLength(255)->nullable(),
                        TextInput::make('phone_number')->label('Phone Number')->maxLength(255)->nullable()->tel(),
                    ]),
                Section::make('Firm Settings')
                    ->columns(2)
                    ->schema([
                        TextInput::make('default_language')->label('Default Language')->maxLength(255)->nullable()
                            ->helperText('e.g. en.'),
                        TextInput::make('state_jurisdiction')->label('State Jurisdiction')->maxLength(255)->nullable(),
                    ]),
                Section::make('Branding')
                    ->description('A small, safe subset of firm_settings.branding_settings_json — no logo upload (no file storage pipeline exists yet).')
                    ->columns(2)
                    ->schema([
                        TextInput::make('branding_display_name_override')
                            ->label('Display Name Override')
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('branding_primary_color')
                            ->label('Primary Brand Color')
                            ->maxLength(7)
                            ->nullable()
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->helperText('Hex color, e.g. #1D4ED8.'),
                    ]),
                Section::make('Platform-Managed')
                    ->description('These are shown for visibility only — they cannot be changed from this page.')
                    ->columns(3)
                    ->schema([
                        Text::make(fn (): string => 'Payment Mode: '.($this->paymentModeDisplay ?? '—')),
                        Text::make(fn (): string => 'Trust/IOLTA Protection: '.($this->trustIoltaProtectionDisplay ? 'Enabled' : 'Disabled')),
                        Text::make(fn (): string => 'AI Mode: '.($this->aiModeDisplay ?? '—')),
                        Text::make('Contact platform support to change any of the three values above.'),
                        Text::make('2FA policy: managed separately.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);
        abort_unless(app(FirmSettingsAccessPolicyService::class)->canManage($firmUser->role), 403);

        $state = $this->form->getState();

        app(TenantContextService::class)->runWithFirmContext(
            (int) $firmUser->firm_id,
            function () use ($state, $firmUser): void {
                $firm = Firm::query()->where('id', $firmUser->firm_id)->firstOrFail();
                $firm->update([
                    'legal_name' => $state['legal_name'] ?? null,
                    'primary_country' => $this->normalizedUpper($state['primary_country'] ?? null),
                    'primary_state' => $state['primary_state'] ?? null,
                    'default_timezone' => $state['default_timezone'] ?? null,
                    'default_currency' => $this->normalizedUpper($state['default_currency'] ?? null),
                    'address_line1' => $state['address_line1'] ?? null,
                    'address_line2' => $state['address_line2'] ?? null,
                    'city' => $state['city'] ?? null,
                    'postal_code' => $state['postal_code'] ?? null,
                    'phone_number' => $state['phone_number'] ?? null,
                ]);

                $settings = FirmSettings::query()->where('firm_id', $firmUser->firm_id)->first();

                if ($settings === null) {
                    return;
                }

                $branding = $settings->branding_settings_json ?? [];
                $branding['display_name_override'] = $state['branding_display_name_override'] ?? null;
                $branding['primary_color'] = $state['branding_primary_color'] ?? null;

                $settings->update([
                    'default_language' => $state['default_language'] ?? null,
                    'state_jurisdiction' => $state['state_jurisdiction'] ?? null,
                    'branding_settings_json' => $branding,
                ]);
            },
        );

        Notification::make()->title('Firm settings saved')->success()->send();
    }

    private static function canManageSettings(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(FirmSettingsAccessPolicyService::class)->canManage($firmUser->role);
    }

    private function normalizedUpper(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::upper($value);
    }
}
