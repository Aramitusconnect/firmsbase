<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmUserResource\Pages;

use App\Filament\Resources\FirmUserResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Services\PlatformFirmUserDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewFirmUser — a custom Resource page, NOT the standard Filament
 * ViewRecord (`{record}` route-model-binding). See FirmUserResource's
 * own docblock for why: a FirmUser row cannot be resolved by its own
 * uuid alone under firm_users' FORCE RLS without the correct firm's
 * context already active, so the route carries both `firmUuid` and
 * `firmUserUuid` — mirroring App\Filament\Pages\
 * PlatformFirmIntegrationDetailPage's established
 * `{firmUuid}/{connectionUuid}` composite-route shape exactly.
 *
 * Scalar-property-only, TOCTOU-consistent with that same precedent: the
 * only public properties are the two route-parameter strings; the
 * actual FirmUser is re-resolved fresh via
 * PlatformFirmUserDirectoryService::findByUuid() inside content(), never
 * cached on $this between renders beyond what mount() needs for its own
 * one-time 403/404 check.
 */
class ViewFirmUser extends Page
{
    protected static string $resource = FirmUserResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Firm User';

    public string $firmUuid = '';

    public string $firmUserUuid = '';

    public function mount(string $firmUuid, string $firmUserUuid): void
    {
        $this->firmUuid = $firmUuid;
        $this->firmUserUuid = $firmUserUuid;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $firmUser = app(PlatformFirmUserDirectoryService::class)->findByUuid($admin, $firm, $this->firmUserUuid);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($firmUser === null) {
            abort(404);
        }
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $firmUser = app(PlatformFirmUserDirectoryService::class)->findByUuid($admin, $firm, $this->firmUserUuid);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($firmUser === null) {
            return $schema->components([
                Text::make('This firm user could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Firm User')
                ->columns(2)
                ->schema([
                    Text::make("Name: {$this->displayName($firmUser)}"),
                    Text::make('Email: '.($firmUser->user?->email ?? '—')),
                    Text::make('Firm: '.$firm->name),
                    Text::make('Role: '.($firmUser->role !== null ? Str::headline($firmUser->role->value) : '—')),
                    Text::make('Status: '.($firmUser->status !== null ? Str::headline($firmUser->status->value) : '—')),
                    Text::make('Seat class: '.Str::headline($firmUser->effectiveSeatClass()->value)),
                    Text::make('Primary: '.($firmUser->is_primary ? 'Yes' : 'No')),
                    Text::make('Invitation accepted: '.($firmUser->invitation_accepted_at?->toDayDateTimeString() ?? '—')),
                    Text::make('Member since: '.$firmUser->created_at?->toDayDateTimeString()),
                ]),
        ]);
    }

    private function displayName(FirmUser $firmUser): string
    {
        return $firmUser->user?->name ?? '—';
    }
}
