<?php

declare(strict_types=1);

namespace App\Filament\Firm\Pages;

use App\Marketplace\Enums\ClaimState;
use App\Marketplace\Models\DirectoryClaim;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Services\MarketplaceClaimAccessPolicyService;
use App\Marketplace\Services\MarketplaceClaimService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
 * MyAttorneyClaimPage — Mission 2 (MyAttorney Marketplace Core),
 * checkpoint 6. The claim-initiation entry point reached from a
 * "Claim This Listing" link on a public /firms/{slug} profile
 * (myattorney.firmsvault.com), which sends the visitor here — the
 * authenticated Firm app (app.firmsvault.com) — never asks them to
 * authenticate anywhere on the public marketplace host itself (section
 * 60/63: claiming happens from the existing Firm identity, no second
 * login surface, no MyAttorney-issued session cookie).
 *
 * Deliberately minimal: shows the target listing, the acting firm's
 * own claim history on it, and a single "Submit Claim" action. Richer
 * self-service (editing the claimed profile's own fields once
 * approved) is checkpoint 10's scope, not this page's. Reviewing/
 * approving/rejecting/revoking a claim is checkpoint 11's Admin
 * Control Center scope, not this page's — a FirmOwner here can only
 * ever act on their OWN firm's claims (canManageClaims() + ownsClaim()
 * — never inferred from a query-string value, section 59).
 *
 * Modeled on FirmSettingsPage's InteractsWithSchemas + snapshot-not-
 * hydrated-model shape. Not registered in navigation
 * (shouldRegisterNavigation() = false) — reached only via the direct
 * link from a public profile's query string, not browsed to.
 */
class MyAttorneyClaimPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $title = 'Claim Your MyAttorney Listing';

    protected static ?string $slug = 'myattorney-claim';

    public ?array $data = [];

    public ?string $targetSlug = null;

    public ?string $targetDisplayName = null;

    public ?string $targetCity = null;

    public bool $targetAlreadyClaimed = false;

    public bool $targetNotFound = false;

    /** @var array<int, array{state: string, submitted_at: ?string}> */
    public array $ownClaimsOnTarget = [];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->activeFirmUser() !== null;
    }

    public function mount(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();
        abort_unless($firmUser !== null, 403);

        $this->targetSlug = request()->query('firm');
        $directoryFirm = $this->targetSlug !== null
            ? DirectoryFirm::query()->where('slug', $this->targetSlug)->first()
            : null;

        if ($directoryFirm === null) {
            $this->targetNotFound = true;

            return;
        }

        $this->targetDisplayName = $directoryFirm->display_name;
        $this->targetCity = $directoryFirm->offices()->first()?->city;
        $this->targetAlreadyClaimed = $directoryFirm->is_claimed;

        $this->ownClaimsOnTarget = DirectoryClaim::query()
            ->where('directory_firm_id', $directoryFirm->id)
            ->where('firm_id', $firmUser->firm_id)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn (DirectoryClaim $claim) => [
                'state' => $claim->state->value,
                'submitted_at' => $claim->submitted_at?->toDateTimeString(),
            ])
            ->all();

        $this->form->fill(['claim_basis' => '']);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            SchemaActions::make([
                Action::make('submitClaim')
                    ->label('Submit Claim')
                    ->action('submitClaim')
                    ->visible(fn (): bool => static::canManageClaims() && ! $this->targetNotFound && ! $this->targetAlreadyClaimed && ! $this->hasActiveOwnClaim()),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Listing')
                    ->schema([
                        Text::make(fn (): string => $this->targetNotFound
                            ? 'No MyAttorney listing was found for this link.'
                            : "Claiming: {$this->targetDisplayName}".($this->targetCity !== null ? " ({$this->targetCity})" : '')),
                        Text::make(fn (): string => $this->targetAlreadyClaimed ? 'This listing has already been claimed.' : '')
                            ->visible(fn (): bool => $this->targetAlreadyClaimed),
                    ]),
                Section::make('Claim Basis')
                    ->description('Briefly describe your authority over this firm (e.g. "I am the owner/managing partner").')
                    ->visible(fn (): bool => ! $this->targetNotFound && ! $this->targetAlreadyClaimed && ! $this->hasActiveOwnClaim())
                    ->schema([
                        Textarea::make('claim_basis')->label('Claim Basis')->rows(3)->maxLength(2000)->nullable(),
                    ]),
            ]);
    }

    public function submitClaim(): void
    {
        $firmUser = Auth::user()?->activeFirmUser();
        abort_unless($firmUser !== null, 403);
        abort_unless(static::canManageClaims(), 403);

        if ($this->targetSlug === null) {
            abort(404);
        }

        $directoryFirm = DirectoryFirm::query()->where('slug', $this->targetSlug)->firstOrFail();

        $state = $this->form->getState();

        try {
            app(MarketplaceClaimService::class)->initiate($directoryFirm, $firmUser, $state['claim_basis'] ?? null);
        } catch (\RuntimeException $e) {
            Notification::make()->title('Claim could not be submitted')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Claim submitted for review')->success()->send();
        $this->mount();
    }

    private function hasActiveOwnClaim(): bool
    {
        foreach ($this->ownClaimsOnTarget as $claim) {
            if (ClaimState::from($claim['state'])->isActive()) {
                return true;
            }
        }

        return false;
    }

    private static function canManageClaims(): bool
    {
        $firmUser = Auth::user()?->activeFirmUser();

        return $firmUser !== null && app(MarketplaceClaimAccessPolicyService::class)->canManageClaims($firmUser->role);
    }
}
