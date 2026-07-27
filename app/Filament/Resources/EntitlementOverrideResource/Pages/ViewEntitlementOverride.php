<?php

declare(strict_types=1);

namespace App\Filament\Resources\EntitlementOverrideResource\Pages;

use App\Filament\Resources\EntitlementOverrideResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformEntitlementOverrideDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ViewEntitlementOverride — a custom Resource page mirroring
 * ViewConflict's exact shape (composite firmUuid/id route, TOCTOU-safe
 * fresh read on every render).
 */
class ViewEntitlementOverride extends Page
{
    protected static string $resource = EntitlementOverrideResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Entitlement';

    public string $firmUuid = '';

    public int $id = 0;

    public function mount(string $firmUuid, int $id): void
    {
        $this->firmUuid = $firmUuid;
        $this->id = $id;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $row = app(PlatformEntitlementOverrideDirectoryService::class)->findEntitlement($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
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
            $row = app(PlatformEntitlementOverrideDirectoryService::class)->findEntitlement($admin, $firm, $this->id);
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This entitlement record could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Entitlement')
                ->columns(2)
                ->schema([
                    Text::make('Firm: '.$row['firm_name']),
                    Text::make('Module: '.$row['module_code']),
                    Text::make('Enabled: '.($row['enabled'] ? 'Yes' : 'No')),
                    Text::make('Source: '.Str::headline($row['source'] ?? '—')),
                    Text::make('Precedence: '.($row['precedence'] ?? '—').' (higher wins: admin_override=4 > firm_override=3 > org_inherited=2 > plan=1)'),
                    Text::make('Starts at: '.($row['starts_at']?->toDayDateTimeString() ?? '—')),
                    Text::make('Ends at: '.($row['ends_at']?->toDayDateTimeString() ?? 'No end date')),
                    Text::make('Last updated: '.($row['updated_at']?->toDayDateTimeString() ?? '—')),
                ]),
        ]);
    }
}
