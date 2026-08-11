<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Enums\FirmUserRole;
use App\Marketplace\Enums\ConsultationMode;
use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\MarketplaceCapability;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceBadgeService;
use App\Marketplace\Services\MarketplaceCapabilityService;
use App\Marketplace\Services\MarketplaceProfileVersionService;
use App\Models\Language;
use App\Models\PracticeArea;
use App\Services\FirmUserAuditEventRecorder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * MyAttorneyProfilePage — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 10. Section 60: a claimed Firm manages its marketplace
 * profile from the authenticated Firm application — never a second
 * login surface — reusing this panel's own canonical Firm identity/
 * tenant context exactly like FirmSettingsPage.
 *
 * Section 59's own requirement in code form: the managed DirectoryFirm
 * is resolved ONLY via `where('firm_id', $firmUser->firm_id)` — the
 * acting FirmUser's own authenticated tenant context — never from a
 * client-submitted identifier of any kind.
 *
 * Gated on MarketplaceCapabilityService::has(ProfileManagement), not a
 * bare `is_claimed` check (section 67) — VIEW is every active role
 * (matching FirmSettingsPage's own "nothing here is confidential-by-
 * role" reasoning), MANAGE (the save action) is FirmOwner only,
 * matching that same page's firm-wide-configuration precedent.
 *
 * Deliberately excluded from this page, disclosed rather than silently
 * missing:
 *   - publication_state (Draft/Published/Suspended/...) — moderation
 *     stays admin-controlled (checkpoint 11), never a firm self-toggle.
 *   - is_claimed/is_marketplace_member/firm_id/completeness_score/
 *     source_type — platform-managed, never in `$this->data`, so a
 *     forged submission for any of them has no code path to apply
 *     through (same defense-in-depth shape as FirmSettingsPage's own
 *     three platform-managed columns).
 *   - Office add/edit — offices are shown read-only here; full office
 *     CRUD is a disclosed, deferred enhancement, not built this
 *     checkpoint (bounding an already large mission's scope).
 */
class MyAttorneyProfilePage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'MyAttorney Profile';

    protected static string|\UnitEnum|null $navigationGroup = 'Firm Management';

    protected static ?int $navigationSort = 21;

    protected static ?string $title = 'MyAttorney Profile';

    protected static ?string $slug = 'myattorney-profile';

    public ?array $data = [];

    public bool $hasClaimedListing = false;

    public ?string $directorySlug = null;

    public ?string $publicationStateDisplay = null;

    /** @var array<int, string> */
    public array $badgeLabels = [];

    /** @var array<int, array{state: string, submitted_at: ?string}> */
    public array $claimHistory = [];

    /** @var array<int, array{label: string, city: ?string}> */
    public array $officesDisplay = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->activeFirmUser() !== null;
    }

    public function mount(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();
        abort_unless($firmUser !== null, 403);

        $firm = DirectoryFirm::query()
            ->where('firm_id', $firmUser->firm_id)
            ->with(['practiceAreas', 'languages', 'offices'])
            ->first();

        if ($firm === null) {
            $this->hasClaimedListing = false;

            return;
        }

        $this->hasClaimedListing = true;
        $this->directorySlug = $firm->slug;
        $this->publicationStateDisplay = $firm->publication_state->value;
        $this->badgeLabels = array_map(fn ($badge) => $badge->label(), app(MarketplaceBadgeService::class)->badgesFor($firm));
        $this->officesDisplay = $firm->offices->map(fn ($office) => ['label' => $office->label, 'city' => $office->city])->all();

        $this->claimHistory = DirectoryClaim::query()
            ->where('directory_firm_id', $firm->id)
            ->where('firm_id', $firmUser->firm_id)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (DirectoryClaim $claim) => ['state' => $claim->state->value, 'submitted_at' => $claim->submitted_at?->toDateTimeString()])
            ->all();

        $this->form->fill([
            'display_name' => $firm->display_name,
            'phone' => $firm->phone,
            'website' => $firm->website,
            'public_email' => $firm->public_email,
            'description' => $firm->description,
            'consultation_modes' => $firm->consultation_modes ?? [],
            'accepting_inquiries' => $firm->accepting_inquiries,
            'practice_area_ids' => $firm->practiceAreas->pluck('id')->all(),
            'language_ids' => $firm->languages->pluck('id')->all(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('save')
                    ->label('Save Profile')
                    ->action('save')
                    ->visible(fn (): bool => $this->hasClaimedListing && static::canManageProfile()),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        if (! $this->hasClaimedListing) {
            return $schema->components([
                Text::make('You have not claimed a MyAttorney listing yet. Find your firm on MyAttorney and use "Claim This Listing" from its public profile page.'),
            ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Status')
                    ->description('Read-only — these are platform-managed and not editable from this page.')
                    ->columns(2)
                    ->schema([
                        Text::make(fn (): string => 'Publication status: '.($this->publicationStateDisplay ?? '—')),
                        Text::make(fn (): string => 'Badges: '.($this->badgeLabels !== [] ? implode(', ', $this->badgeLabels) : '—')),
                        Text::make(fn (): string => 'Offices on file: '.($this->officesDisplay !== [] ? implode('; ', array_map(fn ($o) => $o['label'].($o['city'] !== null ? " ({$o['city']})" : ''), $this->officesDisplay)) : 'None yet.')),
                    ]),
                Section::make('Listing Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('display_name')->label('Display Name')->required()->maxLength(255),
                        TextInput::make('phone')->label('Phone')->tel()->maxLength(255)->nullable(),
                        TextInput::make('website')->label('Website')->url()->maxLength(255)->nullable(),
                        TextInput::make('public_email')->label('Public Email')->email()->maxLength(255)->nullable(),
                        Toggle::make('accepting_inquiries')->label('Accepting new inquiries'),
                    ]),
                Section::make('Description')
                    ->schema([
                        Textarea::make('description')->label('Firm Description')->rows(4)->maxLength(2000)->nullable(),
                    ]),
                Section::make('Consultation Modes')
                    ->schema([
                        CheckboxList::make('consultation_modes')
                            ->label('Consultation Modes Offered')
                            ->options(array_combine(
                                array_map(fn (ConsultationMode $m) => $m->value, ConsultationMode::cases()),
                                ['In Person', 'Phone', 'Video'],
                            )),
                    ]),
                Section::make('Practice Areas & Languages')
                    ->columns(2)
                    ->schema([
                        Select::make('practice_area_ids')
                            ->label('Practice Areas')
                            ->multiple()
                            ->options(fn () => PracticeArea::query()->where('is_marketplace_visible', true)->orderBy('sort_order')->pluck('name', 'id')->all()),
                        Select::make('language_ids')
                            ->label('Languages')
                            ->multiple()
                            ->options(fn () => Language::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                    ]),
            ]);
    }

    public function save(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();

        abort_unless($firmUser !== null, 403);
        abort_unless(static::canManageProfile(), 403);

        $firm = DirectoryFirm::query()->where('firm_id', $firmUser->firm_id)->firstOrFail();

        $state = $this->form->getState();

        $changes = [];
        foreach (['display_name', 'phone', 'website', 'public_email', 'description', 'accepting_inquiries'] as $field) {
            if (($state[$field] ?? null) !== $firm->{$field}) {
                $changes[$field] = $state[$field] ?? null;
            }
        }
        if (($state['consultation_modes'] ?? []) !== ($firm->consultation_modes ?? [])) {
            $changes['consultation_modes'] = $state['consultation_modes'] ?? [];
        }

        $firm->update([
            'display_name' => $state['display_name'],
            'phone' => $state['phone'] ?? null,
            'website' => $state['website'] ?? null,
            'public_email' => $state['public_email'] ?? null,
            'description' => $state['description'] ?? null,
            'consultation_modes' => $state['consultation_modes'] ?? [],
            'accepting_inquiries' => (bool) ($state['accepting_inquiries'] ?? false),
            'last_confirmed_by_firm_at' => now(),
        ]);

        $firm->practiceAreas()->sync(collect($state['practice_area_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['source_type' => 'firm_submitted']])->all());
        $firm->languages()->sync(collect($state['language_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['source_type' => 'firm_submitted']])->all());

        if ($changes !== []) {
            app(MarketplaceProfileVersionService::class)->record($firm->fresh(), $changes, 'firm_user', $firmUser->user_id, DataProvenanceSourceType::FirmSubmitted);
        }

        app(FirmUserAuditEventRecorder::class)->record(
            $firmUser->firm,
            $firmUser->user,
            'marketplace_profile_updated',
            'marketplace_profile',
            ['directory_firm_id' => $firm->id, 'changed_fields' => array_keys($changes)],
        );

        Notification::make()->title('Profile saved')->success()->send();
        $this->mount();
    }

    private static function canManageProfile(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();
        if ($firmUser === null || $firmUser->role !== FirmUserRole::FirmOwner) {
            return false;
        }

        $firm = DirectoryFirm::query()->where('firm_id', $firmUser->firm_id)->first();

        return $firm !== null && app(MarketplaceCapabilityService::class)->has($firm, MarketplaceCapability::ProfileManagement);
    }
}
