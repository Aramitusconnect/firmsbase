<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\AuditLogResource;
use App\Models\PlatformAdmin;
use App\Models\PlatformRole;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PlatformRoleDetailPage — CORE SuperAdmin mission, section 35: a real
 * drill-down/inspection experience for a single fixed role, distinct
 * from PlatformRolesAndPermissionsPage's own catalog-of-all-roles view.
 * Composite-route-free (a single {roleCode} segment, PlatformRoleCode's
 * own enum value — never a database ID, since roles are not database
 * rows), mirroring the same "custom Page, not a Resource" judgment call
 * PlatformRolesAndPermissionsPage's own docblock already explains.
 *
 * Same gate as the catalog page (canManageRoles(), SuperAdmin-only) —
 * see that page's own docblock for why a broader-audience split was
 * deliberately not introduced.
 *
 * Deliberately NOT registered in navigation ($shouldRegisterNavigation
 * returns false) — reached only via the catalog page's own "View
 * details" link, exactly like PlatformFirmIntegrationDetailPage is
 * reached only from its own list page, never a second top-level nav
 * entry for what is fundamentally a drill-down of an existing page.
 */
class PlatformRoleDetailPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $slug = 'roles-and-permissions/{roleCode}';

    public string $roleCode = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canManageRoles($admin)->allowed;
    }

    public function mount(string $roleCode): void
    {
        $this->roleCode = $roleCode;

        if (PlatformRoleCode::tryFrom($roleCode) === null) {
            throw new HttpException(404, 'Unknown role.');
        }
    }

    public function getTitle(): string
    {
        $role = PlatformRoleCode::tryFrom($this->roleCode);

        return $role !== null ? 'Role: '.Str::headline($role->value) : 'Role';
    }

    public function content(Schema $schema): Schema
    {
        $role = PlatformRoleCode::tryFrom($this->roleCode);

        if ($role === null) {
            return $schema->components([
                Text::make('Unknown role.')->color('danger'),
            ]);
        }

        $holders = $this->currentHolders($role);
        $recentGrants = $this->recentGrants($role);
        $recentRevocations = $this->recentRevocations($role);
        $lastAssignment = $recentGrants->first();

        return $schema->components([
            Section::make('Role')
                ->columns(2)
                ->schema([
                    Text::make('Role: '.Str::headline($role->value)),
                    Text::make('Risk classification: '.PlatformRolesAndPermissionsPage::riskClassificationFor($role))
                        ->color(match (PlatformRolesAndPermissionsPage::riskClassificationFor($role)) {
                            'High' => 'danger',
                            'Medium' => 'warning',
                            default => 'gray',
                        }),
                    Text::make('Active admins holding this role: '.$holders->count()),
                    Text::make('Last assignment: '.($lastAssignment?->granted_at?->toDayDateTimeString() ?? 'Never')),
                ]),
            Section::make('Effective Capabilities')
                ->description('What this role grants, per PlatformStaffAccessPolicyService — read live via reflection, never a hand-copied description.')
                ->schema([
                    UnorderedList::make(
                        empty(PlatformRolesAndPermissionsPage::capabilitiesForRole($role))
                            ? [Text::make('No access grants defined for this role.')->color('gray')]
                            : collect(PlatformRolesAndPermissionsPage::capabilitiesForRole($role))->map(fn (string $c) => Text::make($c))->all()
                    ),
                ]),
            Section::make('Platform Administrators Holding This Role')
                ->schema([
                    UnorderedList::make(
                        $holders->isEmpty()
                            ? [Text::make('No active administrator currently holds this role.')->color('gray')]
                            : $holders->map(fn (array $row) => Text::make("{$row['name']} ({$row['email']})"))->all()
                    ),
                ]),
            Section::make('Recent Grants')
                ->schema([
                    UnorderedList::make(
                        $recentGrants->isEmpty()
                            ? [Text::make('No grants recorded yet.')->color('gray')]
                            : $recentGrants->map(fn (PlatformRole $grant) => Text::make(sprintf(
                                '%s — granted %s%s',
                                $grant->platformAdmin?->name ?? "admin #{$grant->platform_admin_id}",
                                $grant->granted_at?->toDayDateTimeString() ?? '—',
                                $grant->grantedBy?->name !== null ? " (by {$grant->grantedBy->name})" : '',
                            )))->all()
                    ),
                ])
                ->collapsible(),
            Section::make('Recent Revocations')
                ->schema([
                    UnorderedList::make(
                        $recentRevocations->isEmpty()
                            ? [Text::make('No revocations recorded yet.')->color('gray')]
                            : $recentRevocations->map(fn (PlatformRole $grant) => Text::make(sprintf(
                                '%s — revoked %s',
                                $grant->platformAdmin?->name ?? "admin #{$grant->platform_admin_id}",
                                $grant->revoked_at?->toDayDateTimeString() ?? '—',
                            )))->all()
                    ),
                ])
                ->collapsible(),
            Section::make('Audit History')
                ->schema([
                    Text::make(fn (): string => 'Full platform audit log (not filtered to this role — no role-specific filter exists on that log today): '.AuditLogResource::getUrl()),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    /**
     * @return Collection<int, array{name: string, email: string}>
     */
    private function currentHolders(PlatformRoleCode $role): Collection
    {
        return PlatformRole::query()
            ->with('platformAdmin:id,name,email,is_active')
            ->where('role_code', $role->value)
            ->whereNull('revoked_at')
            ->get()
            ->filter(fn (PlatformRole $grant) => $grant->platformAdmin !== null && $grant->platformAdmin->is_active)
            ->map(fn (PlatformRole $grant): array => ['name' => $grant->platformAdmin->name, 'email' => $grant->platformAdmin->email])
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, PlatformRole>
     */
    private function recentGrants(PlatformRoleCode $role, int $limit = 10): Collection
    {
        return PlatformRole::query()
            ->with(['platformAdmin:id,name,email', 'grantedBy:id,name,email'])
            ->where('role_code', $role->value)
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PlatformRole>
     */
    private function recentRevocations(PlatformRoleCode $role, int $limit = 10): Collection
    {
        return PlatformRole::query()
            ->with('platformAdmin:id,name,email')
            ->where('role_code', $role->value)
            ->whereNotNull('revoked_at')
            ->orderByDesc('revoked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
